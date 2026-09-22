<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\View;
use App\Services\Requirement;
$title = '必要な材料と足りない分';
$judgeClass = ['short' => 'judge-short', 'tight' => 'judge-tight', 'ok' => 'judge-ok', 'exempt' => 'judge-exempt'];
$plannedIds = array_column($plans, 'product_id');
$showDays   = $days <= 7;   // 日ごとの列は7日までのとき出す（それ以上は合計だけ）
$q = fn(string $d) => '/require?date=' . $d . '&days=' . $days;
?>
<h1 class="page-title">必要な材料と足りない分</h1>
<p class="page-lead">開始日から「先読み期間」ぶんのつくる数をまとめて、材料が何をどれだけ買えばよいかを出します。日ごとのつくる数は
  <a href="<?= View::e(App::url('/schedule?week=' . Clock::weekStart($date))) ?>">スケジュール</a>でも入れられます。</p>

<form method="get" action="<?= View::e(App::url('/require')) ?>" class="week-bar">
  <a class="btn btn-plain" href="<?= View::e(App::url($q($prev_date))) ?>">← 前の日</a>
  <strong><?= View::e(Clock::dayLabel($date)) ?> 〜 <?= View::e(Clock::dayLabel($to)) ?>（<?= (int)$days ?>日間）</strong>
  <a class="btn btn-plain" href="<?= View::e(App::url($q($next_date))) ?>">次の日 →</a>
  <span class="range-pick">
    開始日 <input type="date" name="date" value="<?= View::e($date) ?>">
    先読み期間
    <select name="days">
      <?php foreach ([1, 3, 5, 7, 10, 14, 21, 31] as $n): ?>
        <option value="<?= $n ?>" <?= $n === $days ? 'selected' : '' ?>><?= $n ?>日</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-plain">表示する</button>
  </span>
</form>

<h2 class="sec-title" id="plan">① <?= View::e(Clock::dayLabel($date)) ?> につくる数</h2>
<form method="post" action="<?= View::e(App::url('/require/plan')) ?>" class="box">
  <?= Csrf::field() ?>
  <input type="hidden" name="date" value="<?= View::e($date) ?>">
  <input type="hidden" name="days" value="<?= (int)$days ?>">
  <table class="table table-narrow">
    <thead><tr><th>商品</th><th class="num">つくる数（台）</th></tr></thead>
    <tbody>
    <?php foreach ($plans as $pl): ?>
      <tr>
        <td><?= View::e($pl['name']) ?><?= $pl['spec'] ? '（' . View::e($pl['spec']) . '）' : '' ?></td>
        <td class="num"><input type="number" name="plan_qty[<?= (int)$pl['product_id'] ?>]"
               value="<?= (int)$pl['qty'] ?>" class="w-100" min="0"></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td>
          <select name="new_product_id">
            <option value="">-- 商品を追加する --</option>
            <?php foreach ($products as $p): ?>
              <?php if (in_array($p['id'], $plannedIds)) { continue; } ?>
              <option value="<?= (int)$p['id'] ?>"><?= View::e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="num"><input type="number" name="new_qty" class="w-100" min="0" placeholder="台数"></td>
      </tr>
    </tbody>
  </table>
  <?php if (Auth::can('require')): ?>
    <button class="btn">この数で計算する</button>
  <?php else: ?>
    <p class="note">※ つくる数を変更できるのは管理者と発注担当です。</p>
  <?php endif; ?>
</form>

<?php if ($days > 1): ?>
<table class="table table-narrow">
  <thead><tr><th>日</th><th>この期間につくる数（他の日）</th></tr></thead>
  <tbody>
  <?php foreach ($day_list as $d): if ($d === $date) { continue; } ?>
    <tr>
      <td><a href="<?= View::e(App::url($q($d))) ?>"><?= View::e(Clock::dayLabel($d)) ?></a></td>
      <td><?php if (empty($plans_by_day[$d])): ?><span class="note">なし</span><?php else: ?>
        <?php foreach ($plans_by_day[$d] as $pl): ?>
          <span class="chip"><?= View::e($pl['name']) ?> <?= (int)$pl['qty'] ?>台</span>
        <?php endforeach; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($plans_by_day === []): ?>
  <p class="alert alert-warn">この期間のつくる数がまだ入っていません。上の欄に台数を入れて「この数で計算する」を押してください。</p>
<?php else: ?>

