<?php
namespace App\Services;

use App\Core\Clock;
use App\Core\Db;

/**
 * 左メニューに出す「業務の進み具合」。
 *   はじめの準備 … マスタが整っているか（最初に1回）
 *   今日の状況   … つくる数・仕込み・足りない材料・賞味期限（日単位、足りない材料は先読み期間）
 *   進行中の発注 … 発注1件ごとの納品率（発注は1日で終わらないので「済／未」ではなく達成度で追う）
 *
 * 状態は3つ。
 *   done    … 済（入力・確認が終わっている／全部納品）
 *   partial … 途中
 *   todo    … 未実施
 */
class Flow
{
    public const GROUP_SETUP  = 'はじめの準備';
    public const GROUP_TODAY  = '今日の状況';
    public const GROUP_ORDERS = '進行中の発注';

    public const STATE_LABELS = [
        'done'    => '済',
        'partial' => '途中',
        'todo'    => '未実施',
    ];

    /** 賞味期限が「近い」とみなす日数 */
    public const EXPIRY_SOON_DAYS = 7;

    /** 同じ画面表示中に何度も数え直さないための控え */
    private static array $cache = [];

    /**
     * 項目の一覧を返す。
     * 各要素: no / label / path / group / state / detail / rate(0-100 or null) / current
     */
    public static function steps(?string $date = null): array
    {
        $date = $date !== null && $date !== '' ? $date : Clock::today();
        if (isset(self::$cache[$date])) {
            return self::$cache[$date];
        }
        $steps = array_merge(self::setupSteps(), self::todaySteps($date), self::orderSteps());

        $no = 1;
        foreach ($steps as $i => $step) {
            $steps[$i]['no']      = $no++;
            $steps[$i]['current'] = false;
            $steps[$i]['rate']    = $step['rate'] ?? null;
        }

        // いま実施すべき手順＝今日の状況のうち、最初の「済」でない項目
        foreach ($steps as $i => $step) {
            if ($step['group'] === self::GROUP_TODAY && $step['state'] !== 'done') {
                $steps[$i]['current'] = true;
                break;
            }
        }
        self::$cache[$date] = $steps;
        return $steps;
    }

    /** 済んだ項目の数と全体数 */
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
                'rate'   => $stockTargets > 0 ? self::rate($stockEntered, $stockTargets) : null,
            ],
        ];
    }

    /** 今日の状況（日単位。足りない材料は先読み期間ぶん） */
    private static function todaySteps(string $date): array
    {
        $days = Requirement::DEFAULT_DAYS;
        $to   = Clock::rangeEnd($date, $days);

        $planToday  = (int)Db::value('SELECT IFNULL(SUM(qty),0) FROM production_plans WHERE target_date = ?', [$date]);
        $planPeriod = (int)Db::value('SELECT IFNULL(SUM(qty),0) FROM production_plans WHERE target_date BETWEEN ? AND ?', [$date, $to]);

        $prog = Progress::summary($date);

        // 材料の判定は重い計算なので、つくる数が入っているときだけ数える
        $short = ['short' => 0, 'ordered' => 0];
        if ($planPeriod > 0) {
            $short = Requirement::shortSummary($date, $to);
        }

        $expiring = (int)Db::value(
            'SELECT COUNT(*) FROM inventory iv JOIN materials m ON m.id = iv.material_id
              WHERE iv.qty > 0 AND m.deleted_at IS NULL AND iv.expiry_date IS NOT NULL AND iv.expiry_date <= ?',
            [Clock::shiftDays($date, self::EXPIRY_SOON_DAYS)]
        );

        return [
            [
                'group'  => self::GROUP_TODAY,
                'label'  => 'つくる数を入力',
                'path'   => '/schedule',
                'state'  => $planToday > 0 ? 'done' : ($planPeriod > 0 ? 'partial' : 'todo'),
                'detail' => $planPeriod > 0
                    ? '今日 ' . number_format($planToday) . '台／' . $days . '日間 ' . number_format($planPeriod) . '台'
                    : 'つくる数が未入力',
            ],
            [
                'group'  => self::GROUP_TODAY,
                'label'  => '今日の仕込み',
                'path'   => '/progress',
                'state'  => self::state($prog['total'] > 0 && $prog['done'] >= $prog['total'], $prog['done'] > 0),
                'detail' => $prog['total'] > 0
                    ? 'できあがり ' . $prog['done'] . '／' . $prog['total'] . '部位'
                      . ($prog['carried'] > 0 ? '（前日から ' . $prog['carried'] . '）' : '')
                    : '今日つくる部位がありません',
                'rate'   => $prog['total'] > 0 ? self::rate($prog['done'], $prog['total']) : null,
            ],
            [
                'group'  => self::GROUP_TODAY,
                'label'  => '足りない材料（' . $days . '日先読み）',
                'path'   => '/require#short',
                'state'  => self::state(
                    $planPeriod > 0 && ($short['short'] === 0 || $short['ordered'] >= $short['short']),
                    $short['ordered'] > 0
                ),
                'detail' => $short['short'] > 0
                    ? '不足 ' . $short['short'] . '件／うち発注済 ' . $short['ordered'] . '件'
                    : ($planPeriod > 0 ? '足りない材料はありません' : 'つくる数の入力後に出ます'),
                'rate'   => $short['short'] > 0 ? self::rate($short['ordered'], $short['short']) : null,
            ],
            [
                'group'  => self::GROUP_TODAY,
                'label'  => '賞味期限が近い在庫',
                'path'   => '/stock',
                'state'  => $expiring === 0 ? 'done' : 'partial',
                'detail' => $expiring > 0
                    ? $expiring . 'ロットが' . self::EXPIRY_SOON_DAYS . '日以内に期限'
                    : '期限が近いものはありません',
            ],
        ];
    }

    /** 進行中の発注（1件ごとの納品率） */
    private static function orderSteps(): array
    {
        $out = [];
        foreach (Orders::open(8) as $o) {
            $isDraft = $o['status'] === 'draft';
            $out[] = [
                'group'  => self::GROUP_ORDERS,
                'label'  => $o['supplier_name'],
                'path'   => '/orders/show?id=' . $o['id'],
                'state'  => $isDraft ? 'todo' : ($o['received_count'] > 0 ? 'partial' : 'todo'),
                'detail' => $isDraft
                    ? '未発注　' . $o['item_count'] . '品目'
                    : '納品 ' . $o['received_count'] . '／' . $o['item_count'] . '品目'
                      . ($o['desired_date'] ? '　納期 ' . Clock::dayLabel($o['desired_date']) : ''),
                'rate'   => $isDraft ? 0 : $o['rate'],
            ];
        }
        if ($out === []) {
            $out[] = [
                'group'  => self::GROUP_ORDERS,
                'label'  => '進行中の発注はありません',
                'path'   => '/orders',
                'state'  => 'done',
                'detail' => '納品済・取消以外の発注がここに並びます',
            ];
        }
        return $out;
    }

    private static function state(bool $done, bool $partial): string
    {
        if ($done) {
            return 'done';
        }
        return $partial ? 'partial' : 'todo';
    }

    private static function rate(int $done, int $total): int
    {
        return $total > 0 ? (int)floor(min($done, $total) * 100 / $total) : 0;
    }
}
