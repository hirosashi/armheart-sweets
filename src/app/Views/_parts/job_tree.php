<?php
/**
 * 左メニュー「発注ごと」ツリー（$tree は JobTree::build()、$here は今の画面のURL）。
 * 今の画面に当たる行は強調し、その上の行も開いておく。
 */
use App\Core\App;
use App\Core\Clock;
use App\Core\View;
use App\Services\Allocation;
use App\Services\JobTree;

$md = static fn(string $d) => (int)substr($d, 5, 2) . '/' . (int)substr($d, 8, 2);
$judgeClass = ['short' => 'tj-short', 'late' => 'tj-short', 'ordered' => 'tj-ordered', 'ok' => 'tj-ok', 'exempt' => 'tj-ok'];
$stateClass = ['todo' => 'ts-todo', 'doing' => 'ts-doing', 'done' => 'ts-done', 'late' => 'ts-late'];
$isHere = static fn(string $href) => $href === $here;
$matLine = static function (array $m) use ($md, $judgeClass, $isHere): string {
    $href = App::url('/stock/show?id=' . (int)$m['material_id']);
    $info = $m['judge'] === 'short'
        ? '足りない ' . View::num($m['short'], 1) . $m['unit']
        : ($m['judge'] === 'late' || $m['judge'] === 'ordered'
            ? '納品 ' . ($m['arrival'] !== null ? $md($m['arrival']) : '?') . '／使う ' . $md($m['use_date'])
            : View::num($m['qty'], 1) . $m['unit']);
    return '<a class="tree-row tree-mat' . ($isHere($href) ? ' here' : '') . '" href="' . View::e($href) . '">'
        . '<span class="tree-name">' . View::e($m['name']) . '</span>'
        . '<span class="tree-info">' . View::e($info) . '</span>'
        . '<span class="tree-badge ' . $judgeClass[$m['judge']] . '">' . View::e(Allocation::JUDGE_LABELS[$m['judge']]) . '</span></a>';
};
$matBlock = static function (array $mats, string $key) use ($matLine, $here): string {
    $h = '';
    foreach ($mats['alert'] as $m) {
        $h .= $matLine($m);
    }
    if ($mats['ok'] !== []) {
        $inner = '';
        $open  = false;
        foreach ($mats['ok'] as $m) {
            $line = $matLine($m);
            $open = $open || str_contains($line, ' here"');
            $inner .= $line;
        }
        $h .= '<details class="tree-more" data-key="' . View::e($key) . '"' . ($open ? ' open data-here="1"' : '') . '>'
            . '<summary>ほか' . count($mats['ok']) . '件（すべて足りる）</summary>' . $inner . '</details>';
    }
    return $h;
};
?>
<div class="tree">
  <div class="tree-head">
    進行中の発注 <?= count($tree) ?>件
    <span class="flow-week">納品日の早い順。材料は早い発注から在庫を割り当てます</span>
  </div>
  <?php if ($tree === []): ?>
    <div class="tree-empty">進行中の発注はありません。<a href="<?= View::e(App::url('/schedule?add=1#add')) ?>">発注を追加する</a></div>
  <?php endif; ?>
  <?php foreach ($tree as $j): ?>
    <?php
    $jHref = App::url('/schedule?job=' . $j['id']);
    ob_start();
    foreach ($j['items'] as $it):
        $iHref = App::url('/schedule?job=' . $j['id']) . '#item-' . $it['id'];
        ob_start();
        foreach ($it['parts'] as $pt):
            $pHref = App::url('/progress?date=' . $pt['first']);
            $mats  = $matBlock(['alert' => $pt['alert'], 'ok' => $pt['ok']], 'm' . $it['id'] . '-' . $pt['part_id']);
            $pOpen = $pt['alert'] !== [] || str_contains($mats, 'data-here');
            ?>
            <details class="tree-node tree-part" data-key="p<?= (int)$it['id'] ?>-<?= (int)$pt['part_id'] ?>"<?= $pOpen ? ' open' : '' ?><?= str_contains($mats, 'data-here') ? ' data-here="1"' : '' ?>>
              <summary><a class="tree-row" href="<?= View::e($pHref) ?>">
                <span class="tree-name"><?= View::e($pt['name']) ?></span>
                <span class="tree-info"><?= View::e($md($pt['first'])) ?> <?= View::e(View::num($pt['done'], 0)) ?>/<?= View::e(View::num($pt['batches'], 0)) ?>回</span>
                <span class="tree-badge <?= $stateClass[$pt['state']] ?>"><?= View::e(JobTree::STATE_LABELS[$pt['state']]) ?></span></a></summary>
              <?php if ($pt['state'] === 'done'): ?>
                <div class="tree-note">できあがり（材料は在庫から引き済み）</div>
              <?php elseif ($pt['alert'] === [] && $pt['ok'] === []): ?>
                <div class="tree-note">配合の材料がありません</div>
              <?php else: ?>
                <?= $mats ?>
              <?php endif; ?>
            </details>
            <?php
        endforeach;
        $sup = $it['supplies'];
        if ($sup['alert'] !== [] || $sup['ok'] !== []):
            $mats  = $matBlock($sup, 's' . $it['id']);
            $sOpen = $sup['alert'] !== [] || str_contains($mats, 'data-here');
            ?>
            <details class="tree-node tree-part" data-key="s<?= (int)$it['id'] ?>"<?= $sOpen ? ' open' : '' ?><?= str_contains($mats, 'data-here') ? ' data-here="1"' : '' ?>>
              <summary><span class="tree-row"><span class="tree-name">資材（仕上げ日に使う）</span>
                <span class="tree-info"><?= View::e($md($it['finish_date'])) ?></span>
                <?php if ($sup['alert'] !== []): ?><span class="tree-badge tj-short"><?= count($sup['alert']) ?>件</span><?php else: ?><span class="tree-badge tj-ok">足りる</span><?php endif; ?>
              </span></summary>
              <?= $mats ?>
            </details>
            <?php
        endif;
        $itemInner = (string)ob_get_clean();
        $iHere = str_contains($itemInner, 'data-here') || str_contains($itemInner, ' here"');
        ?>
        <details class="tree-node tree-item" data-key="i<?= (int)$it['id'] ?>" open<?= $iHere ? ' data-here="1"' : '' ?>>
          <summary><a class="tree-row" href="<?= View::e($iHref) ?>">
            <span class="tree-name"><?= View::e($it['product_name']) ?> <?= (int)$it['qty'] ?>台</span>
            <span class="tree-info">仕上げ <?= View::e($md($it['finish_date'])) ?> 部位<?= (int)$it['ach']['done'] ?>/<?= (int)$it['ach']['total'] ?></span>
            <span class="tree-badge <?= $stateClass[$it['state']] ?>"><?= View::e(JobTree::STATE_LABELS[$it['state']]) ?></span></a></summary>
          <?= $itemInner ?>
        </details>
        <?php
    endforeach;
    if ($j['items'] === []):
        ?><div class="tree-note">作る商品がありません</div><?php
    endif;
    $jobInner = (string)ob_get_clean();
    $jHere = $isHere($jHref);
    $jMark = $jHere || str_contains($jobInner, 'data-here') || str_contains($jobInner, ' here"');
    $jOpen = $jMark || count($tree) <= 3;
    ?>
    <details class="tree-node tree-job" data-key="j<?= (int)$j['id'] ?>"<?= $jOpen ? ' open' : '' ?><?= $jMark ? ' data-here="1"' : '' ?>>
      <summary><a class="tree-row<?= $jHere ? ' here' : '' ?>" href="<?= View::e($jHref) ?>">
        <span class="tree-name"><?= View::e($j['label']) ?></span>
        <span class="tree-info<?= $j['late'] ? ' tree-late' : '' ?>">納品 <?= View::e(Clock::dayLabel($j['delivery_date'])) ?></span>
        <?php if ($j['short'] > 0): ?><span class="tree-badge tj-short">不足<?= (int)$j['short'] ?></span><?php endif; ?>
        <span class="tree-rate"><span class="bar"><span class="bar-fill" style="width:<?= (int)$j['ach']['rate'] ?>%"></span></span><?= (int)$j['ach']['rate'] ?>%</span>
      </a></summary>
      <?= $jobInner ?>
    </details>
  <?php endforeach; ?>
  <?php if (count($tree) >= JobTree::LIMIT): ?>
    <div class="tree-note">納品日の早い <?= JobTree::LIMIT ?>件だけ出しています</div>
  <?php endif; ?>
</div>
