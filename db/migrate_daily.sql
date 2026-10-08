-- 週単位 → 日単位への移行（既存DBに適用する差分。schema.sql は新規構築用に更新済み）
-- 既存の週基準データ（デモ）は日付をそのまま「対象日」として引き継ぐ。
ALTER TABLE production_plans
  CHANGE COLUMN target_week target_date DATE NOT NULL COMMENT '対象日',
  COMMENT = '日別生産計画';

ALTER TABLE part_progress
  CHANGE COLUMN target_week target_date DATE NOT NULL COMMENT '対象日',
  MODIFY planned_qty DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '必要数（その日の計画分）',
  ADD COLUMN carried_qty DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '前日から引き継いだ残り回数' AFTER planned_qty;

ALTER TABLE part_consumptions
  CHANGE COLUMN target_week target_date DATE NOT NULL COMMENT '対象日（できあがりを入れた日）',
  RENAME INDEX idx_consume_week_part TO idx_consume_date_part;

ALTER TABLE purchase_orders
  MODIFY status ENUM('draft','ordered','partial','delivered','canceled') NOT NULL DEFAULT 'draft' COMMENT '未発注/発注済/一部納品/納品済/取消',
  ADD COLUMN period_from DATE NULL COMMENT '対象期間（この日から）' AFTER order_date,
  ADD COLUMN period_to   DATE NULL COMMENT '対象期間（この日まで）' AFTER period_from;
