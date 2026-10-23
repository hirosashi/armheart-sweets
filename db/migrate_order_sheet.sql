-- 原材料発注一覧（Excelと同じ並びのかんたん画面）用の追加。既存の列・データは変えない（supplier_id を空でもよくするだけ）。
ALTER TABLE materials
  ADD COLUMN vendor_name VARCHAR(100) NULL COMMENT '発注先（いつも発注する商社）' AFTER supplier_id,
  ADD COLUMN storage     VARCHAR(10)  NULL COMMENT '保管配送（冷凍/冷蔵/常温）' AFTER vendor_name;

ALTER TABLE product_parts
  ADD COLUMN batch_pieces DECIMAL(12,3) NULL COMMENT '1Bでできる数（原材料発注一覧で使う）' AFTER use_pieces;

ALTER TABLE purchase_orders
  MODIFY supplier_id INT UNSIGNED NULL COMMENT 'メーカー',
  ADD COLUMN sheet_id      INT UNSIGNED NULL COMMENT 'どの原材料発注一覧のぶんか' AFTER job_id,
  ADD COLUMN sheet_part_id INT UNSIGNED NULL COMMENT '原材料発注一覧のどの部位のぶんか（資材はNULL）' AFTER sheet_id,
  ADD KEY idx_po_sheet (sheet_id);

CREATE TABLE order_sheets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  qty         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '製造数',
  note        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED NULL,
  updated_by  INT UNSIGNED NULL,
  deleted_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_os_product (product_id),
  CONSTRAINT fk_os_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='原材料発注一覧（商品＋製造数ごと）';

CREATE TABLE order_sheet_packs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sheet_id    INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  purpose     VARCHAR(100) NULL COMMENT '使用用途',
  use_qty     DECIMAL(12,3) NULL COMMENT '使用数',
  sort_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_osp (sheet_id, material_id),
  CONSTRAINT fk_osp_sheet    FOREIGN KEY (sheet_id)    REFERENCES order_sheets(id) ON DELETE CASCADE,
  CONSTRAINT fk_osp_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='原材料発注一覧の資材関連';
