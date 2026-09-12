<?php
namespace App\Services;

use App\Core\Db;

/**
 * 「必要な材料と足りない分」の計算。
 * 配合表はバッチ（仕込み）単位で登録されているため、次の2段階で求める。
 *   1台あたり実使用量 = 充填量 ÷ 取り数 × 1台に使う個数
 *   必要バッチ数     = 台数 × 1台あたり実使用量 ÷ 歩留まり ÷ バッチ合計量（既定は切り上げ）
 *   材料の必要量     = 必要バッチ数 × バッチ配合量
 */
class Requirement
{
    /** 対象週の生産計画 */
    public static function plans(string $week): array
    {
        return Db::all(
            'SELECT pl.id, pl.product_id, pl.qty, p.name, p.spec
               FROM production_plans pl
               JOIN products p ON p.id = pl.product_id
              WHERE pl.target_week = ? AND p.deleted_at IS NULL
              ORDER BY p.name',
            [$week]
        );
    }

    /** 部位ごとの必要量とバッチ数 */
    public static function parts(string $week): array
    {
        return Db::all(
            'SELECT p.id, p.name, p.unit, p.batch_total_qty, p.yield_rate, p.round_batch,
                    pn.need_qty,
                    CASE WHEN p.round_batch = 1
                         THEN CEILING(pn.need_qty / p.yield_rate / p.batch_total_qty)
                         ELSE pn.need_qty / p.yield_rate / p.batch_total_qty
                    END AS batches
               FROM (SELECT pp.part_id,
                            SUM(pl.qty * (pp.fill_qty / pp.pieces_per_fill * pp.use_pieces)) AS need_qty
                       FROM production_plans pl
                       JOIN product_parts pp ON pp.product_id = pl.product_id
                      WHERE pl.target_week = ?
                      GROUP BY pp.part_id) pn
               JOIN parts p ON p.id = pn.part_id
              WHERE p.batch_total_qty > 0 AND p.deleted_at IS NULL
              ORDER BY p.name',
            [$week]
        );
    }

    /** 材料ごとの必要量・在庫・過不足・発注数 */
    public static function materials(string $week): array
    {
        $sql = <<<'SQL'
WITH part_need AS (
  SELECT pp.part_id,
         SUM(pl.qty * (pp.fill_qty / pp.pieces_per_fill * pp.use_pieces)) AS need_qty
    FROM production_plans pl
    JOIN product_parts pp ON pp.product_id = pl.product_id
   WHERE pl.target_week = :week1
   GROUP BY pp.part_id
),
part_batch AS (
  SELECT pn.part_id,
         CASE WHEN p.round_batch = 1
              THEN CEILING(pn.need_qty / p.yield_rate / p.batch_total_qty)
              ELSE pn.need_qty / p.yield_rate / p.batch_total_qty
         END AS batches
    FROM part_need pn
    JOIN parts p ON p.id = pn.part_id
   WHERE p.batch_total_qty > 0 AND p.deleted_at IS NULL
),
need AS (
  SELECT pm.material_id, SUM(pb.batches * pm.qty) AS need_qty
    FROM part_batch pb
    JOIN part_materials pm ON pm.part_id = pb.part_id
   GROUP BY pm.material_id
  UNION ALL
  SELECT prm.material_id, SUM(pl.qty * prm.qty)
    FROM production_plans pl
    JOIN product_materials prm ON prm.product_id = pl.product_id
   WHERE pl.target_week = :week2
   GROUP BY prm.material_id
),
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
        return Db::all($sql, ['week1' => $week, 'week2' => $week]);
    }

    public const JUDGE_LABELS = [
        'short'  => '足りない',
        'tight'  => 'あぶない',
        'ok'     => '足りている',
        'exempt' => '対象外',
    ];
}
