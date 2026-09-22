<?php
namespace App\Services;

use App\Core\Db;

/**
 * 「必要な材料と足りない分」の計算。
 * 配合表はバッチ（仕込み）単位で登録されているため、次の2段階で求める。
 *   1台あたり実使用量 = 充填量 ÷ 取り数 × 1台に使う個数
 *   必要バッチ数     = 台数 × 1台あたり実使用量 ÷ 歩留まり ÷ バッチ合計量（既定は切り上げ）
 *   材料の必要量     = 必要バッチ数 × バッチ配合量
 * 仕込みの回数は「発注（jobs）」ごとに部位を日へ割り振った job_parts が正本。期間の合計はその和とする。
 * 商品用資材（product_materials）は発注の仕上げ日（jobs.finish_date）に使う。
 * 期間は from〜to（両端を含む）。1日ぶんは from = to で指定する。$jobId を渡すと1件の発注に絞る。
 */
class Requirement
{
    /** 既定の先読み期間（日） */
    public const DEFAULT_DAYS = 7;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 31;

    /** 画面から受けた先読み日数を 1〜31 に整える */
    public static function normalizeDays($value): int
    {
        $n = (int)$value;
        if ($n < self::MIN_DAYS) {
            return self::DEFAULT_DAYS;
        }
        return min($n, self::MAX_DAYS);
    }

    /** 発注の絞り込み条件（$jobId が null なら全件） */
    private static function jobFilter(?int $jobId, string $alias): array
    {
        return $jobId !== null
            ? [" AND $alias.job_id = ? ", [$jobId]]
            : [' ', []];
    }

    /** 期間に仕上げる発注（日付・商品ごと。target_date ＝ 仕上げ日） */
    public static function plans(string $from, string $to, ?int $jobId = null): array
    {
        $w = $jobId !== null ? ' AND j.id = ? ' : ' ';
        $p = $jobId !== null ? [$jobId] : [];
        return Db::all(
            "SELECT j.id, j.finish_date AS target_date, j.delivery_date, j.customer_name,
                    j.product_id, j.qty, p.name, p.spec, j.status
               FROM jobs j
               JOIN products p ON p.id = j.product_id
              WHERE j.status IN ('open','done') AND j.finish_date BETWEEN ? AND ? AND p.deleted_at IS NULL $w
              ORDER BY j.finish_date, p.name, j.id",
            array_merge([$from, $to], $p)
        );
    }

    /** 部位ごとの仕込み回数（日ごと）。target_date, 部位名 順。need_qty は回数×1バッチ量の目安 */
    public static function partsByDay(string $from, string $to, ?int $jobId = null): array
    {
        [$w, $p] = self::jobFilter($jobId, 'jp');
        return Db::all(
            "SELECT jp.target_date, p.id, p.name, p.unit, p.batch_total_qty, p.yield_rate, p.round_batch,
                    SUM(jp.batches) * p.batch_total_qty AS need_qty,
                    SUM(jp.batches) AS batches
               FROM job_parts jp
               JOIN jobs j ON j.id = jp.job_id AND j.status IN ('open','done')
               JOIN parts p ON p.id = jp.part_id
              WHERE jp.target_date BETWEEN ? AND ? AND p.deleted_at IS NULL $w
              GROUP BY jp.target_date, p.id
              ORDER BY jp.target_date, p.name",
            array_merge([$from, $to], $p)
        );
    }

    /** 1日ぶんの部位の必要量とバッチ数 */
    public static function parts(string $date): array
    {
        return self::partsByDay($date, $date);
    }

