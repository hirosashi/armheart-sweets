<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
$title = 'Excelで取り込み・書き出し';
?>
<h1 class="page-title">Excelで取り込み・書き出し</h1>
<p class="page-lead">材料・仕入先・部位・商品と、その配合をExcelでまとめて登録・確認できます。</p>

<h2 class="sec-title">今の登録数</h2>
<table class="table table-narrow">
  <thead><tr><th>シート</th><th class="num">件数</th></tr></thead>
  <tbody>
  <?php foreach ($counts as $sheet => $n): ?>
    <tr><td><?= View::e($sheet) ?></td><td class="num"><?= (int)$n ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 class="sec-title">1. Excelに書き出す</h2>
<p>今の登録内容を1つのExcelファイル（シート：<?= View::e(implode('・', $sheets)) ?>）にします。何も登録していないときは、見出しだけのひな形になります。</p>
<p><a class="btn btn-primary" href="<?= View::e(App::url('/master-io/export')) ?>">Excelに書き出す</a></p>

<h2 class="sec-title">2. Excelから取り込む</h2>
<?php if ($canEdit): ?>
  <ul class="note">
    <li>書き出したExcelを直して取り込みます。1行目の見出しは変えないでください（列の並びは変えてもかまいません）。</li>
    <li>名前（コードを入れた場合はコード）が同じ行は上書きし、ない行は新しく追加します。Excelにない行は消えません。</li>
    <li>「部位の配合」「商品の構成」「商品の配合」は、シートに出てくる部位・商品ごとに、シートの内容に置きかえます。</li>
    <li>まちがいが1か所でもあると何も登録せず、まちがいの一覧を出します。</li>
  </ul>
  <form method="post" action="<?= View::e(App::url('/master-io/import')) ?>" enctype="multipart/form-data" class="box">
    <?= Csrf::field() ?>
    <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    <button class="btn btn-primary">取り込む</button>
    <span class="note">.xlsx・5MBまで</span>
  </form>
<?php else: ?>
  <p class="note">取り込みは管理者だけができます。</p>
<?php endif; ?>

<?php if ($result !== null): ?>
  <h2 id="result" class="sec-title">取り込みの結果（<?= View::e($result['file'] ?? '') ?>）</h2>
  <?php if ($result['errors'] !== []): ?>
    <p class="judge-short">まちがい <?= count($result['errors']) ?> 件<?= count($result['errors']) >= \App\Services\MasterIo::MAX_ERRORS ? '（多いため途中まで）' : '' ?>。何も登録していません。</p>
    <table class="table">
      <thead><tr><th>シート</th><th class="num">行</th><th>内容</th></tr></thead>
      <tbody>
      <?php foreach ($result['errors'] as $e): ?>
        <tr><td><?= View::e($e['sheet']) ?></td><td class="num"><?= $e['row'] > 0 ? (int)$e['row'] : '' ?></td><td><?= View::e($e['message']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table table-narrow">
      <thead><tr><th>シート</th><th class="num">追加</th><th class="num">上書き</th></tr></thead>
      <tbody>
      <?php foreach ($result['summary'] as $sheet => $s): ?>
        <tr><td><?= View::e($sheet) ?></td><td class="num"><?= (int)$s['added'] ?></td><td class="num"><?= (int)$s['updated'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">配合・構成のシートは、置きかえた部位・商品の数を「上書き」、登録した行の数を「追加」に出しています。</p>
  <?php endif; ?>
<?php endif; ?>
