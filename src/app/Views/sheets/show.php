<?php
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
use App\Services\OrderSheet;
$title = '原材料発注一覧 ' . $sheet['product_name'];
$seen = [];
$n = static fn($v, int $d = 0) => View::e(View::num($v, $d));
/** 入力欄の値（type="number" はカンマ付きだと空になるため、区切りなしで出す） */
$raw = static fn($v, int $d = 3): string => ($v === null || $v === '') ? ''
    : View::e(rtrim(rtrim(number_format((float)$v, $d, '.', ''), '0'), '.'));

/** 発注の入力欄（今ある発注は order[id]、新しい行は new_order[部位-材料][連番]） */
$orderCells = static function (?array $o, string $key, int $idx) use ($raw): string {
    $name = $o ? 'order[' . (int)$o['order_id'] . ']' : 'new_order[' . $key . '][' . $idx . ']';
    $h  = '<td class="in num"><input type="number" step="any" min="0" name="' . View::e($name) . '[qty]" value="' . ($o ? $raw($o['qty']) : '') . '" class="w-80"></td>';
    return $h;
};
$dateCells = static function (?array $o, string $key, int $idx): string {
    $name = $o ? 'order[' . (int)$o['order_id'] . ']' : 'new_order[' . $key . '][' . $idx . ']';
    $done = $o && $o['status'] === 'delivered';
    $link = $o ? ' <a class="small" href="' . View::e(App::url('/orders/show?id=' . (int)$o['order_id'])) . '" title="発注の管理で開く">' . View::e($o['order_no']) . '</a>' : '';
    return '<td class="in"><input type="date" name="' . View::e($name) . '[order_date]" value="' . View::e($o['order_date'] ?? '') . '"></td>'
         . '<td class="in"><input type="date" name="' . View::e($name) . '[desired_date]" value="' . View::e($o['desired_date'] ?? '') . '"></td>'
         . '<td class="in text-center' . ($done ? ' done' : '') . '"><label><input type="checkbox" name="' . View::e($name) . '[done]" value="1"' . ($done ? ' checked' : '') . '> 済</label>' . $link . '</td>';
};
$diffCell = static function (array $r): string {
    if ($r['need'] === null) {
        return '<td class="auto">—</td>';
    }
    $d = $r['diff'];
    return $d >= -0.0001
        ? '<td class="auto num judge-ok">足りる</td>'
        : '<td class="auto num judge-short">あと ' . View::e(View::num(-$d, 2)) . ' ' . View::e($r['purchase_unit']) . '</td>';
};
?>
<div class="easy-band">かんたん画面（Excelと同じ並び）　黄色の欄に入力して「保存」を押すと、材料・配合・発注・在庫の画面にも反映されます。
  <a href="<?= View::e(App::url('/products/show?id=' . (int)$sheet['product_id'])) ?>">今までの画面（商品と配合）へ</a></div>

<form method="post" action="<?= View::e(App::url('/sheets/save')) ?>" id="sheet-form">
<?= Csrf::field() ?>
<input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
<fieldset class="plain" <?= $canEdit ? '' : 'disabled' ?>>

<div class="sheet-top">
  <h1 class="page-title">原材料発注一覧　<?= View::e($sheet['product_name']) ?></h1>
  <label>製造数 <input type="number" name="qty" min="0" step="1" value="<?= (int)$sheet['qty'] ?>" class="w-100 in"></label>
  <label>メモ <input type="text" name="note" value="<?= View::e($sheet['note']) ?>" class="w-300 in" maxlength="255"></label>
  <a class="btn btn-plain" href="<?= View::e(App::url('/sheets/export?id=' . (int)$sheet['id'])) ?>">Excelで書き出す</a>
  <button class="btn btn-primary">保存</button>
  <a class="small" href="<?= View::e(App::url('/sheets')) ?>">← 一覧へ</a>
</div>

<datalist id="dl-materials"><?php foreach ($materials as $m): ?><option value="<?= View::e($m['name']) ?>"><?php endforeach; ?></datalist>
<datalist id="dl-parts"><?php foreach ($partNames as $p): ?><option value="<?= View::e($p['name']) ?>"><?php endforeach; ?></datalist>
<datalist id="dl-vendors"><?php foreach ($vendors as $vd): ?><option value="<?= View::e($vd['v']) ?>"><?php endforeach; ?></datalist>

