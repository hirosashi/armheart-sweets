<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;

/**
 * 発注（得意先からの注文＝案件）。
 *   jobs      … 得意先・案件名・納品日
 *   job_items … その発注で作る商品（商品・台数・仕上げ日。1件の発注に複数）
 *   job_parts … 商品ごとに、部位を「どの日に何回」仕込むか
 * 部位の回数は商品の登録時に計算して仮置きし（仕上げ日の前日）、スケジュール画面で日・回数を直す。
 * 部位の進み具合は日×部位で入るので、できた回数は納品日の早い発注から順に割り当てる（Allocation）。
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

    /** 発注1件（作る商品 items つき） */
    public static function find(int $id): ?array
    {
        $job = Db::one('SELECT * FROM jobs WHERE id = ?', [$id]);
        if ($job === null) {
            return null;
        }
        $job['items'] = self::items([$id])[$id] ?? [];
        return $job;
    }

    /** 発注の呼び名（得意先＋案件名） */
    public static function label(array $job): string
    {
        $name = (string)preg_replace('/^[\s　]+|[\s　]+$/u', '', ($job['customer_name'] ?? '') . '　' . ($job['title'] ?? ''));
        return $name !== '' ? $name : '得意先なし';
    }

    /**
     * 材料の使う予定（Allocation の needs）の行ごとの説明。
     * @return list<array{job_id:int, job:string, what:string}> needs と同じ順
     */
    public static function useLabels(array $needs): array
    {
        if ($needs === []) {
            return [];
        }
        $jobIds = array_values(array_unique(array_map(fn($n) => (int)$n['job_id'], $needs)));
        $ph     = implode(',', array_fill(0, count($jobIds), '?'));
        $jobs   = [];
        foreach (Db::all("SELECT * FROM jobs WHERE id IN ($ph)", $jobIds) as $j) {
            $jobs[(int)$j['id']] = self::label($j);
        }
        $items = [];
        foreach (self::items($jobIds) as $list) {
            foreach ($list as $it) {
                $items[(int)$it['id']] = $it['product_name'];
            }
        }
        $parts = [];
        foreach (Db::all('SELECT id, name FROM parts') as $p) {
            $parts[(int)$p['id']] = $p['name'];
        }
        return array_map(fn($n) => [
            'job_id' => (int)$n['job_id'],
            'job'    => $jobs[(int)$n['job_id']] ?? '',
            'what'   => ($items[(int)$n['item_id']] ?? '') . '　'
                      . ($n['part_id'] !== null ? ($parts[(int)$n['part_id']] ?? '') : '資材（仕上げ日）'),
        ], $needs);
    }

    /**
     * その日に仕込む部位の、発注ごとの内訳（納品日の早い順）。part_id => rows
     * rows: job_id, label, item（商品名）, delivery_date, batches, done_qty, status
     */
    public static function partBreakdown(string $date): array
    {
        $rows = Db::all(
            "SELECT jp.id, jp.part_id, jp.batches, j.id AS job_id, j.customer_name, j.title, j.delivery_date,
                    p.name AS product_name
               FROM job_parts jp
               JOIN jobs j ON j.id = jp.job_id AND j.status IN ('open','done')
               JOIN job_items ji ON ji.id = jp.job_item_id
               JOIN products p ON p.id = ji.product_id
              WHERE jp.target_date = ?
              ORDER BY j.delivery_date, j.id, jp.id",
            [$date]
        );
        $progress = Allocation::partProgress(array_values(array_unique(array_map(fn($r) => (int)$r['part_id'], $rows))));
        $out = [];
        foreach ($rows as $r) {
            $pg = $progress[(int)$r['id']] ?? ['status' => 'todo', 'done' => 0.0];
            $out[(int)$r['part_id']][] = [
                'job_id'        => (int)$r['job_id'],
                'label'         => self::label($r),
                'item'          => $r['product_name'],
                'delivery_date' => $r['delivery_date'],
                'batches'       => (float)$r['batches'],
                'done_qty'      => $pg['done'],
                'status'        => $pg['status'],
            ];
        }
        return $out;
    }

    /** 作る商品の要約（例：クロミ 20台 ほか1品） */
    public static function itemSummary(array $items): string
    {
        if ($items === []) {
            return '商品なし';
        }
        $first = $items[0]['product_name'] . ' ' . (int)$items[0]['qty'] . '台';
        return count($items) > 1 ? $first . ' ほか' . (count($items) - 1) . '品' : $first;
    }

    /** 発注ごとの作る商品。job_id => rows（仕上げ日順） */
    public static function items(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($jobIds), '?'));
        $out = [];
        foreach (Db::all(
            "SELECT ji.*, p.name AS product_name, p.spec
               FROM job_items ji JOIN products p ON p.id = ji.product_id
              WHERE ji.job_id IN ($ph)
              ORDER BY ji.job_id, ji.sort_no, ji.finish_date, ji.id",
            $jobIds
        ) as $r) {
            $out[(int)$r['job_id']][] = $r;
        }
        return $out;
    }

    /**
     * 期間に関係する発注（仕込み日〜納品日が期間にかかるもの。進行中で納品日を過ぎたものも出す。取消は除く）。
     * 納品日順。
     */
    public static function inRange(string $from, string $to, bool $withCanceled = false): array
    {
        $st = $withCanceled ? "('open','done','canceled')" : "('open','done')";
        return Db::all(
            "SELECT j.*
               FROM jobs j
              WHERE j.status IN $st
                AND LEAST(j.delivery_date,
                          IFNULL((SELECT MIN(jp.target_date) FROM job_parts jp WHERE jp.job_id = j.id), j.delivery_date),
                          IFNULL((SELECT MIN(ji.finish_date) FROM job_items ji WHERE ji.job_id = j.id), j.delivery_date)) <= ?
                AND (j.delivery_date >= ? OR j.status = 'open')
              ORDER BY j.delivery_date, j.id",
            [$to, $from]
        );
    }

    /** 進行中の発注（左メニュー用。納品日順） */
    public static function open(int $limit = 50): array
    {
        return Db::all(
            'SELECT j.* FROM jobs j
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

    /** 発注（案件）を登録する。作る商品は addItem で入れる。発注IDを返す */
    public static function create(array $in): int
    {
        return Db::insert(
            'INSERT INTO jobs (customer_name, title, delivery_date, note, created_by) VALUES (?,?,?,?,?)',
            [$in['customer_name'], $in['title'], $in['delivery_date'], $in['note'], Auth::id()]
        );
    }

    /** 作る商品を追加し、部位の仕込みを仕上げ日の前日に仮置きする。商品行のIDを返す */
    public static function addItem(int $jobId, int $productId, int $qty, string $finishDate): int
    {
        $sort = (int)Db::value('SELECT IFNULL(MAX(sort_no), 0) + 1 FROM job_items WHERE job_id = ?', [$jobId]);
        $id = Db::insert(
            'INSERT INTO job_items (job_id, product_id, qty, finish_date, sort_no) VALUES (?,?,?,?,?)',
            [$jobId, $productId, $qty, $finishDate, $sort]
        );
        self::placeParts($id);
        return $id;
    }

    /** 商品1行ぶんの部位の仮置き（その商品の既存の割り振りは消して作り直す） */
    public static function placeParts(int $itemId): void
    {
        $item = Db::one('SELECT * FROM job_items WHERE id = ?', [$itemId]);
        if ($item === null) {
            return;
        }
        Db::exec('DELETE FROM job_parts WHERE job_item_id = ?', [$itemId]);
        $day = Clock::shiftDays($item['finish_date'], -self::PREP_OFFSET);
        foreach (self::batchesFor((int)$item['product_id'], (int)$item['qty']) as $b) {
            if ((float)$b['batches'] <= 0) {
                continue;
            }
            Db::exec(
                'INSERT INTO job_parts (job_id, job_item_id, part_id, target_date, batches) VALUES (?,?,?,?,?)',
                [(int)$item['job_id'], $itemId, (int)$b['part_id'], $day, (float)$b['batches']]
            );
        }
    }

    /**
     * 発注ごとの部位の割り振り行（部位名・進み具合つき）。job_id => rows
     * 進み具合（status / done_qty）は Allocation::partProgress で納品日の早い発注から割り当てた値。
     */
    public static function partRows(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($jobIds), '?'));
        $rows = Db::all(
            "SELECT jp.*, p.name AS part_name, p.unit
               FROM job_parts jp
               JOIN parts p ON p.id = jp.part_id
              WHERE jp.job_id IN ($ph)
              ORDER BY jp.job_id, jp.job_item_id, jp.target_date, p.name, jp.id",
            $jobIds
        );
        $partIds  = array_values(array_unique(array_map(fn($r) => (int)$r['part_id'], $rows)));
        $progress = Allocation::partProgress($partIds);
        $out = [];
        foreach ($rows as $r) {
            $pg = $progress[(int)$r['id']] ?? ['status' => 'todo', 'done' => 0.0];
            $r['status']   = $pg['status'];
            $r['done_qty'] = $pg['done'];
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
}
