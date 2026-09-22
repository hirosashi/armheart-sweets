CREATE TABLE jobs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_name VARCHAR(100) NULL COMMENT '得意先',
  product_id    INT UNSIGNED NOT NULL,
  qty           INT UNSIGNED NOT NULL COMMENT '台数',
  delivery_date DATE         NOT NULL COMMENT '納品日',
  finish_date   DATE         NOT NULL COMMENT '仕上げ日（商品用資材はこの日に使う）',
  status        ENUM('open','done','canceled') NOT NULL DEFAULT 'open' COMMENT '進行中/納品済/取消',
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  updated_by    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_delivery (status, delivery_date),
  CONSTRAINT fk_job_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='発注（得意先からの注文＝つくる予定）';
CREATE TABLE job_parts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id       INT UNSIGNED NOT NULL,
  part_id      INT UNSIGNED NOT NULL,
  target_date  DATE          NOT NULL COMMENT '仕込む日',
  batches      DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'その日に仕込む回数',
  note         VARCHAR(255)  NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_parts_date (target_date, part_id),
  KEY idx_job_parts_job (job_id),
  CONSTRAINT fk_jp_job  FOREIGN KEY (job_id)  REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_jp_part FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='発注ごとの部位の仕込み割り振り（日・回数）';

ALTER TABLE purchase_orders
  ADD COLUMN job_id INT UNSIGNED NULL COMMENT 'どの発注（つくる予定）のぶんか' AFTER period_to;
