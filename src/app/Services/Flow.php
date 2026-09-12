<?php
namespace App\Services;

use App\Core\Clock;
use App\Core\Db;

/**
 * 業務の流れ（手順）と、その手順が済んでいるかの判定。
 * 左の「工程フロー」に表示する。
 *
 * 状態は3つ。
 *   done    … 済（入力・確認が終わっている）
 *   partial … 途中（一部だけ終わっている）
 *   todo    … 未実施
 */
class Flow
{
    public const GROUP_SETUP = 'はじめの準備';
    public const GROUP_WEEK  = '今週の流れ';

    public const STATE_LABELS = [
        'done'    => '済',
        'partial' => '途中',
        'todo'    => '未実施',
    ];

    /** 同じ画面表示中に何度も数え直さないための控え */
    private static array $cache = [];

    /**
     * 手順の一覧を返す。
     * 各要素: no / label / path / group / state / detail / current
     */
    public static function steps(?string $week = null): array
    {
        $week = $week !== null && $week !== '' ? $week : Clock::weekStart();
        if (isset(self::$cache[$week])) {
            return self::$cache[$week];
        }
        $steps = array_merge(self::setupSteps(), self::weeklySteps($week));

        $no = 1;
        foreach ($steps as $i => $step) {
            $steps[$i]['no']      = $no++;
            $steps[$i]['current'] = false;
        }

        // いま実施すべき手順＝今週の流れのうち、最初の「済」でない手順
        foreach ($steps as $i => $step) {
            if ($step['group'] === self::GROUP_WEEK && $step['state'] !== 'done') {
                $steps[$i]['current'] = true;
                break;
            }
        }
        self::$cache[$week] = $steps;
        return $steps;
    }

    /** 済んだ手順の数と全体数 */
    public static function summary(array $steps): array
    {
        $done = 0;
        foreach ($steps as $step) {
            if ($step['state'] === 'done') {
                $done++;
            }
        }
        return ['done' => $done, 'total' => count($steps)];
    }

    /** はじめの準備（最初に1回だけ整える手順） */
    private static function setupSteps(): array
    {
        $materials = (int)Db::value('SELECT COUNT(*) FROM materials WHERE deleted_at IS NULL');

        $parts     = (int)Db::value('SELECT COUNT(*) FROM parts WHERE deleted_at IS NULL');
        $partsNg   = (int)Db::value('SELECT COUNT(*) FROM parts WHERE deleted_at IS NULL AND batch_total_qty <= 0');

        $products  = (int)Db::value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL');
        $productNg = (int)Db::value(
            'SELECT COUNT(*) FROM products p
              WHERE p.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM product_parts pp WHERE pp.product_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM product_materials pm WHERE pm.product_id = p.id)'
        );

        // 在庫を入れる対象は「配合に使っていて、在庫管理する（支給品でない）材料」に絞る
        $stockWhere = 'FROM materials m
                        WHERE m.deleted_at IS NULL AND m.is_stock_managed = 1 AND m.is_supplied = 0
                          AND (EXISTS (SELECT 1 FROM part_materials pm WHERE pm.material_id = m.id)
                            OR EXISTS (SELECT 1 FROM product_materials pr WHERE pr.material_id = m.id))';
        $stockTargets = (int)Db::value('SELECT COUNT(*) ' . $stockWhere);
        $stockEntered = (int)Db::value(
            'SELECT COUNT(*) ' . $stockWhere . '
                          AND EXISTS (SELECT 1 FROM inventory iv WHERE iv.material_id = m.id)'
        );

