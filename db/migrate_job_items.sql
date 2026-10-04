-- 1件の発注（案件）に複数の商品を入れられるようにする。
-- jobs の商品・台数・仕上げ日を job_items へ移し、job_parts をどの商品のための仕込みかに紐づける。
ALTER TABLE jobs ADD COLUMN title VARCHAR(100) NULL COMMENT '案件名' AFTER customer_name;

CREATE TABLE job_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id       INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  qty          INT UNSIGNED NOT NULL COMMENT '台数',
  finish_date  DATE         NOT NULL COMMENT '仕上げ日（商品用資材はこの日に使う）',
  sort_no      INT          NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_items_job (job_id),
  KEY idx_job_items_finish (finish_date),
  CONSTRAINT fk_ji_job     FOREIGN KEY (job_id)     REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_ji_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='発注で作る商品（1件の発注に複数）';

INSERT INTO job_items (job_id, product_id, qty, finish_date, sort_no)
SELECT id, product_id, qty, finish_date, 1 FROM jobs;

ALTER TABLE job_parts ADD COLUMN job_item_id INT UNSIGNED NULL COMMENT 'どの商品のための仕込みか' AFTER job_id;
UPDATE job_parts jp JOIN job_items ji ON ji.job_id = jp.job_id SET jp.job_item_id = ji.id;
ALTER TABLE job_parts
  MODIFY job_item_id INT UNSIGNED NOT NULL COMMENT 'どの商品のための仕込みか',
  ADD KEY idx_job_parts_item (job_item_id),
  ADD CONSTRAINT fk_jp_item FOREIGN KEY (job_item_id) REFERENCES job_items(id) ON DELETE CASCADE;

ALTER TABLE jobs
  MODIFY product_id  INT UNSIGNED NULL COMMENT '旧列（未使用。job_items へ移行済み）',
  MODIFY qty         INT UNSIGNED NULL COMMENT '旧列（未使用。job_items へ移行済み）',
  MODIFY finish_date DATE         NULL COMMENT '旧列（未使用。job_items へ移行済み）';
