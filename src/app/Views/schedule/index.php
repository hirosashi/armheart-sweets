<?php
use App\Controllers\ScheduleController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
use App\Services\Jobs;
use App\Controllers\OrderController;
$title = 'スケジュール';
$editable = Auth::can('require');
$youbi = ['日', '月', '火', '水', '木', '金', '土'];
$qs = fn(string $f, array $extra = []) => App::url('/schedule?' . http_build_query(array_merge(
    ['from' => $f, 'span' => $span] + ($only_open ? ['filter' => 'open'] : []) + ($single > 0 ? ['job' => $single] : []), $extra)));
$statusClass = ['todo' => 'plan', 'doing' => 'doing', 'done' => 'done'];
$hidden = fn() => Csrf::field()
    . '<input type="hidden" name="from" value="' . View::e($from) . '">'
    . '<input type="hidden" name="span" value="' . (int)$span . '">'
    . '<input type="hidden" name="back_job" value="' . (int)$single . '">';
$editHidden = fn(int $jid) => $hidden() . '<input type="hidden" name="back_edit" value="' . $jid . '">';
$productOptions = function (int $selected) use ($products): string {
    $h = '';
    foreach ($products as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $selected ? ' selected' : '') . '>' . View::e($p['name']) . '</option>';
    }
    return $h;
};
$first = $days[0];
$last  = $days[count($days) - 1];
$clip  = fn(string $d) => max($first, min($last, $d));
?>
<h1 class="page-title">スケジュール</h1>
<p class="page-lead">発注（得意先からの注文）ごとに1ブロック。太字の行が発注、その下に作る商品、商品ごとに部位の仕込み、最後に「材料の発注」。
  行の「進み具合」「材料」でその発注の画面へ。部位の仕込み日は商品の登録時に仕上げ日の前日へ仮置きされるので、「直す」で日や回数を動かしてください。
  同じ日に同じ部位を複数の発注で仕込むときは、できた回数を納品日の早い発注から順に割り当てます。</p>
<?php if ($single > 0): ?>
  <p class="note">発注1件だけを表示しています。<a href="<?= View::e(App::url('/schedule?from=' . $from . '&span=' . $span)) ?>">すべての発注を表示する</a></p>
<?php endif; ?>

<form method="get" action="<?= View::e(App::url('/schedule')) ?>" class="week-bar">
  <a class="btn btn-plain" href="<?= View::e($qs($prev_from)) ?>">← 前の週</a>
  <a class="btn btn-plain<?= $from === $this_from ? ' btn-primary' : '' ?>" href="<?= View::e($qs($this_from)) ?>">今日</a>
  <a class="btn btn-plain" href="<?= View::e($qs($next_from)) ?>">次の週 →</a>
  <strong>表示期間：<?= View::e(Clock::dayLabel($from)) ?> 〜 <?= View::e(Clock::dayLabel($to)) ?></strong>
  <span class="range-pick">
    <input type="date" name="from" value="<?= View::e($from) ?>">
    <select name="span">
      <?php foreach (ScheduleController::SPAN_OPTIONS as $n => $lb): ?>
        <option value="<?= $n ?>" <?= $n === $span ? 'selected' : '' ?>><?= View::e($lb) ?></option>
      <?php endforeach; ?>
    </select>
    絞り込み
    <select name="filter">
      <option value="all" <?= !$only_open ? 'selected' : '' ?>>すべての発注</option>
      <option value="open" <?= $only_open ? 'selected' : '' ?>>進行中だけ</option>
    </select>
    <button class="btn btn-plain">表示する</button>
    <?php if ($editable): ?>
      <a class="btn" href="<?= View::e($qs($from, ['add' => 1])) ?>#add">＋ 発注（つくる予定）を追加</a>
    <?php endif; ?>
  </span>
</form>

<p class="gantt-legend">
  <span class="b plan">予定</span><span class="b doing">仕込み中・前日から</span><span class="b done">できあがり</span>
  <span class="b deliv">仕上げ・納品</span><span class="b po">材料の発注</span><span class="b late">未発注・遅れ</span>