<h2 class="sec-title" id="batch">② 仕込み（バッチ）の回数</h2>
<p class="note">1台に使う量 × 台数 ÷ 歩留まり ÷ 1バッチの合計量 を、日ごとにバッチ単位へ切り上げた回数です。期間の合計はその和です。</p>
<table class="table">
  <thead><tr><th>部位</th><th class="num">必要な量（合計）</th><th class="num">1バッチ</th>
    <?php if ($showDays): foreach ($day_list as $d): ?><th class="num small"><?= View::e(Clock::dayLabel($d)) ?></th><?php endforeach; endif; ?>
    <th class="num">仕込み回数（合計）</th></tr></thead>
  <tbody>
  <?php foreach ($parts as $pt): ?>
    <tr>
      <td><a href="<?= View::e(App::url('/parts/show?id=' . $pt['id'])) ?>"><?= View::e($pt['name']) ?></a></td>
      <td class="num"><?= View::e(View::num($pt['need_qty'], 1)) ?><?= View::e($pt['unit']) ?></td>
      <td class="num"><?= View::e(View::num($pt['batch_total_qty'], 1)) ?><?= View::e($pt['unit']) ?></td>
      <?php if ($showDays): foreach ($day_list as $d): ?>
        <td class="num"><?= isset($pt['days'][$d]) ? View::e(View::num($pt['days'][$d], 0)) : '' ?></td>
      <?php endforeach; endif; ?>
      <td class="num"><strong><?= View::e(View::num($pt['batches'], 0)) ?> 回</strong></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 class="sec-title" id="short">③ 材料の必要量と足りない分</h2>
<p class="summary">
  <span class="judge-short">足りない <?= (int)$summary['short'] ?></span>
  <span class="judge-tight">あぶない <?= (int)$summary['tight'] ?></span>
  <span class="judge-ok">足りている <?= (int)$summary['ok'] ?></span>
  <span class="judge-exempt">対象外 <?= (int)$summary['exempt'] ?></span>
  　
  <?php if ($only): ?>
    <a href="<?= View::e(App::url($q($date))) ?>">すべて表示する</a>
  <?php else: ?>
    <a href="<?= View::e(App::url($q($date) . '&only=short')) ?>">足りない・あぶないだけ表示する</a>
  <?php endif; ?>
</p>

<form method="post" action="<?= View::e(App::url('/require/order')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="date" value="<?= View::e($date) ?>">
  <input type="hidden" name="days" value="<?= (int)$days ?>">
  <table class="table">
    <thead>
      <tr>
        <th class="w-30">発注</th><th>材料・資材</th><th>メーカー</th>
        <?php if ($showDays): foreach ($day_list as $d): ?><th class="num small"><?= View::e(Clock::dayLabel($d)) ?></th><?php endforeach; endif; ?>
        <th class="num">必要な量（合計）</th><th class="num">今ある量</th><th class="num">過不足</th>
        <th>判定</th><th class="num">発注数</th><th>仕入先</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($materials as $m): ?>
      <tr class="<?= View::e($judgeClass[$m['judge']]) ?>-row">
        <td class="text-center">
          <?php if ($m['order_qty'] !== null && (float)$m['order_qty'] > 0): ?>
            <input type="checkbox" name="material_id[]" value="<?= (int)$m['id'] ?>"
                   <?= $m['judge'] === 'short' ? 'checked' : '' ?>>
          <?php endif; ?>
        </td>
        <td><a href="<?= View::e(App::url('/stock/show?id=' . $m['id'])) ?>"><?= View::e($m['name']) ?></a>
            <?php if ((int)$m['is_supplied'] === 1): ?><span class="badge">支給品</span><?php endif; ?></td>
        <td><?= View::e($m['maker_name']) ?></td>
        <?php if ($showDays): foreach ($day_list as $d): ?>
          <td class="num small"><?= isset($need_by_day[(int)$m['id']][$d]) ? View::e(View::num($need_by_day[(int)$m['id']][$d], 1)) : '' ?></td>
        <?php endforeach; endif; ?>
        <td class="num"><?= View::e(View::num($m['need_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="num"><?= View::e(View::num($m['stock_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="num"><?= View::e(View::num($m['diff_qty'], 1)) ?><?= View::e($m['unit']) ?></td>
        <td class="<?= View::e($judgeClass[$m['judge']]) ?>"><?= View::e(Requirement::JUDGE_LABELS[$m['judge']]) ?></td>
        <td class="num"><?php if ($m['order_qty'] !== null && (float)$m['order_qty'] > 0): ?>
            <strong><?= View::e(View::num($m['order_qty'], 0)) ?><?= View::e($m['purchase_unit']) ?></strong>
        <?php endif; ?></td>
        <td><?= View::e($m['supplier_name']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($materials === []): ?>
      <tr><td colspan="<?= 9 + ($showDays ? $days : 0) ?>" class="text-center">表示する材料がありません。</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if (Auth::can('order')): ?>
    <p><button class="btn">チェックした材料を発注（未発注）に追加する</button>
       <span class="note">仕入先ごとに1件の発注をつくります。発注には「<?= View::e(Clock::dayLabel($date)) ?>〜<?= View::e(Clock::dayLabel($to)) ?>のぶん」と記録されます。</span></p>
  <?php endif; ?>
</form>
<?php endif; ?>
