<?php
use App\Controllers\ProgressController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
$title = 'スケジュール';
$editable = Auth::can('require');
$youbi = ['月', '火', '水', '木', '金', '土', '日'];
$statusClass = ['todo' => 'flow-todo', 'doing' => 'flow-partial', 'done' => 'flow-done'];
?>
<h1 class="page-title">スケジュール</h1>
<p class="page-lead">月曜から日曜の1週間です。上段が「つくる数」、下段が「仕込み（部位）」の状況。日付を押すとその日の部位の進み具合へ、
  「材料」でその日から7日分の必要な材料へ移ります。終わらなかった仕込みは翌日のマスに「前日から」として引き継がれます。</p>

<form method="get" action="<?= View::e(App::url('/schedule')) ?>" class="week-bar">
  <a class="btn btn-plain" href="<?= View::e(App::url('/schedule?week=' . $prev_week)) ?>">← 前の週</a>
  <strong><?= View::e(Clock::dayLabel($week)) ?> 〜 <?= View::e(Clock::dayLabel($to)) ?></strong>
  <a class="btn btn-plain" href="<?= View::e(App::url('/schedule?week=' . $next_week)) ?>">次の週 →</a>
  <span class="range-pick">
    <?php if ($week !== $this_week): ?><a class="btn btn-plain" href="<?= View::e(App::url('/schedule')) ?>">今週へ</a><?php endif; ?>
    日を選ぶ <input type="date" name="week" value="<?= View::e($week) ?>"> <button class="btn btn-plain">表示する</button>
  </span>
</form>

<div class="calendar">
  <?php foreach ($days as $i => $d): ?>
    <?php $isToday = $d === $today; $cnt = $status_count[$d]; $cards = $cards_by_day[$d]; $plans = $plans_by_day[$d]; ?>
    <div class="cal-day<?= $isToday ? ' today' : '' ?><?= $i >= 5 ? ' weekend' : '' ?>">
      <div class="cal-head">
        <a href="<?= View::e(App::url('/progress?date=' . $d)) ?>" title="この日の部位の進み具合へ">
          <span class="cal-date"><?= (int)substr($d, 8, 2) ?></span><span class="cal-youbi">（<?= $youbi[$i] ?>）</span>
          <?php if ($isToday): ?><span class="badge">今日</span><?php endif; ?>
        </a>
        <a class="cal-link" href="<?= View::e(App::url('/require?date=' . $d)) ?>">材料</a>
      </div>

      <div class="cal-sec">
        <div class="cal-sec-title">つくる数
          <?php if ($editable): ?>
            <a class="cal-edit" href="<?= View::e(App::url('/schedule?week=' . $week . '&edit=' . $d . '#day-' . $d)) ?>">直す</a>
          <?php endif; ?>
        </div>
        <?php if ($edit_date === $d && $editable): ?>
          <form method="post" action="<?= View::e(App::url('/schedule/plan')) ?>" class="cal-form" id="day-<?= View::e($d) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="date" value="<?= View::e($d) ?>">
            <?php $plannedIds = array_column($plans, 'product_id'); ?>
            <?php foreach ($plans as $pl): ?>
              <label><?= View::e($pl['name']) ?>
                <input type="number" min="0" name="plan_qty[<?= (int)$pl['product_id'] ?>]" value="<?= (int)$pl['qty'] ?>" class="w-60">台</label>
            <?php endforeach; ?>
            <select name="new_product_id">
              <option value="">-- 商品を追加 --</option>
              <?php foreach ($products as $p): if (in_array($p['id'], $plannedIds)) { continue; } ?>
                <option value="<?= (int)$p['id'] ?>"><?= View::e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" min="0" name="new_qty" class="w-60" placeholder="台数">
            <div><button class="btn btn-small">保存</button>
              <a class="btn btn-small btn-plain" href="<?= View::e(App::url('/schedule?week=' . $week)) ?>">やめる</a></div>
          </form>
        <?php else: ?>
          <?php if ($plans === []): ?>
            <div class="cal-empty">なし</div>
          <?php else: ?>
            <?php foreach ($plans as $pl): ?>
              <div class="cal-plan"><?= View::e($pl['name']) ?> <strong><?= (int)$pl['qty'] ?></strong>台</div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="cal-sec">
        <div class="cal-sec-title">仕込み
          <?php if ($cards !== []): ?>
            <span class="cal-count">できあがり <?= (int)$cnt['done'] ?>／<?= count($cards) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($cards === []): ?>
          <div class="cal-empty">なし</div>
        <?php else: ?>
          <?php foreach ($cards as $c): ?>
            <div class="cal-part">
              <span class="flow-state <?= View::e($statusClass[$c['status']]) ?>"><?= View::e(ProgressController::STATUS_LABELS[$c['status']]) ?></span>
              <?= View::e($c['part_name']) ?> <?= View::e(View::num($c['planned'] + $c['carried'], 0)) ?>回
              <?php if ($c['carried'] > 0): ?><span class="carry">（前日から<?= View::e(View::num($c['carried'], 0)) ?>）</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