</p>

<?php if ($add && $editable): ?>
<form method="post" action="<?= View::e(App::url('/schedule/job')) ?>" class="box gantt-form" id="add">
  <?= $hidden() ?>
  <strong>発注（つくる予定）を追加</strong>
  <label>得意先 <input type="text" name="customer_name" maxlength="100" placeholder="例：○○百貨店"></label>
  <label>案件名 <input type="text" name="title" maxlength="100" placeholder="例：クリスマス用"></label>
  <label>納品日 <input type="date" name="delivery_date" value="<?= View::e($default_delivery) ?>" required></label>
  <table class="table table-narrow add-items">
    <thead><tr><th>作る商品</th><th>台数</th><th>仕上げ日（空なら納品日の前日）</th></tr></thead>
    <tbody>
    <?php for ($n = 0; $n < ScheduleController::ADD_ROWS; $n++): ?>
      <tr>
        <td><select name="product_id[]" class="select-search"<?= $n === 0 ? ' required' : '' ?>><option value="">-- 選ぶ --</option><?= $productOptions(0) ?></select></td>
        <td><input type="number" name="qty[]" min="1" class="w-80"<?= $n === 0 ? ' required' : '' ?>>台</td>
        <td><input type="date" name="finish_date[]"></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  <label>メモ <input type="text" name="note" maxlength="255" class="w-200"></label>
  <button class="btn">登録する</button>
  <a class="btn btn-plain" href="<?= View::e($qs($from)) ?>">やめる</a>
  <p class="note">登録すると、商品ごとに配合から部位の仕込み回数を計算し、仕上げ日の前日に仮置きします。商品は登録したあとも「直す」で足せます。</p>
</form>
<?php endif; ?>