        return [
            [
                'group'  => self::GROUP_SETUP,
                'label'  => '原材料・資材を確認',
                'path'   => '/materials',
                'state'  => $materials > 0 ? 'done' : 'todo',
                'detail' => $materials . '件 登録',
            ],
            [
                'group'  => self::GROUP_SETUP,
                'label'  => '1バッチの配合・歩留まりを確認',
                'path'   => '/parts',
                'state'  => self::state($parts > 0 && $partsNg === 0, $parts > 0),
                'detail' => $partsNg > 0
                    ? $parts . '件中 ' . $partsNg . '件がバッチ量なし'
                    : $parts . '件 登録',
            ],
            [
                'group'  => self::GROUP_SETUP,
                'label'  => '部位・資材の使い方を確認',
                'path'   => '/products',
                'state'  => self::state($products > 0 && $productNg === 0, $products > 0),
                'detail' => $productNg > 0
                    ? $products . '件中 ' . $productNg . '件が配合なし'
                    : $products . '件 登録',
            ],
            [
                'group'  => self::GROUP_SETUP,
                'label'  => '今ある数量を入力',
                'path'   => '/stock',
                'state'  => self::state($stockEntered >= $stockTargets && $stockTargets > 0, $stockEntered > 0),
                'detail' => $stockTargets > 0
                    ? $stockEntered . '／' . $stockTargets . '件 入力済み'
                    : '対象なし',
            ],
        ];
    }

    /** 毎週の流れ */
    private static function weeklySteps(string $week): array
    {
        $weekEnd = Clock::shiftWeek($week, 1);

        $planQty  = (int)Db::value('SELECT IFNULL(SUM(qty),0) FROM production_plans WHERE target_week = ?', [$week]);
        $partCnt  = $planQty > 0 ? count(Requirement::parts($week)) : 0;

        // 材料の判定は重い計算なので、つくる数が入っているときだけ数える
        $shortCnt = 0;
        if ($planQty > 0) {
            foreach (Requirement::materials($week) as $m) {
                if ($m['judge'] === 'short') {
                    $shortCnt++;
                }
            }
        }

        $orders = Db::one(
            "SELECT COUNT(*) AS cnt,
                    SUM(status = 'draft')     AS draft,
                    SUM(status = 'ordered')   AS ordered,
                    SUM(status = 'delivered') AS delivered
               FROM purchase_orders
              WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?",
            [$week . ' 00:00:00', $weekEnd . ' 00:00:00']
        ) ?? ['cnt' => 0, 'draft' => 0, 'ordered' => 0, 'delivered' => 0];

        $orderCnt  = (int)$orders['cnt'];
        $draft     = (int)$orders['draft'];
        $ordered   = (int)$orders['ordered'];
        $delivered = (int)$orders['delivered'];

        $orderedMaterials = (int)Db::value(
            'SELECT COUNT(DISTINCT i.material_id)
               FROM purchase_order_items i
               JOIN purchase_orders o ON o.id = i.order_id
              WHERE o.deleted_at IS NULL AND o.created_at >= ? AND o.created_at < ?',
            [$week . ' 00:00:00', $weekEnd . ' 00:00:00']
        );

        $progDone = (int)Db::value(
            "SELECT COUNT(*) FROM part_progress WHERE target_week = ? AND status = 'done'",
            [$week]
        );
        $progDoing = (int)Db::value(
            "SELECT COUNT(*) FROM part_progress WHERE target_week = ? AND status <> 'todo'",
            [$week]
        );

        $adjust = (int)Db::value(
            'SELECT COUNT(*) FROM inventory_adjustments WHERE created_at >= ? AND created_at < ?',
            [$week . ' 00:00:00', $weekEnd . ' 00:00:00']
        );

        return [
            [
                'group'  => self::GROUP_WEEK,
                'label'  => 'つくる数を入力',
                'path'   => '/require#plan',
                'state'  => $planQty > 0 ? 'done' : 'todo',
                'detail' => $planQty > 0 ? '合計 ' . number_format($planQty) . '台' : 'つくる数が未入力',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '部位ごとの仕込み回数を確認',
                'path'   => '/require#batch',
                'state'  => $planQty > 0 && $partCnt > 0 ? 'done' : 'todo',
                'detail' => $partCnt > 0 ? $partCnt . '部位の回数を計算済み' : '計算するとここに出ます',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '足りない材料をチェックして発注に追加',
                'path'   => '/require#short',
                'state'  => self::state(
                    $planQty > 0 && ($shortCnt === 0 || $orderedMaterials >= $shortCnt),
                    $orderedMaterials > 0
                ),
                'detail' => $shortCnt > 0
                    ? '足りない ' . $shortCnt . '件／発注に入れた ' . $orderedMaterials . '件'
                    : ($planQty > 0 ? '足りない材料はありません' : 'つくる数の入力後に出ます'),
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '内容と希望納期を確認',
                'path'   => '/orders?status=draft',
                'state'  => self::state($orderCnt > 0 && $draft === 0, $orderCnt > 0),
                'detail' => $orderCnt > 0 ? '未発注 ' . $draft . '件' : '今週の発注はまだありません',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '発注書を印刷して送り、発注済にする',
                'path'   => '/orders?status=draft',
                'state'  => self::state($orderCnt > 0 && $draft === 0, $ordered + $delivered > 0),
                'detail' => $orderCnt > 0
                    ? '発注済 ' . ($ordered + $delivered) . '／' . $orderCnt . '件'
                    : '今週の発注はまだありません',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '担当とできた数を入力',
                'path'   => '/progress',
                'state'  => self::state($partCnt > 0 && $progDone >= $partCnt, $progDoing > 0),
                'detail' => $partCnt > 0
                    ? 'できあがり ' . $progDone . '／' . $partCnt . '部位'
                    : '今週つくる部位がありません',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '入荷・使った分を直す',
                'path'   => '/stock',
                'state'  => $adjust > 0 ? 'done' : 'todo',
                'detail' => $adjust > 0 ? '今週 ' . $adjust . '件 調整' : '今週の調整はまだありません',
            ],
            [
                'group'  => self::GROUP_WEEK,
                'label'  => '届いた発注を納品済にする',
                'path'   => '/orders?status=ordered',
                'state'  => self::state($orderCnt > 0 && $ordered === 0 && $delivered > 0, $delivered > 0),
                'detail' => $orderCnt > 0
                    ? '納品済 ' . $delivered . '／' . $orderCnt . '件'
                    : '今週の発注はまだありません',
            ],
        ];
    }

    private static function state(bool $done, bool $partial): string
    {
        if ($done) {
            return 'done';
        }
        return $partial ? 'partial' : 'todo';
    }
}