<div class="sheet-wrap">
<table class="table sheet">
  <thead><tr>
    <th>部位名</th><th>発注先</th><th>原材料名</th><th>１B配合量</th><th>使用数</th><th>発注単位</th><th>必要数</th>
    <th>発注数</th><th>単位</th><th>発注日</th><th>納品日</th><th>状態</th><th>保管配送</th><th>在庫</th><th>過不足</th><th>外す</th>
  </tr></thead>
  <tbody>
  <?php foreach ($parts as $part): $pid = (int)$part['part_id']; $firstRow = true; ?>
    <?php foreach ($part['rows'] as $r):
        $mid = (int)$r['material_id']; $key = $pid . '-' . $mid;
        $lines = $r['orders'] ?: [null]; $own = !isset($seen[$mid]); $seen[$mid] = true; ?>
      <?php foreach ($lines as $k => $o): ?>
      <tr class="<?= $k === 0 ? 'row-main' : 'row-split' ?>" data-key="<?= View::e($key) ?>">
        <td class="part-name"><?php if ($firstRow): ?><?= View::e($part['name']) ?><br><span class="small"><?= (int)$part['batches'] ?>B</span><?php endif; $firstRow = false; ?></td>
        <?php if ($k === 0): ?>
          <?php if ($own): ?>
            <td class="in"><input type="text" name="mat[<?= $mid ?>][vendor]" value="<?= View::e($r['vendor_name']) ?>" list="dl-vendors" class="w-100"></td>
          <?php else: ?><td class="auto"><?= View::e($r['vendor_name']) ?></td><?php endif; ?>
          <td><a href="<?= View::e(App::url('/materials/edit?id=' . $mid)) ?>"><?= View::e($r['name']) ?></a>
            <button type="button" class="btn btn-plain btn-small js-split" data-key="<?= View::e($key) ?>">＋分けて発注</button></td>
          <td class="in num"><input type="number" step="any" min="0" name="pm[<?= $pid ?>][<?= $mid ?>][qty]" value="<?= $raw($r['batch_qty'], 3) ?>" class="w-80"></td>
          <td class="auto num"><?= $n($r['use_qty'], 1) ?> <?= View::e($r['unit']) ?></td>
          <?php if ($own): ?>
            <td class="in num"><input type="number" step="any" min="0" name="mat[<?= $mid ?>][per]" value="<?= $raw($r['purchase_qty'], 3) ?>" class="w-80"></td>
          <?php else: ?><td class="auto num"><?= $n($r['purchase_qty'], 3) ?></td><?php endif; ?>
          <td class="auto num"><?= $r['need'] === null ? '—' : $n($r['need'], 2) ?></td>
        <?php else: ?>
          <td></td><td class="small">（分けて発注）</td><td></td><td></td><td></td><td></td>
        <?php endif; ?>
        <?= $orderCells($o, $key, 0) ?>
        <?php if ($k === 0 && $own): ?>
          <td class="in"><input type="text" name="mat[<?= $mid ?>][unit]" value="<?= View::e($r['purchase_unit']) ?>" class="w-50"></td>
        <?php else: ?><td class="auto"><?= View::e($r['purchase_unit']) ?></td><?php endif; ?>
        <?= $dateCells($o, $key, 0) ?>
        <?php if ($k === 0): ?>
          <?php if ($own): ?>
          <td class="in"><select name="mat[<?= $mid ?>][storage]"><option value=""></option>
            <?php foreach (OrderSheet::STORAGES as $st): ?><option <?= $r['storage'] === $st ? 'selected' : '' ?>><?= View::e($st) ?></option><?php endforeach; ?></select></td>
          <?php else: ?><td class="auto"><?= View::e($r['storage']) ?></td><?php endif; ?>
          <td class="auto num"><a href="<?= View::e(App::url('/stock/show?id=' . $mid)) ?>" title="在庫を直すときはここから"><?= $n($r['stock_qty'], 1) ?> <?= View::e($r['unit']) ?></a></td>
          <?= $diffCell($r) ?>
          <td class="text-center"><input type="checkbox" name="pm[<?= $pid ?>][<?= $mid ?>][remove]" value="1" title="この部位の配合から外す"></td>
        <?php else: ?>
          <td></td><td></td><td></td><td></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <tr class="row-add">
      <td class="part-name"><?php if ($firstRow): ?><?= View::e($part['name']) ?><?php endif; ?></td>
      <td></td>
      <td class="in"><input type="text" name="new_pm[<?= $pid ?>][name]" list="dl-materials" placeholder="＋原材料を追加" class="w-200"></td>
      <td class="in num"><input type="number" step="any" min="0" name="new_pm[<?= $pid ?>][qty]" class="w-80" placeholder="配合量"></td>
      <td colspan="12" class="small">名前が同じ材料があればそれを使い、無ければ新しく登録します。</td>
    </tr>
  <?php endforeach; ?>
  <?php if ($parts === []): ?>
    <tr><td colspan="16" class="note">部位がまだありません。いちばん下の「製造数・バッチ数」で部位を追加してください。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<h2 class="sec-title">資材関連</h2>
