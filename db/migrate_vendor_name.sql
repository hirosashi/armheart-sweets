-- 材料の発注に「発注先（実際に発注する商社）」を追加する。supplier_id はメーカーとして使う。
ALTER TABLE purchase_orders
  ADD COLUMN vendor_name VARCHAR(100) NULL COMMENT '発注先（実際に発注する商社）' AFTER supplier_id;
