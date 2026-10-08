<?php
/**
 * 削除の枠。使う側で $deleteAction（POST先）・$deleteId・$deleteLabel（「この材料」など）・$usages を用意する。
 */
use App\Core\App;
use App\Core\Csrf;
use App\Core\View;
?>
<h2 class="sec-title"><?= View::e($deleteLabel) ?>を削除する</h2>
<div class="box">
<?php if ($usages !== []): ?>
  <p class="judge-short">次の所で使われているため、削除できません。先にこちらから外してください。</p>
  <ul>
    <?php foreach ($usages as $u): ?><li><?= View::e($u) ?></li><?php endforeach; ?>
  </ul>
<?php else: ?>
  <form method="post" action="<?= View::e(App::url($deleteAction)) ?>" class="inline-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int)$deleteId ?>">
    <button class="btn btn-danger"
            onclick="return confirm('<?= View::e($deleteLabel) ?>を削除しますか？ 元には戻せません。');">削除する</button>
    <span class="note">一覧や入力欄から消えます。過去の発注書・在庫の記録は残ります。</span>
  </form>
<?php endif; ?>
</div>