<div class="sheet-wrap">
<table class="table sheet">
  <thead><tr><th>発注先</th><th>資材名</th><th>使用用途</th><th>使用数</th><th>発注数</th><th>発注単位</th><th>発注日</th><th>納品日</th><th>状態</th><th>在庫</th><th>外す</th></tr></thead>
  <tbody>
  <?php foreach ($packs as $r):
      $mid = (int)$r['material_id']; $key = '0-' . $mid; $lines = $r['orders'] ?: [null];
      $own = !isset($seen[$mid]); $seen[$mid] = true; ?>
    <?php foreach ($lines as $k => $o): ?>
    <tr class="<?= $k === 0 ? 'row-main' : 'row-split' ?>" data-key="<?= View::e($key) ?>">
      <?php if ($k === 0): ?>
        <?php if ($own): ?><td class="in"><input type="text" name="mat[<?= $mid ?>][vendor]" value="<?= View::e($r['vendor_name']) ?>" list="dl-vendors" class="w-100"></td>
        <?php else: ?><td class="auto"><?= View::e($r['vendor_name']) ?></td><?php endif; ?>
        <td><a href="<?= View::e(App::url('/materials/edit?id=' . $mid)) ?>"><?= View::e($r['name']) ?></a>
          <button type="button" class="btn btn-plain btn-small js-split" data-key="<?= View::e($key) ?>">＋分けて発注</button></td>
        <td class="in"><input type="text" name="pack[<?= (int)$r['sp_id'] ?>][purpose]" value="<?= View::e($r['purpose']) ?>" class="w-200"></td>
        <td class="in num"><input type="number" step="any" min="0" name="pack[<?= (int)$r['sp_id'] ?>][use_qty]" value="<?= $raw($r['use_qty'], 3) ?>" class="w-80"></td>
      <?php else: ?>
        <td></td><td class="small">（分けて発注）</td><td></td><td></td>
      <?php endif; ?>
      <?= $orderCells($o, $key, 0) ?>
      <?php if ($k === 0 && $own): ?><td class="in"><input type="text" name="mat[<?= $mid ?>][unit]" value="<?= View::e($r['purchase_unit']) ?>" class="w-100"></td>
      <?php else: ?><td class="auto"><?= View::e($r['purchase_unit']) ?></td><?php endif; ?>
      <?= $dateCells($o, $key, 0) ?>
      <?php if ($k === 0): ?>
        <td class="auto num"><a href="<?= View::e(App::url('/stock/show?id=' . $mid)) ?>"><?= $n($r['stock_qty'], 1) ?> <?= View::e($r['unit']) ?></a></td>
        <td class="text-center"><input type="checkbox" name="pack[<?= (int)$r['sp_id'] ?>][remove]" value="1" title="この一覧から外す"></td>
      <?php else: ?><td></td><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
    <tr class="row-add">
      <td></td>
      <td class="in"><input type="text" name="new_pack[name]" list="dl-materials" placeholder="＋資材を追加" class="w-200"></td>
      <td class="in"><input type="text" name="new_pack[purpose]" class="w-200" placeholder="使用用途"></td>
      <td class="in num"><input type="number" step="any" min="0" name="new_pack[use_qty]" class="w-80" placeholder="使用数"></td>
      <td colspan="7"></td>
    </tr>
  </tbody>
</table>
</div>

<h2 class="sec-title">製造数・バッチ数</h2>
<table class="table table-narrow sheet">
  <thead><tr><th>部位名</th><th>1Bでできる数</th><th>B数（製造数÷1Bでできる数、切り上げ）</th><th>外す</th></tr></thead>
  <tbody>
  <?php foreach ($parts as $part): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/parts/show?id=' . (int)$part['part_id'])) ?>"><?= View::e($part['name']) ?></a></td>
      <td class="in num"><input type="number" step="any" min="0" name="pieces[<?= (int)$part['pp_id'] ?>]" value="<?= $raw($part['pieces'], 1) ?>" class="w-80">
        <?php if ($part['pieces_guess']): ?><span class="small">（今の構成からの目安）</span><?php endif; ?></td>
      <td class="auto num"><?= (int)$part['batches'] ?> B</td>
      <td class="text-center"><input type="checkbox" name="remove_part[<?= (int)$part['pp_id'] ?>]" value="1" title="この商品から部位を外す"></td>
    </tr>
  <?php endforeach; ?>
    <tr class="row-add">
      <td class="in"><input type="text" name="new_part[name]" list="dl-parts" placeholder="＋部位を追加" class="w-200"></td>
      <td class="in num"><input type="number" step="any" min="0" name="new_part[pieces]" class="w-80"></td>
      <td colspan="2" class="small">トッピングなど1台ずつ使うものは、1Bでできる数を「1」にします。</td>
    </tr>
  </tbody>
</table>

<div class="legend">
  <span class="lg in">黄色＝入力する欄</span>
  <span class="lg auto">灰色＝自動で計算・今のデータから表示</span>
  <span class="lg done">緑＝納品済（済）</span>
  <span class="note">発注数を空にして保存すると、その発注は「取消」になります。外すにチェックした行は、保存すると配合から外れます（材料そのものは消えません）。</span>
</div>
<p><button class="btn btn-primary">保存</button></p>
</fieldset>
</form>
