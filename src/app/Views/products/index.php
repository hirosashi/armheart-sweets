<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\View;
$title = '商品と配合';
?>
<h1 class="page-title">商品と配合</h1>
<p class="page-lead">商品ごとに「どの部位を、1台あたり何g使うか」を登録します。部位の中身（配合）は「部位の登録」で決めます。</p>

<?php if (Auth::can('recipe')): ?>
  <p><a class="btn" href="<?= View::e(App::url('/products/edit')) ?>">＋ 商品を新しく登録する</a></p>
<?php endif; ?>

<table class="table">
  <thead>
    <tr>
      <th>商品名</th><th>販売者</th><th>規格</th><th>使う部位</th><th>直接使う材料</th><th>導入予定</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php if ($products === []): ?>
    <tr><td colspan="7" class="text-center">まだ商品が登録されていません。</td></tr>
  <?php endif; ?>
  <?php foreach ($products as $p): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/products/show?id=' . $p['id'])) ?>"><?= View::e($p['name']) ?></a></td>
      <td><?= View::e($p['seller_name']) ?></td>
      <td><?= View::e($p['spec']) ?></td>
      <td class="num"><?= (int)$p['part_count'] ?> 件</td>
      <td class="num"><?= (int)$p['material_count'] ?> 件</td>
      <td><?= View::e(View::d($p['launch_date'])) ?></td>
      <td><a class="btn btn-plain" href="<?= View::e(App::url('/products/show?id=' . $p['id'])) ?>">配合を見る</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
