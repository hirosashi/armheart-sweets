-- スイーツ生産管理システム フェーズA（MVP） テーブル定義
-- MySQL 8.0 / utf8mb4
SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- ============================================================
-- 1. 利用者・認証・ログ
-- ============================================================
CREATE TABLE users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '利用者ID',
  login_id        VARCHAR(50)  NOT NULL COMMENT 'ログインID',
  password_hash   VARCHAR(255) NOT NULL COMMENT 'パスワード(ハッシュ)',
  name            VARCHAR(100) NOT NULL COMMENT '氏名',
  role            ENUM('admin','production','purchase','viewer') NOT NULL DEFAULT 'viewer' COMMENT '役割',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '有効フラグ',
  must_change_pw  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '次回ログイン時パスワード変更',
  last_login_at   DATETIME     NULL COMMENT '最終ログイン日時',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  deleted_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login_id (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者';

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id    VARCHAR(50) NOT NULL COMMENT '試行されたログインID',
  ip_address  VARCHAR(45) NOT NULL,
  succeeded   TINYINT(1)  NOT NULL DEFAULT 0,
  attempted_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts (login_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ログイン試行記録';

CREATE TABLE operation_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL COMMENT '操作者',
  action      VARCHAR(50)  NOT NULL COMMENT 'login/logout/create/update/delete/order_fix 等',
  target      VARCHAR(50)  NULL COMMENT '対象（テーブル名や画面名）',
  target_id   VARCHAR(50)  NULL COMMENT '対象のキー',
  detail      TEXT         NULL COMMENT '内容',
  ip_address  VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_operation_logs_user (user_id, created_at),
  KEY idx_operation_logs_target (target, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作ログ';

-- ============================================================
-- 2. 自社・業者・原材料
-- ============================================================
CREATE TABLE companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL COMMENT '自社名（発注書の発注者欄）',
  zip           VARCHAR(10)  NULL,
  address       VARCHAR(255) NULL,
  tel           VARCHAR(30)  NULL,
  fax           VARCHAR(30)  NULL,
  delivery_place VARCHAR(255) NULL COMMENT '納品場所の既定値',
  is_default    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '既定の発注元',
  sort_no       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自社（発注元）';

CREATE TABLE suppliers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(20)  NULL COMMENT '業者コード',
  name          VARCHAR(100) NOT NULL COMMENT '業者名',
  zip           VARCHAR(10)  NULL,
  address       VARCHAR(255) NULL,
  tel           VARCHAR(30)  NULL,
  fax           VARCHAR(30)  NULL,
  contact_name  VARCHAR(100) NULL COMMENT '担当者名',
  email         VARCHAR(255) NULL,
  supplier_type ENUM('purchase','sales','both') NOT NULL DEFAULT 'purchase' COMMENT '仕入先/販売先/両方',
  order_method  ENUM('fax','email','tel','web','other') NOT NULL DEFAULT 'fax' COMMENT '発注方法',
  lead_time_days SMALLINT UNSIGNED NULL COMMENT '納品までの日数',
  note          TEXT         NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  updated_by    INT UNSIGNED NULL,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (code),
  KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='業者';

CREATE TABLE materials (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)  NULL COMMENT '材料コード',
  name            VARCHAR(100) NOT NULL COMMENT '材料名（代表名）',
  alias_names     VARCHAR(500) NULL COMMENT '別表記（/区切り。例:未殺菌液全卵(ロカ)/液全卵ロカ(未殺菌)）',
  kind            ENUM('material','pack_production','pack_product') NOT NULL DEFAULT 'material' COMMENT '原材料/生産用資材/商品用資材',
  category        VARCHAR(50)  NULL COMMENT '種別（粉類/乳製品/果物 等）',
  maker_name      VARCHAR(200) NULL COMMENT 'メーカー名（/区切りで複数可）',
  allergens       VARCHAR(255) NULL COMMENT 'アレルゲン（・区切り。例:小麦・卵・乳成分）',
  unit            VARCHAR(20)  NOT NULL DEFAULT 'g' COMMENT '管理単位（g/ml/枚/個）',
  purchase_unit   VARCHAR(20)  NULL COMMENT '仕入単位（袋/本/ケース）',
  purchase_qty    DECIMAL(12,3) NULL COMMENT '仕入単位あたりの数量（例:1袋=25000g）',
  unit_price      DECIMAL(12,2) NULL COMMENT '仕入単価（仕入単位あたり・税抜）',
  price_per_kg    DECIMAL(12,2) NULL COMMENT 'kg単価（原価計算表の値）',
  price_source    VARCHAR(100) NULL COMMENT '単価の根拠（例:2026.05.25納品書）',
  price_date      DATE         NULL COMMENT '単価の適用日',
  supplier_id     INT UNSIGNED NULL COMMENT '主な業者',
  is_supplied     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '支給品（取引先から支給される）',
  is_stock_managed TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '在庫・発注の対象にする（水などは0）',
  has_expiry      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '賞味期限管理する',
  expiry_alert_days SMALLINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '期限が近いと判断する日数',
  safety_ratio    DECIMAL(5,2) NOT NULL DEFAULT 1.20 COMMENT '「あぶない」判定の余裕倍率',
  note            TEXT         NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  deleted_at      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_materials_code (code),
  KEY idx_materials_name (name),
  CONSTRAINT fk_materials_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='原材料';

-- ============================================================
-- 3. 商品・パーツ・レシピ
-- ============================================================
CREATE TABLE products (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(20)  NULL COMMENT '商品コード',
  name         VARCHAR(100) NOT NULL COMMENT '商品名',
  category     VARCHAR(50)  NULL COMMENT '種類（焼菓子/生菓子 等）',
  seller_name  VARCHAR(100) NULL COMMENT '販売者（例:伊藤忠食品）',
  spec         VARCHAR(100) NULL COMMENT '規格・形状（例:４号ホール）',
  shelf_life   VARCHAR(100) NULL COMMENT '賞味期限',
  thaw_shelf_life VARCHAR(100) NULL COMMENT '解凍後消費期限',
  launch_date  DATE         NULL COMMENT '導入予定（発売日）',
  plan_qty_note VARCHAR(100) NULL COMMENT '数量(限定数)（例:3000～8000台）',
  allergens    VARCHAR(255) NULL COMMENT '含有アレルゲン',
  author       VARCHAR(50)  NULL COMMENT '配合表の作成者',
  revised_at   DATE         NULL COMMENT '配合表の作成・改訂日',
  case_qty     SMALLINT UNSIGNED NULL COMMENT '1ケース入数（入り数）',
  case_weight  DECIMAL(10,2) NULL COMMENT 'ケース重量(g)',
  case_size    VARCHAR(50)  NULL COMMENT 'ケース寸法',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  note         TEXT         NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code),
  KEY idx_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品';

CREATE TABLE parts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(20)  NULL COMMENT 'パーツコード',
  name         VARCHAR(100) NOT NULL COMMENT '部位名（スポンジ/ムース/グラサージュ 等）',
  unit         VARCHAR(20)  NOT NULL DEFAULT 'g' COMMENT 'バッチの単位（g/ml）',
  batch_total_qty DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '1バッチ（1回の仕込み）の合計量。配合量の合計と一致',
  yield_rate   DECIMAL(5,3) NOT NULL DEFAULT 0.900 COMMENT '歩留まり（0.9＝ロス10%）',
  round_batch  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '必要バッチ数を整数に切り上げる',
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '複数商品で使い回す共通パーツか',
  note         TEXT         NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  deleted_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parts_code (code),
  KEY idx_parts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='パーツ（半製品）';

CREATE TABLE product_parts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  part_id     INT UNSIGNED NOT NULL,
  fill_qty    DECIMAL(12,3) NOT NULL COMMENT '充填量（1台ぶんとして充填する量）',
  fill_unit   VARCHAR(20)  NOT NULL DEFAULT 'g' COMMENT '充填量の単位',
  pieces_per_fill SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '取り数（充填量から何個取れるか）',
  use_pieces  SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1台に使う個数',
  note        VARCHAR(255) NULL COMMENT '例:1.5cm厚2枚取り1枚使用',
  sort_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_parts (product_id, part_id),
  CONSTRAINT fk_pp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_part    FOREIGN KEY (part_id)    REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品→パーツ構成';

CREATE TABLE part_materials (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  part_id     INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  qty         DECIMAL(12,3) NOT NULL COMMENT '1バッチあたりの配合量（配合表の「配合量(g)」）',
  sort_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_part_materials (part_id, material_id),
  CONSTRAINT fk_pm_part     FOREIGN KEY (part_id)     REFERENCES parts(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='パーツ→原材料の配合';

CREATE TABLE product_materials (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  qty         DECIMAL(12,3) NOT NULL COMMENT '商品1個あたりの材料量（パーツを介さない直接配合）',
  sort_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_materials (product_id, material_id),
  CONSTRAINT fk_prm_product  FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_prm_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品→原材料の直接配合';

-- ============================================================
-- 4. 生産計画・進捗
-- ============================================================
CREATE TABLE production_plans (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_date  DATE         NOT NULL COMMENT '対象日',
  product_id   INT UNSIGNED NOT NULL,
  qty          INT UNSIGNED NOT NULL COMMENT '生産数',
  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_production_plans (target_date, product_id),
  CONSTRAINT fk_plan_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日別生産計画（旧。jobs へ移行し未使用）';

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

CREATE TABLE part_progress (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_date  DATE         NOT NULL COMMENT '対象日',
  part_id      INT UNSIGNED NOT NULL,
  planned_qty  DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '必要数（その日の計画分）',
  carried_qty  DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '前日から引き継いだ残り回数',
  done_qty     DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '完成数',
  status       ENUM('todo','doing','done') NOT NULL DEFAULT 'todo' COMMENT '未着手/製造中/完成',
  assignee     VARCHAR(100) NULL COMMENT '担当',
  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_part_progress (target_date, part_id),
  CONSTRAINT fk_progress_part FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='パーツ別生産進捗（かんばん）';

-- ============================================================
-- 5. 在庫
-- ============================================================
CREATE TABLE inventory (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  material_id  INT UNSIGNED NOT NULL,
  lot_no       VARCHAR(50)  NULL COMMENT 'ロット番号',
  qty          DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '在庫数（管理単位）',
  expiry_date  DATE         NULL COMMENT '賞味期限',
  location     VARCHAR(50)  NULL COMMENT '保管場所',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_inventory_material (material_id),
  KEY idx_inventory_expiry (expiry_date),
  CONSTRAINT fk_inv_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='原材料在庫';

CREATE TABLE inventory_adjustments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inventory_id  INT UNSIGNED NULL COMMENT '対象在庫（新規追加時はNULL）',
  material_id   INT UNSIGNED NOT NULL,
  before_qty    DECIMAL(12,3) NULL COMMENT '調整前',
  after_qty     DECIMAL(12,3) NOT NULL COMMENT '調整後',
  reason        ENUM('stocktake','receive','consume','loss','other') NOT NULL DEFAULT 'stocktake' COMMENT '棚卸し/入荷/使用/廃棄/その他',
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_adj_material (material_id, created_at),
  CONSTRAINT fk_adj_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在庫調整履歴';

-- ============================================================
-- 6. 発注
-- ============================================================
CREATE TABLE purchase_orders (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no      VARCHAR(30)  NOT NULL COMMENT '発注番号',
  company_id    INT UNSIGNED NULL COMMENT '発注元（自社）',
  supplier_id   INT UNSIGNED NOT NULL,
  delivery_place VARCHAR(255) NULL COMMENT '納品場所',
  status        ENUM('draft','ordered','partial','delivered','canceled') NOT NULL DEFAULT 'draft' COMMENT '未発注/発注済/一部納品/納品済/取消',
  order_date    DATE         NULL COMMENT '発注日',
  period_from   DATE         NULL COMMENT '対象期間（この日から）',
  period_to     DATE         NULL COMMENT '対象期間（この日まで）',
  job_id        INT UNSIGNED NULL COMMENT 'どの発注（つくる予定）のぶんか',
  desired_date  DATE         NULL COMMENT '希望納品日',
  delivered_date DATE        NULL COMMENT '納品日',
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '小計(税抜)',
  tax           DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '消費税',
  total         DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '合計(税込)',
  note          TEXT         NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  updated_by    INT UNSIGNED NULL,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_order_no (order_no),
  KEY idx_po_status (status, order_date),
  CONSTRAINT fk_po_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='発注';

CREATE TABLE purchase_order_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      INT UNSIGNED NOT NULL,
  material_id   INT UNSIGNED NOT NULL,
  item_name     VARCHAR(150) NULL COMMENT '発注書に印字する品名（未指定なら材料名）',
  spec          VARCHAR(100) NULL COMMENT '規格',
  qty           DECIMAL(12,3) NOT NULL COMMENT '発注数（仕入単位）',
  unit          VARCHAR(20)  NULL COMMENT '仕入単位（ケース/袋 等）',
  unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  received_qty  DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '納品済数',
  note          VARCHAR(255) NULL,
  sort_no       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_poi_order (order_id),
  CONSTRAINT fk_poi_order    FOREIGN KEY (order_id)    REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='発注明細';

-- ============================================================
-- 7. 製造による材料使用（できあがり時に在庫から自動で引いた記録）
-- ============================================================
CREATE TABLE part_consumptions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_date   DATE          NOT NULL COMMENT '対象日（できあがりを入れた日）',
  part_id       INT UNSIGNED  NOT NULL,
  material_id   INT UNSIGNED  NOT NULL,
  inventory_id  INT UNSIGNED  NULL COMMENT '引いた在庫ロット（在庫が無く引けなかった分はNULL）',
  batches       DECIMAL(12,3) NOT NULL COMMENT 'できた仕込み回数',
  qty           DECIMAL(12,3) NOT NULL COMMENT '使った量（計算値）',
  applied_qty   DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '実際に在庫から引いた量',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_consume_date_part (target_date, part_id),
  KEY idx_consume_material (material_id, target_date),
  CONSTRAINT fk_consume_part     FOREIGN KEY (part_id)     REFERENCES parts(id),
  CONSTRAINT fk_consume_material FOREIGN KEY (material_id) REFERENCES materials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='製造による材料使用（自動引き当て）';