    /** 部位ごとの期間合計（日ごとに切り上げたバッチ数の和） */
    public static function partsTotal(string $from, string $to, ?int $jobId = null): array
    {
        $out = [];
        foreach (self::partsByDay($from, $to, $jobId) as $r) {
            $id = (int)$r['id'];
            if (!isset($out[$id])) {
                $out[$id] = $r;
                $out[$id]['need_qty'] = 0.0;
                $out[$id]['batches']  = 0.0;
                $out[$id]['days']     = [];
            }
            $out[$id]['need_qty'] += (float)$r['need_qty'];
            $out[$id]['batches']  += (float)$r['batches'];
            $out[$id]['days'][$r['target_date']] = (float)$r['batches'];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * 期間の材料必要量を材料単位で集計する共通CTE。
     * 部位の配合 … job_parts の回数 × バッチ配合量（仕込む日）
     * 商品用資材 … 発注の台数 × 1台あたり量（仕上げ日）
     */
    private static function needSql(?int $jobId): string
    {
        $w = $jobId !== null ? ' AND j.id = :job' : '';
        $w2 = $jobId !== null ? ' AND j.id = :job2' : '';
        return <<<SQL
WITH need AS (
  SELECT jp.target_date, pm.material_id, SUM(jp.batches * pm.qty) AS need_qty
    FROM job_parts jp
    JOIN jobs j ON j.id = jp.job_id AND j.status IN ('open','done')
    JOIN parts p ON p.id = jp.part_id AND p.deleted_at IS NULL
    JOIN part_materials pm ON pm.part_id = jp.part_id
   WHERE jp.target_date BETWEEN :from1 AND :to1 $w
   GROUP BY jp.target_date, pm.material_id
  UNION ALL
  SELECT j.finish_date, prm.material_id, SUM(j.qty * prm.qty)
    FROM jobs j
    JOIN product_materials prm ON prm.product_id = j.product_id
   WHERE j.status IN ('open','done') AND j.finish_date BETWEEN :from2 AND :to2 $w2
   GROUP BY j.finish_date, prm.material_id
)
SQL;
    }

    private static function needParams(string $from, string $to, ?int $jobId): array
    {
        $p = ['from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to];
        if ($jobId !== null) {
            $p['job']  = $jobId;
            $p['job2'] = $jobId;
        }
        return $p;
    }

    /** 材料ごとの必要量・在庫・過不足・発注数（期間合計） */
    public static function materials(string $from, string $to, ?int $jobId = null): array
    {
        $sql = self::needSql($jobId) . <<<'SQL'
,
need_sum AS (
  SELECT material_id, SUM(need_qty) AS need_qty FROM need GROUP BY material_id
),
stock AS (
  SELECT material_id, SUM(qty) AS stock_qty FROM inventory GROUP BY material_id
)
SELECT
  m.id, m.name, m.maker_name, m.unit, m.purchase_unit, m.purchase_qty,
  m.is_stock_managed, m.is_supplied, m.kind,
  n.need_qty,
  IFNULL(s.stock_qty, 0) AS stock_qty,
  IFNULL(s.stock_qty, 0) - n.need_qty AS diff_qty,
  CASE
    WHEN m.is_stock_managed = 0                               THEN 'exempt'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty                  THEN 'short'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty * m.safety_ratio THEN 'tight'
    ELSE 'ok'
  END AS judge,
  CASE WHEN m.is_stock_managed = 0 OR m.purchase_qty IS NULL OR m.purchase_qty <= 0 THEN NULL
       ELSE CEIL(GREATEST(n.need_qty - IFNULL(s.stock_qty,0), 0) / m.purchase_qty)
  END AS order_qty,
  m.supplier_id, sp.name AS supplier_name
FROM need_sum n
JOIN materials m ON m.id = n.material_id
LEFT JOIN stock s ON s.material_id = n.material_id
LEFT JOIN suppliers sp ON sp.id = m.supplier_id
WHERE m.deleted_at IS NULL
ORDER BY FIELD(CASE
    WHEN m.is_stock_managed = 0                               THEN 'exempt'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty                  THEN 'short'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty * m.safety_ratio THEN 'tight'
    ELSE 'ok' END, 'short','tight','ok','exempt'), m.name
SQL;
        return Db::all($sql, self::needParams($from, $to, $jobId));
    }

    /**
     * 材料ごと・日ごとの必要量。
     * @return array<int, array<string, float>>  material_id => [target_date => need_qty]
     */
    public static function materialsByDay(string $from, string $to, ?int $jobId = null): array
    {
        $sql = self::needSql($jobId) . <<<'SQL'

SELECT material_id, target_date, SUM(need_qty) AS need_qty
  FROM need
 GROUP BY material_id, target_date
SQL;
        $out = [];
        foreach (Db::all($sql, self::needParams($from, $to, $jobId)) as $r) {
            $out[(int)$r['material_id']][$r['target_date']] = (float)$r['need_qty'];
        }
        return $out;
    }

    /** 期間内で足りない材料の件数と、そのうち発注に載っている件数（左メニューの達成度用） */
    public static function shortSummary(string $from, string $to): array
    {
        $short = array_filter(self::materials($from, $to), fn($m) => $m['judge'] === 'short');
        $ids   = array_map(fn($m) => (int)$m['id'], $short);
        $ordered = 0;
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ordered = (int)Db::value(
                "SELECT COUNT(DISTINCT i.material_id)
                   FROM purchase_order_items i
                   JOIN purchase_orders o ON o.id = i.order_id
                  WHERE o.deleted_at IS NULL AND o.status IN ('ordered','partial')
                    AND i.material_id IN ($ph)",
                $ids
            );
        }
        return ['short' => count($short), 'ordered' => $ordered];
    }

    public const JUDGE_LABELS = [
        'short'  => '足りない',
        'tight'  => 'あぶない',
        'ok'     => '足りている',
        'exempt' => '対象外',
    ];
}
