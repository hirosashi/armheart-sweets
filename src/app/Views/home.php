<?php
use App\Core\App;
use App\Core\Auth;
use App\Core\View;
$title = 'ホーム';
?>
<h1 class="page-title">ホーム</h1>
<p class="page-lead">上のメニューから、やりたいことを選んでください。左の工程フローで、いま進めるべき作業と抜けを確認できます。</p>

<div class="card-grid">
  <a class="card" href="<?= View::e(App::url('/require')) ?>">
    <div class="card-head">🧮 必要な材料と足りない分</div>
    <div class="card-body">スケジュールで登録した発注（つくる予定）から、材料が何をどれだけ買えばよいかがわかります。</div>
  </a>
  <a class="card" href="<?= View::e(App::url('/orders')) ?>">
    <div class="card-head">📨 発注の管理</div>
    <div class="card-body">発注書をつくって印刷できます。<?php if ($counts['draft_orders'] > 0): ?>
      <strong class="text-red">まだ発注していないものが <?= (int)$counts['draft_orders'] ?> 件あります。</strong><?php endif; ?></div>
  </a>
  <a class="card" href="<?= View::e(App::url('/stock')) ?>">
    <div class="card-head">📦 材料の在庫</div>
    <div class="card-body">在庫と賞味期限を確認できます。<?php if ($counts['expiring'] > 0): ?>
      <strong class="text-red">期限が近い材料が <?= (int)$counts['expiring'] ?> 件あります。</strong><?php endif; ?></div>
  </a>
  <a class="card" href="<?= View::e(App::url('/products')) ?>">
    <div class="card-head">🍰 商品とレシピ</div>
    <div class="card-body">商品ごとの材料の分量を登録します。登録した商品：<?= (int)$counts['products'] ?> 件</div>
  </a>
</div>

<h2 class="sec-title">ご自身の設定</h2>
<table class="table table-narrow">
  <tbody>
    <tr><th>お名前</th><td><?= View::e(Auth::user()['name']) ?>（<?= View::e(Auth::roleLabel()) ?>）</td></tr>
    <tr><th>パスワード</th><td><a href="<?= View::e(App::url('/password')) ?>">パスワードを変更する</a></td></tr>
  </tbody>
</table>

<h2 class="sec-title">いま登録されている情報</h2>
<table class="table table-narrow">
  <tbody>
    <tr><th>商品</th><td><?= (int)$counts['products'] ?> 件</td></tr>
    <tr><th>パーツ</th><td><?= (int)$counts['parts'] ?> 件</td></tr>
    <tr><th>材料</th><td><?= (int)$counts['materials'] ?> 件</td></tr>
    <tr><th>仕入先</th><td><?= (int)$counts['suppliers'] ?> 件</td></tr>
    <tr><th>今日</th><td><?= View::e(View::d($week_start)) ?>（<a href="<?= View::e(App::url('/schedule')) ?>">今週のスケジュールを見る</a>）</td></tr>
  </tbody>
</table>

<h2 class="sec-title">時刻の確認（日本時間）</h2>
<table class="table table-narrow">
  <tbody>
    <tr><th>システムの時計</th><td><?= View::e($server_time) ?></td></tr>
    <tr><th>データベースの時計</th><td><?= View::e($db_time) ?></td></tr>
  </tbody>
</table>
<p class="note">※ 2つの時刻は必ず一致します（日本時間で統一）。ズレていたらご連絡ください。</p>
<p class="note">※ 各画面はこれから順に作っていきます。この画面は動作確認用の入口です。</p>
