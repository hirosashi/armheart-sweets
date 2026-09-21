<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\View;
use App\Services\Flow;

// 日々の作業で使う画面（いつも出しておく）
$menuWork = [
    ['label' => 'スケジュール',             'path' => '/schedule'],
    ['label' => '必要な材料と足りない分', 'path' => '/require'],
    ['label' => '発注の管理',             'path' => '/orders'],
    ['label' => '部位の進み具合',         'path' => '/progress'],
    ['label' => '材料の在庫',             'path' => '/stock'],
];

// 必要なときだけ使う登録画面（「登録・確認」にまとめる）
$menuSetup = [
    ['label' => '商品と配合', 'path' => '/products'],
    ['label' => '部位の登録', 'path' => '/parts'],
    ['label' => '材料の一覧', 'path' => '/materials'],
];

$current  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$isActive = static function (string $path) use ($current): bool {
    $base = parse_url($path, PHP_URL_PATH) ?? '/';
    return rtrim($current, '/') === rtrim(App::url($base), '/');
};
$setupOpen = false;
foreach ($menuSetup as $m) {
    if ($isActive($m['path'])) {
        $setupOpen = true;
    }
}

$flowDate  = Clock::today();
$flowSteps = [];
if (Auth::check()) {
    try {
        $flowSteps = Flow::steps($flowDate);
    } catch (\Throwable $e) {
        $flowSteps = [];
    }
}
$flowDone = Flow::summary($flowSteps);

// 工程のリンク。どの工程から来たかを step で持たせ、押した工程だけを強調する
$flowStep = (int)($_GET['step'] ?? 0);
$flowHref = static function (array $step): string {
    $path     = (string)$step['path'];
    $fragment = '';
    if (str_contains($path, '#')) {
        [$path, $fragment] = explode('#', $path, 2);
        $fragment = '#' . $fragment;
    }
    $sep = str_contains($path, '?') ? '&' : '?';
    return App::url($path) . $sep . 'step=' . (int)$step['no'] . $fragment;
};
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e(($title ?? '') !== '' ? $title . '｜' : '') ?><?= View::e(App::config('app_name')) ?></title>
<link rel="stylesheet" href="<?= View::e(App::url('/assets/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="topbar-title" href="<?= View::e(App::url('/')) ?>"><?= View::e(App::config('app_name')) ?></a>

  <?php if (Auth::check()): ?>
  <nav class="mainmenu">
    <?php foreach ($menuWork as $m): ?>
      <a class="mainmenu-item<?= $isActive($m['path']) ? ' active' : '' ?>" href="<?= View::e(App::url($m['path'])) ?>"><?= View::e($m['label']) ?></a>
    <?php endforeach; ?>
    <details class="mainmenu-drop<?= $setupOpen ? ' active' : '' ?>">
      <summary>登録・確認</summary>
      <div class="drop-list">
        <?php foreach ($menuSetup as $m): ?>
          <a class="drop-item<?= $isActive($m['path']) ? ' active' : '' ?>" href="<?= View::e(App::url($m['path'])) ?>"><?= View::e($m['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </details>
  </nav>
  <?php endif; ?>

  <div class="topbar-user">
    <?php if (Auth::check()): ?>
      <span class="user-name"><?= View::e(Auth::user()['name']) ?></span>
      <a class="topbar-link" href="<?= View::e(App::url('/logout')) ?>">ログアウト</a>
    <?php endif; ?>
  </div>
</header>

<div class="layout">
  <nav class="sidemenu">
    <?php if ($flowSteps !== []): ?>
      <div class="flow">
        <div class="flow-head">
          業務の進み具合
          <span class="flow-week"><?= View::e(Clock::dayLabel($flowDate)) ?>　<?= (int)$flowDone['done'] ?>/<?= (int)$flowDone['total'] ?>済</span>
        </div>
        <?php $group = ''; ?>
        <?php foreach ($flowSteps as $step): ?>
          <?php if ($step['group'] !== $group): ?>
            <?php $group = $step['group']; ?>
            <div class="flow-group"><?= View::e($group) ?></div>
          <?php endif; ?>
          <?php $here = $flowStep === (int)$step['no']; ?>
          <a class="flow-item<?= $step['current'] ? ' current' : '' ?><?= $here ? ' here' : '' ?>" href="<?= View::e($flowHref($step)) ?>">
            <span class="flow-no"><?= (int)$step['no'] ?></span>
            <span class="flow-body">
              <span class="flow-label"><?= View::e($step['label']) ?></span>
              <span class="flow-detail"><?= View::e($step['detail']) ?><?php if ($step['current']): ?><span class="flow-next">つぎに実施</span><?php endif; ?></span>
              <?php if ($step['rate'] !== null): ?>
                <span class="bar"><span class="bar-fill" style="width:<?= (int)$step['rate'] ?>%"></span></span>
              <?php endif; ?>
            </span>
            <span class="flow-state flow-<?= View::e($step['state']) ?>"><?= View::e(Flow::STATE_LABELS[$step['state']]) ?></span>
          </a>
        <?php endforeach; ?>
        <div class="flow-note">※ 入力された内容から自動で判定しています</div>
      </div>
    <?php endif; ?>
  </nav>

  <main class="content">
    <?php foreach (($flash ?? []) as $type => $message): ?>
      <div class="alert alert-<?= View::e($type) ?>"><?= View::e($message) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>

<footer class="footer">
  <?= View::e(App::config('app_name')) ?>　第1期（MVP）　開発中の画面です　|　<?= View::e(\App\Core\Clock::dt(\App\Core\Clock::now())) ?>（日本時間）
</footer>
<script src="<?= View::e(App::url('/assets/js/app.js')) ?>"></script>
</body>
</html>
