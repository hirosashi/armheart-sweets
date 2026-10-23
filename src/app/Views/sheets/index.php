<?php
use App\Core\App;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
$title = '原材料発注一覧';
?>
<div class="easy-band">かんたん画面（Excelと同じ並び）　ここで入れた内容は、材料・配合・発注・在庫の画面にもそのまま出ます。</div>
<h1 class="page-title">原材料発注一覧</h1>
<p class="page-lead">商品と製造数ごとに1枚ずつ作ります。開くとExcelの「原材料発注一覧」と同じ並びで入力できます。</p>

<table class="table">
  <thead><tr><th>商品</th><th class="num">製造数</th><th class="num">材料の発注</th><th>メモ</th><th>更新</th></tr></thead>
  <tbody>
  <?php foreach ($sheets as $s): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/sheets/show?id=' . (int)$s['id'])) ?>"><?= View::e($s['product_name']) ?></a></td>
      <td class="num"><?= View::e(View::num($s['qty'])) ?></td>
      <td class="num"><?= (int)$s['done_count'] ?>／<?= (int)$s['order_count'] ?> 件 済</td>
      <td><?= View::e($s['note']) ?></td>
      <td><?= View::e(Clock::dt($s['updated_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($sheets === []): ?>
    <tr><td colspan="5" class="note">まだありません。下から作ってください。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if ($canEdit): ?>
<h2 class="sec-title">新しく作る</h2>
<form method="post" action="<?= View::e(App::url('/sheets/create')) ?>" class="inline-form">
  <?= Csrf::field() ?>
  商品
  <select name="product_id" class="select-search w-300" required>
    <option value="">-- 商品を選ぶ --</option>
    <?php foreach ($products as $p): ?>
      <option value="<?= (int)$p['id'] ?>"><?= View::e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
  製造数 <input type="number" name="qty" min="0" step="1" class="w-100" required>
  <button class="btn">作る</button>
</form>
<p class="note">商品がまだ無いときは「登録・確認」→「商品と配合」で商品名だけ登録してください。部位と原材料は、作ったあとの画面で追加できます。</p>
<?php endif; ?>
