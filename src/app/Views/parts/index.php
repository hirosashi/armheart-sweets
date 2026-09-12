<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
$title = '部位の登録';
$editable = Auth::can('parts');
?>
<h1 class="page-title">部位（パーツ）の登録</h1>
<p class="page-lead">スポンジ・ムース・グラサージュなど、部位ごとの配合を「1回の仕込み（1バッチ）」の量で登録します。</p>

<table class="table">
  <thead>
    <tr><th>部位名</th><th class="num">1バッチの合計量</th><th class="num">材料の数</th><th class="num">歩留まり</th>
        <th>バッチの丸め</th><th class="num">使っている商品</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($parts as $p): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/parts/show?id=' . $p['id'])) ?>"><?= View::e($p['name']) ?></a>
          <?php if ((int)$p['is_shared'] === 1): ?><span class="badge">共通</span><?php endif; ?></td>
      <td class="num"><?= View::e(View::num($p['batch_total_qty'], 1)) ?><?= View::e($p['unit']) ?></td>
      <td class="num"><?= (int)$p['material_count'] ?></td>
      <td class="num"><?= View::e(View::num($p['yield_rate'], 3)) ?></td>
      <td><?= (int)$p['round_batch'] === 1 ? '切り上げ' : 'そのまま' ?></td>
      <td class="num"><?= (int)$p['product_count'] ?></td>
      <td><a class="btn btn-plain" href="<?= View::e(App::url('/parts/show?id=' . $p['id'])) ?>">配合を見る</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($parts === []): ?>
    <tr><td colspan="7" class="text-center">まだ部位が登録されていません。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if ($editable): ?>
<form method="post" action="<?= View::e(App::url('/parts/save')) ?>" class="box">
  <?= Csrf::field() ?>
  <strong>部位を新しく登録する</strong>
  部位名 <input type="text" name="name" class="w-200" required>
  歩留まり <input type="number" step="0.001" name="yield_rate" value="0.900" class="w-80">
  <label class="inline"><input type="checkbox" name="round_batch" checked> バッチ数を切り上げる</label>
  <label class="inline"><input type="checkbox" name="is_shared"> 複数商品で使い回す</label>
  <button class="btn">登録する</button>
</form>
<?php endif; ?>