<div class="gantt-wrap">
<table class="gantt">
  <thead>
    <tr>
      <th class="g-label">発注 ／ 商品 ／ 部位</th>
      <?php foreach ($days as $d): $w = (int)Clock::parse($d)->format('w'); ?>
        <th class="g-day<?= $d === $today ? ' today' : '' ?><?= $w === 0 || $w === 6 ? ' weekend' : '' ?>">
          <?= (int)substr($d, 5, 2) ?>/<?= (int)substr($d, 8, 2) ?><br><small><?= $youbi[$w] ?><?= $d === $today ? '・今日' : '' ?></small>
        </th>
      <?php endforeach; ?>
    </tr>
  </thead>

  <?php if ($jobs === []): ?>
    <tbody><tr><td colspan="<?= count($days) + 1 ?>" class="g-empty">この期間に関係する発注（つくる予定）はありません。
      <?php if ($editable): ?>「＋ 発注（つくる予定）を追加」から登録してください。<?php endif; ?></td></tr></tbody>
  <?php endif; ?>

  <?php foreach ($jobs as $j): ?>
    <?php
    $jid    = (int)$j['id'];
    $ach    = $j['ach'];
    $isEdit = $edit_job === $jid && $editable;
    $starts = array_merge(array_column($j['parts'], 'target_date'), array_column($j['items'], 'finish_date'));
    $start  = $starts !== [] ? min($starts) : $j['delivery_date'];
    $isLate = $j['status'] === 'open' && $j['delivery_date'] < $today && $ach['rate'] < 100;
    ?>
    <tbody class="g-job<?= $j['status'] !== 'open' ? ' g-closed' : '' ?>" data-job="<?= $jid ?>" id="job-<?= $jid ?>">
      <tr class="g-head">
        <td class="g-label">
          <button type="button" class="g-toggle" title="商品・部位の行を開く／閉じる">▼</button>
          <span class="g-title"><?= View::e(Jobs::label($j)) ?></span>
          <span class="g-sub"><?= View::e(Jobs::itemSummary($j['items'])) ?>　納品 <?= View::e(Clock::dayLabel($j['delivery_date'])) ?>
            <?php if ($j['status'] !== 'open'): ?><span class="flow-state flow-done"><?= View::e(Jobs::STATUS_LABELS[$j['status']]) ?></span><?php endif; ?>
            <?php if ($isLate): ?><span class="flow-state g-late">納品日を過ぎています</span><?php endif; ?>
          </span>
          <span class="g-rate"><span class="bar"><span class="bar-fill" style="width:<?= (int)$ach['rate'] ?>%"></span></span><?= (int)$ach['rate'] ?>%
            <small>（できあがり <?= (int)$ach['done'] ?>／<?= (int)$ach['total'] ?>）</small></span>
          <span class="g-links">
            <a href="<?= View::e(App::url('/progress?date=' . $clip($start))) ?>">進み具合</a>
            <a href="<?= View::e(App::url('/require?job=' . $jid)) ?>">材料</a>
            <?php if ($editable): ?>
              <a href="<?= View::e($qs($from, ['edit' => $jid])) ?>#job-<?= $jid ?>">直す</a>
            <?php endif; ?>
          </span>
        </td>
        <?php foreach ($days as $d): ?>
          <?php
          $cls = '';
          $txt = '';
          if ($d === $j['delivery_date']) {
              $cls = 'deliv';
              $txt = '納品';
          } elseif ($d >= $start && $d < $j['delivery_date']) {
              $cls = 'span';
          }
          ?>
          <td class="g-cell<?= $d === $today ? ' today' : '' ?>"><?php if ($cls !== ''): ?><span class="b <?= $cls ?>"><?= $txt ?></span><?php endif; ?></td>
        <?php endforeach; ?>
      </tr>

      <?php if ($isEdit): ?>
      <tr class="g-edit">
        <td colspan="<?= count($days) + 1 ?>">
          <form method="post" action="<?= View::e(App::url('/schedule/job')) ?>" class="gantt-form">
            <?= $editHidden($jid) ?>
            <input type="hidden" name="id" value="<?= $jid ?>">
            <strong>発注</strong>
            <label>得意先 <input type="text" name="customer_name" maxlength="100" value="<?= View::e($j['customer_name']) ?>"></label>
            <label>案件名 <input type="text" name="title" maxlength="100" value="<?= View::e($j['title']) ?>"></label>
            <label>納品日 <input type="date" name="delivery_date" value="<?= View::e($j['delivery_date']) ?>"></label>
            <label>状態
              <select name="status">
                <?php foreach (Jobs::STATUS_LABELS as $k => $lb): ?>
                  <option value="<?= $k ?>" <?= $k === $j['status'] ? 'selected' : '' ?>><?= View::e($lb) ?></option>
                <?php endforeach; ?>
              </select></label>
            <label>メモ <input type="text" name="note" maxlength="255" class="w-200" value="<?= View::e($j['note']) ?>"></label>
            <button class="btn btn-small">保存</button>
            <a class="btn btn-small btn-plain" href="<?= View::e($qs($from)) ?>">直すのをやめる</a>
          </form>
          <form method="post" action="<?= View::e(App::url('/schedule/item')) ?>" class="gantt-form">
            <?= $editHidden($jid) ?><input type="hidden" name="job_id" value="<?= $jid ?>">
            <strong>商品を追加</strong>
            <select name="product_id" class="select-search"><option value="">-- 選ぶ --</option><?= $productOptions(0) ?></select>
            <input type="number" name="qty" min="1" class="w-80" placeholder="台数">台
            <label>仕上げ日 <input type="date" name="finish_date"></label>
            <button class="btn btn-small btn-plain">追加</button>
            <span class="note">仕上げ日が空なら納品日の前日</span>
          </form>
          <form method="post" action="<?= View::e(App::url('/schedule/job/delete')) ?>" class="gantt-form"
                onsubmit="return confirm('この発注を削除します。作る商品と部位の仕込みの割り振りも消えます。よいですか？');">
            <?= $hidden() ?><input type="hidden" name="id" value="<?= $jid ?>">
            <button class="btn btn-small btn-danger">この発注を削除する</button>
          </form>
        </td>
      </tr>
      <?php endif; ?>

      <?php foreach ($j['items'] as $it): ?>
        <?php $iid = (int)$it['id']; $iach = $it['ach']; ?>
        <tr class="g-item" id="item-<?= $iid ?>">
          <td class="g-label">
            <?php if ($isEdit): ?>
              <form method="post" action="<?= View::e(App::url('/schedule/item')) ?>" class="g-inline">
                <?= $editHidden($jid) ?>
                <input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="item_id" value="<?= $iid ?>">
                <select name="product_id" class="select-search"><?= $productOptions((int)$it['product_id']) ?></select>
                <input type="number" name="qty" min="0" class="w-60" value="<?= (int)$it['qty'] ?>">台
                <input type="date" name="finish_date" value="<?= View::e($it['finish_date']) ?>">
                <button class="btn btn-small">保存</button>
                <span class="note">0台で外す。商品・台数・仕上げ日を変えると、この商品の部位を仮置きし直します</span>
              </form>
            <?php else: ?>
              <span class="g-item-name"><?= View::e($it['product_name']) ?> <?= (int)$it['qty'] ?>台</span>
              <span class="g-sub">仕上げ <?= View::e(Clock::dayLabel($it['finish_date'])) ?>　部位 <?= (int)$iach['done'] ?>／<?= (int)$iach['total'] ?> できあがり</span>
            <?php endif; ?>
          </td>
          <?php foreach ($days as $d): ?>
            <td class="g-cell<?= $d === $today ? ' today' : '' ?>"><?php if ($d === $it['finish_date']): ?><span class="b deliv light">仕上げ</span><?php endif; ?></td>
          <?php endforeach; ?>
        </tr>

        <?php foreach ($it['parts'] as $r): ?>
          <?php
          $total   = (float)$r['batches'];
          $done    = (float)$r['done_qty'];
          $rowLate = $r['status'] !== 'done' && $r['target_date'] < $today;
          $cls = $rowLate ? 'late' : $statusClass[$r['status']];
          ?>
          <tr class="g-part">
            <td class="g-label">
              <?php if ($isEdit): ?>
                <form method="post" action="<?= View::e(App::url('/schedule/part')) ?>" class="g-inline">
                  <?= $editHidden($jid) ?>
                  <input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="job_item_id" value="<?= $iid ?>">
                  <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
                  <select name="part_id">
                    <?php foreach ($parts_all as $pt): ?>
                      <option value="<?= (int)$pt['id'] ?>" <?= (int)$pt['id'] === (int)$r['part_id'] ? 'selected' : '' ?>><?= View::e($pt['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="date" name="target_date" value="<?= View::e($r['target_date']) ?>">
                  <input type="number" name="batches" step="0.5" min="0" class="w-60" value="<?= View::e(View::num($total, 1)) ?>">回
                  <button class="btn btn-small">保存</button>
                  <span class="note">0回で行を消す</span>
                </form>
              <?php else: ?>
                <span class="g-part-name"><?= View::e($r['part_name']) ?></span>
                <span class="g-sub"><?= View::e(View::num($total, 0)) ?>回　仕込み <?= View::e(Clock::dayLabel($r['target_date'])) ?>
                  <?php if ($done > 0): ?>　できた <?= View::e(View::num($done, 1)) ?>回<?php endif; ?>
                </span>
              <?php endif; ?>
            </td>
            <?php foreach ($days as $d): ?>
              <td class="g-cell<?= $d === $today ? ' today' : '' ?>">
                <?php if ($d === $r['target_date']): ?>
                  <a class="b <?= $cls ?>" href="<?= View::e(App::url('/progress?date=' . $d)) ?>" title="この日の部位の進み具合へ">
                    <?= View::e(View::num($total, 0)) ?>回<?= $r['status'] === 'doing' ? ' 仕込み中' : ($r['status'] === 'done' ? ' 済' : ($rowLate ? ' 遅れ' : '')) ?>
                  </a>
                <?php elseif ($rowLate && $d === $today): ?>
                  <a class="b doing" href="<?= View::e(App::url('/progress?date=' . $d)) ?>">残り<?= View::e(View::num(max(0, $total - $done), 0)) ?>回</a>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>

        <?php if ($isEdit): ?>
          <tr class="g-part">
            <td class="g-label">
              <form method="post" action="<?= View::e(App::url('/schedule/part')) ?>" class="g-inline">
                <?= $editHidden($jid) ?><input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="job_item_id" value="<?= $iid ?>">
                <select name="part_id"><option value="">-- 部位を追加 --</option>
                  <?php foreach ($parts_all as $pt): ?><option value="<?= (int)$pt['id'] ?>"><?= View::e($pt['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="date" name="target_date" value="<?= View::e(Clock::shiftDays($it['finish_date'], -1)) ?>">
                <input type="number" name="batches" step="0.5" min="0" class="w-60" placeholder="回数">回
                <button class="btn btn-small btn-plain">追加</button>
              </form>
            </td>
            <td colspan="<?= count($days) ?>" class="g-cell"></td>
          </tr>
        <?php elseif ($it['parts'] === []): ?>
          <tr class="g-part"><td class="g-label note">部位の仕込みがありません（商品に部位の配合が登録されていないか、行を消しています）</td>
            <td colspan="<?= count($days) ?>" class="g-cell"></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($j['items'] === []): ?>
        <tr class="g-item"><td class="g-label note">作る商品がありません<?= $editable ? '（「直す」から商品を追加してください）' : '' ?></td>
          <td colspan="<?= count($days) ?>" class="g-cell"></td></tr>
      <?php endif; ?>

      <?php foreach ($j['orders'] as $o): ?>
        <?php
        $isDraft = $o['status'] === 'draft';
        $oFrom = $o['order_date'] ?: $first;
        $oTo   = $o['delivered_date'] ?: ($o['desired_date'] ?: $oFrom);
        if ($oTo < $oFrom) {
            $oTo = $oFrom;
        }
        $oLate = !$isDraft && $o['status'] !== 'delivered' && $oTo < $today;
        ?>
        <tr class="g-po">
          <td class="g-label">
            <a href="<?= View::e(App::url('/orders/show?id=' . (int)$o['id'])) ?>">材料の発注：<?= View::e($o['supplier_name']) ?></a>
            <span class="g-sub"><?= (int)$o['item_count'] ?>品目　
              <?php if ($isDraft): ?><span class="flow-state g-late">未発注</span>
              <?php else: ?><span class="flow-state <?= $o['status'] === 'delivered' ? 'flow-done' : 'flow-partial' ?>"><?= View::e(OrderController::STATUS_LABELS[$o['status']] ?? $o['status']) ?></span>
                納品 <?= (int)$o['received_count'] ?>／<?= (int)$o['item_count'] ?>（<?= (int)$o['rate'] ?>%）<?php endif; ?>
            </span>
          </td>
          <?php foreach ($days as $d): ?>
            <td class="g-cell<?= $d === $today ? ' today' : '' ?>">
              <?php if ($d >= $oFrom && $d <= $oTo): ?>
                <span class="b <?= $isDraft ? 'late' : ($oLate ? 'late' : 'po') ?><?= $d === $oFrom ? ' st' : '' ?><?= $d === $oTo ? ' en' : '' ?>"><?php
                  if ($d === $oFrom) { echo $isDraft ? '未発注' : '発注'; }
                  elseif ($d === $oTo) { echo $o['status'] === 'delivered' ? '納品済' : ($oLate ? '遅れ' : '納品予定'); }
                ?></span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($j['orders'] === []): ?>
        <tr class="g-po"><td class="g-label note">材料の発注：なし　<a href="<?= View::e(App::url('/require?job=' . $jid)) ?>">足りない材料を確認する</a></td>
          <td colspan="<?= count($days) ?>" class="g-cell"></td></tr>
      <?php endif; ?>
    </tbody>
  <?php endforeach; ?>
</table>
</div>

<script>
document.querySelectorAll('.g-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var body = btn.closest('tbody');
    body.classList.toggle('collapsed');
    btn.textContent = body.classList.contains('collapsed') ? '▶' : '▼';
  });
});
</script>
