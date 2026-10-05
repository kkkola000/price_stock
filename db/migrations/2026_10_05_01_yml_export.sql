-- Дополнение «Выгрузка YML»: свои склады, позиции, сопоставления и настройки фида.
-- Основные таблицы (sources, products, import_runs) не изменяются.

CREATE TABLE IF NOT EXISTS yx_warehouses (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(190) NOT NULL,
  code       VARCHAR(64)  NOT NULL DEFAULT '',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 0 COMMENT 'Меньше = приоритетнее (оттуда берутся цены оффера)',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_wh_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yx_uploads (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id       INT UNSIGNED NOT NULL,
  type               ENUM('excel','csv','yml') NOT NULL,
  fetch_method       ENUM('upload','url')      NOT NULL,
  source_url         VARCHAR(1000) NULL,
  file_path          VARCHAR(255)  NULL COMMENT 'Имя файла внутри storage/uploads',
  original_filename  VARCHAR(255)  NULL,
  csv_delimiter      VARCHAR(8)    NOT NULL DEFAULT 'auto',
  csv_encoding       VARCHAR(32)   NOT NULL DEFAULT 'auto',
  skip_rows          INT UNSIGNED  NOT NULL DEFAULT 1,
  sheet_index        INT UNSIGNED  NOT NULL DEFAULT 1,
  skip_hidden        TINYINT(1)    NOT NULL DEFAULT 1,
  mapping            TEXT          NOT NULL COMMENT 'JSON: sku, name, stock, price, oldprice, min_price',
  last_run_at        DATETIME      NULL,
  last_status        ENUM('never','running','ok','error') NOT NULL DEFAULT 'never',
  last_error         TEXT          NULL,
  items_count        INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_upload_wh (warehouse_id),
  CONSTRAINT fk_yx_uploads_wh FOREIGN KEY (warehouse_id) REFERENCES yx_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yx_items (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_id    INT UNSIGNED    NOT NULL,
  warehouse_id INT UNSIGNED    NOT NULL,
  sku          VARCHAR(190)    NOT NULL,
  name         VARCHAR(512)    NOT NULL,
  stock_qty    INT             NULL,
  stock_text   VARCHAR(190)    NULL COMMENT 'Остаток как в файле',
  price        DECIMAL(14,2)   NULL,
  oldprice     DECIMAL(14,2)   NULL,
  min_price    DECIMAL(14,2)   NULL,
  extra        TEXT            NULL COMMENT 'JSON: сырые значения столбцов по буквам — для конструктора тегов',
  import_batch VARCHAR(16)     NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_item (warehouse_id, sku),
  KEY idx_yx_items_upload (upload_id),
  CONSTRAINT fk_yx_items_wh FOREIGN KEY (warehouse_id) REFERENCES yx_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yx_matches (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id INT UNSIGNED    NOT NULL,
  item_sku     VARCHAR(190)    NOT NULL,
  product_id   BIGINT UNSIGNED NULL COMMENT 'id товара каталога (products.id), без внешнего ключа — товары каталога перевливаются импортом',
  product_sku  VARCHAR(190)    NOT NULL DEFAULT '' COMMENT 'Снимок артикула каталога на момент сопоставления — стабильный id оффера',
  status       ENUM('suggested','confirmed','rejected','unmatched') NOT NULL DEFAULT 'unmatched',
  score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  method       ENUM('sku','name','manual','none') NOT NULL DEFAULT 'none',
  confirmed_by INT UNSIGNED    NULL,
  confirmed_at DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_match (warehouse_id, item_sku),
  KEY idx_yx_matches_status (status),
  KEY idx_yx_matches_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yx_feed_settings (
  id                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  shop_name            VARCHAR(190) NOT NULL DEFAULT '',
  shop_company         VARCHAR(190) NOT NULL DEFAULT '',
  shop_url             VARCHAR(500) NOT NULL DEFAULT '',
  currency             VARCHAR(8)   NOT NULL DEFAULT 'KZT',
  offer_mapping        TEXT NOT NULL COMMENT 'JSON: конструктор тегов и атрибутов оффера',
  outlets              TEXT NOT NULL COMMENT 'JSON: настройка блока остатков по складам',
  only_confirmed       TINYINT(1) NOT NULL DEFAULT 1,
  skip_zero_stock      TINYINT(1) NOT NULL DEFAULT 1,
  oldprice_only_higher TINYINT(1) NOT NULL DEFAULT 1,
  token                VARCHAR(64) NOT NULL,
  built_at             DATETIME NULL,
  built_offers         INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
