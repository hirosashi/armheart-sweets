<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;

/**
 * 発注（得意先からの注文＝つくる予定）。
 *   jobs      … 得意先・商品・台数・納品日・仕上げ日
 *   job_parts … その発注のために部位を「どの日に何回」仕込むか
 * 部位の回数は登録時に計算して仮置きし（仕上げ日の前日）、スケジュール画面で日・回数を直す。
 * 達成度は job_parts の各行が part_progress（日・部位）で「できあがり」かどうかで数える。
 */
class Jobs
{
    public const STATUS_LABELS = [
        'open'     => '進行中',
        'done'     => '納品済',
        'canceled' => '取消',
    ];

    /** 仕上げ日の既定＝納品日の前日、仕込みの既定＝仕上げ日の前日 */
    public const FINISH_OFFSET = 1;
    public const PREP_OFFSET   = 1;

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT j.*, p.name AS product_name, p.spec
               FROM jobs j JOIN products p ON p.id = j.product_id
              WHERE j.id = ?',
            [$id]
        );
    }

    /**
     * 期間に関係する発注（仕込み日・仕上げ日・納品日のどれかが期間内。取消は除く）。
     * 納品日 → 商品名 の順。
     */
    public static function inRange(string $from, string $to, bool $withCanceled = false): array
    {
        $st = $withCanceled ? "('open','done','canceled')" : "('open','done')";
        return Db::all(
            "SELECT j.*, p.name AS product_name, p.spec
               FROM jobs j JOIN products p ON p.id = j.product_id
              WHERE j.status IN $st
                AND (j.finish_date BETWEEN ? AND ? OR j.delivery_date BETWEEN ? AND ?
                     OR EXISTS (SELECT 1 FROM job_parts jp WHERE jp.job_id = j.id AND jp.target_date BETWEEN ? AND ?)
                     OR (j.finish_date < ? AND j.status = 'open'))
              ORDER BY j.delivery_date, p.name, j.id",
            [$from, $to, $from, $to, $from, $to, $from]
        );
    }

    /** 進行中の発注（左メニュー用。納品日順） */
    public static function open(int $limit = 8): array
    {
        return Db::all(
            'SELECT j.*, p.name AS product_name
               FROM jobs j JOIN products p ON p.id = j.product_id
              WHERE j.status = ?
              ORDER BY j.delivery_date, j.id
              LIMIT ' . (int)$limit,
            ['open']
        );
    }

    /** 商品・台数から部位ごとの仕込み回数を計算する（Requirement と同じ式） */
    public static function batchesFor(int $productId, int $qty): array
    {
        return Db::all(
            'SELECT p.id AS part_id, p.name, p.unit, p.batch_total_qty,
                    pn.need_qty,
                    CASE WHEN p.round_batch = 1
                         THEN CEILING(pn.need_qty / p.yield_rate / p.batch_total_qty)
                         ELSE ROUND(pn.need_qty / p.yield_rate / p.batch_total_qty, 3)
                    END AS batches
               FROM (SELECT pp.part_id,
                            SUM(? * (pp.fill_qty / pp.pieces_per_fill * pp.use_pieces)) AS need_qty
                       FROM product_parts pp
                      WHERE pp.product_id = ?
                      GROUP BY pp.part_id) pn
               JOIN parts p ON p.id = pn.part_id
              WHERE p.batch_total_qty > 0 AND p.deleted_at IS NULL
              ORDER BY p.name',
            [$qty, $productId]
        );
    }

    /** 発注を登録し、部位の仕込みを仕上げ日の前日に仮置きする。発注IDを返す */
    public static function create(array $in): int
    {
        $id = Db::insert(
            'INSERT INTO jobs (customer_name, product_id, qty, delivery_date, finish_date, note, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$in['customer_name'], $in['product_id'], $in['qty'], $in['delivery_date'],
             $in['finish_date'], $in['note'], Auth::id()]
        );
        self::placeParts($id, (int)$in['product_id'], (int)$in['qty'], $in['finish_date']);
        return $id;
    }

    /** 部位の仮置き（既存の割り振りは消して作り直す） */
    public static function placeParts(int $jobId, int $productId, int $qty, string $finishDate): void
    {
        Db::exec('DELETE FROM job_parts WHERE job_id = ?', [$jobId]);
        $day = Clock::shiftDays($finishDate, -self::PREP_OFFSET);
        foreach (self::batchesFor($productId, $qty) as $b) {
            if ((float)$b['batches'] <= 0) {
                continue;
            }
            Db::exec(
                'INSERT INTO job_parts (job_id, part_id, target_date, batches) VALUES (?,?,?,?)',
                [$jobId, (int)$b['part_id'], $day, (float)$b['batches']]
            );
        }
    }

    /** 発注ごとの部位の割り振り行（part 名・進み具合つき）。job_id => rows */
    public static function partRows(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($jobIds), '?'));
        $rows = Db::all(
            "SELECT jp.*, p.name AS part_name, p.unit,
                    pg.status, pg.done_qty, pg.planned_qty AS day_planned, pg.carried_qty
               FROM job_parts jp
               JOIN parts p ON p.id = jp.part_id
               LEFT JOIN part_progress pg ON pg.target_date = jp.target_date AND pg.part_id = jp.part_id
              WHERE jp.job_id IN ($ph)
              ORDER BY jp.job_id, jp.target_date, p.name, jp.id",
            $jobIds
        );
        $out = [];
        foreach ($rows as $r) {
            $r['status'] = $r['status'] ?? 'todo';
            $out[(int)$r['job_id']][] = $r;
        }
        return $out;
    }

    /** 発注1件の達成度（部位行のうち「できあがり」の数） */
    public static function achievement(array $partRows): array
    {
        $total = count($partRows);
        $done  = 0;
        $doing = 0;
        foreach ($partRows as $r) {
            if ($r['status'] === 'done') {
                $done++;
            } elseif ($r['status'] === 'doing') {
                $doing++;
            }
        }
        return [
            'total' => $total,
            'done'  => $done,
            'doing' => $doing,
            'rate'  => $total > 0 ? (int)floor($done * 100 / $total) : 0,
        ];
    }

    /** 発注に紐づく材料の発注（仕入先への発注）。job_id => rows */
    public static function purchaseOrders(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($jobIds), '?'));
        $orders = Db::all(
            "SELECT o.id, o.job_id, o.order_no, o.status, o.order_date, o.desired_date, o.delivered_date,
                    o.period_from, o.period_to, s.name AS supplier_name,
                    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.order_id = o.id) AS item_count,
                    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.order_id = o.id AND i.received_qty >= i.qty AND i.qty > 0) AS received_count
               FROM purchase_orders o
               JOIN suppliers s ON s.id = o.supplier_id
              WHERE o.deleted_at IS NULL AND o.status <> 'canceled' AND o.job_id IN ($ph)
              ORDER BY o.job_id, o.order_date, o.id",
            $jobIds
        );
        $out = [];
        foreach ($orders as $o) {
            $o['rate'] = (int)$o['item_count'] > 0 ? (int)floor((int)$o['received_count'] * 100 / (int)$o['item_count']) : 0;
            $out[(int)$o['job_id']][] = $o;
        }
        return $out;
    }

    /** その発注の材料のうち足りないもの（件数）と、そのうち発注に載っている件数 */
    public static function shortage(int $jobId): array
    {
        $rows  = Requirement::materials('1000-01-01', '9999-12-31', $jobId);
        $short = array_values(array_filter($rows, fn($m) => $m['judge'] === 'short'));
        $ids   = array_map(fn($m) => (int)$m['id'], $short);
        $ordered = 0;
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ordered = (int)Db::value(
                "SELECT COUNT(DISTINCT i.material_id)
                   FROM purchase_order_items i JOIN purchase_orders o ON o.id = i.order_id
                  WHERE o.deleted_at IS NULL AND o.status IN ('draft','ordered','partial','delivered')
                    AND o.job_id = ? AND i.material_id IN ($ph)",
                array_merge([$jobId], $ids)
            );
        }
        return ['short' => count($short), 'ordered' => $ordered];
    }
}
