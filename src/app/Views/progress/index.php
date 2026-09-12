<?php
use App\Controllers\ProgressController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
$title = '部位の進み具合';
$editable = Auth::can('progress');
?>
<h1 class="page-title">部位の進み具合</h1>
<p class="page-lead">今週つくる部位を、仕込みの進み方で並べています。担当と できた回数 を入れて動かしてください。<br>
  「できあがり」にすると、できた回数ぶんの材料（回数 × 1バッチの配合量）を <a href="<?= View::e(App::url('/stock')) ?>">材料の在庫</a> から自動で引きます（戻すと在庫も戻ります）。</p>

<div class="week-bar">
  <a class="btn btn-plain" href="<?= View::e(App::url('/progress?week=' . $prev_week)) ?>">← 前の週</a>
  <strong><?= View::e(View::d($week)) ?>（月）からの1週間</strong>
  <a class="btn btn-plain" href="<?= View::e(App::url('/progress?week=' . $next_week)) ?>">次の週 →</a>
</div>

<?php if (!$has_plan): ?>
  <p class="alert alert-warn">この週のつくる数が入っていません。
    <a href="<?= View::e(App::url('/require?week=' . $week)) ?>">必要な材料と足りない分</a>の画面で台数を入れてください。</p>
<?php endif; ?>

<div class="kanban">
  <?php foreach (ProgressController::STATUS_LABELS as $status => $label): ?>
    <div class="kanban-col">
      <div class="kanban-head"><?= View::e($label) ?>（<?= count($columns[$status]) ?>）</div>
      <?php foreach ($columns[$status] as $c): ?>
        <div class="kanban-card">
          <div class="kanban-title"><?= View::e($c['part_name']) ?></div>
          <div class="kanban-body">
            仕込み <strong><?= View::e(View::num($c['batches'], 0)) ?> 回</strong>／必要 <?= View::e(View::num($c['need_qty'], 1)) ?><?= View::e($c['unit']) ?>
          </div>
          <?php if ($editable): ?>
          <form method="post" action="<?= View::e(App::url('/progress/save')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="week" value="<?= View::e($week) ?>">
            <input type="hidden" name="part_id" value="<?= (int)$c['part_id'] ?>">
            <input type="hidden" name="planned_qty" value="<?= View::e($c['batches']) ?>">
            <label class="kanban-label">できた回数
              <input type="number" step="0.001" min="0" name="done_qty" value="<?= View::e($c['done_qty']) ?>"
                     placeholder="<?= View::e(View::num($c['batches'], 0)) ?>" class="w-80"></label>
            <label class="kanban-label">担当
              <input type="text" name="assignee" value="<?= View::e($c['assignee']) ?>" class="w-100"></label>
            <label class="kanban-label">メモ
              <input type="text" name="note" value="<?= View::e($c['note']) ?>" class="w-100"></label>
            <select name="status">
              <?php foreach (ProgressController::STATUS_LABELS as $k => $v): ?>
                <option value="<?= View::e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= View::e($v) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-small">保存</button>
          </form>
          <?php else: ?>
            <div class="kanban-body">できた回数 <?= View::e(View::num($c['done_qty'], 1)) ?>／担当 <?= View::e($c['assignee']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($columns[$status] === []): ?>
        <div class="kanban-empty">なし</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
