-- 「必要な材料と足りない分」画面の算出クエリ（設計検証用）
-- 配合表はバッチ（仕込み）単位で登録されているため、次の2段階で計算する。
--   1台あたり実使用量 = 充填量 ÷ 取り数 × 1台に使う個数
--   必要バッチ数       = 台数 × 1台あたり実使用量 ÷ 歩留まり ÷ バッチ合計量（既定は整数へ切り上げ）
--   材料の必要量       = 必要バッチ数 × バッチ配合量
SET @week = '2026-07-06';

WITH part_need AS (
  SELECT pp.part_id,
         SUM(pl.qty * (pp.fill_qty / pp.pieces_per_fill * pp.use_pieces)) AS need_qty
    FROM production_plans pl
    JOIN product_parts  pp ON pp.product_id = pl.product_id
   WHERE pl.target_week = @week
   GROUP BY pp.part_id
),
part_batch AS (
  SELECT pn.part_id,
         pn.need_qty,
         CASE WHEN p.round_batch = 1
              THEN CEILING(pn.need_qty / p.yield_rate / p.batch_total_qty)
              ELSE pn.need_qty / p.yield_rate / p.batch_total_qty
         END AS batches
    FROM part_need pn
    JOIN parts p ON p.id = pn.part_id
   WHERE p.batch_total_qty > 0
),
need AS (
  -- 部位（パーツ）経由の必要量
  SELECT pm.material_id, SUM(pb.batches * pm.qty) AS need_qty
    FROM part_batch     pb
    JOIN part_materials pm ON pm.part_id = pb.part_id
   GROUP BY pm.material_id
  UNION ALL
  -- 商品への直接配合（クロミ顔チョコのような「1枚/台」の材料・包材）
  SELECT prm.material_id, SUM(pl.qty * prm.qty)
    FROM production_plans   pl
    JOIN product_materials prm ON prm.product_id = pl.product_id
   WHERE pl.target_week = @week
   GROUP BY prm.material_id
),
need_sum AS (
  SELECT material_id, SUM(need_qty) AS need_qty FROM need GROUP BY material_id
),
stock AS (
  SELECT material_id, SUM(qty) AS stock_qty FROM inventory GROUP BY material_id
)
SELECT
  m.name                                   AS `材料名`,
  m.maker_name                             AS `メーカー`,
  CONCAT(FORMAT(n.need_qty,1), m.unit)     AS `必要な量`,
  CONCAT(FORMAT(IFNULL(s.stock_qty,0),1), m.unit) AS `今の在庫`,
  CONCAT(FORMAT(IFNULL(s.stock_qty,0) - n.need_qty,1), m.unit) AS `過不足`,
  CONCAT(FORMAT(m.purchase_qty,0), m.unit, '/', m.purchase_unit) AS `仕入れられる量`,
  CASE
    WHEN m.is_stock_managed = 0                               THEN '対象外'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty                  THEN '足りない'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty * m.safety_ratio THEN 'あぶない'
    ELSE '足りている'
  END                                      AS `判定`,
  CASE WHEN m.is_stock_managed = 0 OR m.purchase_qty IS NULL THEN NULL
       ELSE CEIL(GREATEST(n.need_qty - IFNULL(s.stock_qty,0),0) / m.purchase_qty)
  END                                      AS `発注数`,
  sp.name                                  AS `業者`
FROM need_sum n
JOIN materials m  ON m.id = n.material_id
LEFT JOIN stock s ON s.material_id = n.material_id
LEFT JOIN suppliers sp ON sp.id = m.supplier_id
ORDER BY FIELD(CASE
    WHEN m.is_stock_managed = 0                               THEN '対象外'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty                  THEN '足りない'
    WHEN IFNULL(s.stock_qty,0) <  n.need_qty * m.safety_ratio THEN 'あぶない'
    ELSE '足りている' END, '足りない','あぶない','足りている','対象外'), m.name;
