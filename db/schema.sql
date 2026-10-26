-- Схема БД сервиса объединённого каталога поставщиков.
-- MySQL 5.7+ / MariaDB 10.3+ (кодировка utf8mb4).

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                   VARCHAR(190) NOT NULL COMMENT 'Название поставщика',
  type                   ENUM('excel','csv','yml') NOT NULL,
  fetch_method           ENUM('upload','url')      NOT NULL,
  source_url             VARCHAR(1000) NULL,
  file_path              VARCHAR(255)  NULL COMMENT 'Путь внутри storage/uploads',
  original_filename      VARCHAR(255)  NULL,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  auto_import            TINYINT(1)   NOT NULL DEFAULT 0,
  import_interval_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
  csv_delimiter          VARCHAR(8)   NOT NULL DEFAULT 'auto',
  csv_encoding           VARCHAR(32)  NOT NULL DEFAULT 'auto',
  skip_rows              INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Сколько строк заголовка пропустить',
  sheet_index            INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Номер листа Excel, 1 = первый',
  skip_hidden            TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Не импортировать скрытые строки и столбцы',
  currency_code          VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'Валюта источника, если её нет в файле',
  mapping                TEXT         NOT NULL COMMENT 'JSON: соответствие полей столбцам/тегам',
  last_run_at            DATETIME     NULL,
  last_success_at        DATETIME     NULL,
  last_status            ENUM('never','running','ok','error') NOT NULL DEFAULT 'never',
  last_error             TEXT         NULL,
  products_count         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sources_active (is_active),
  KEY idx_sources_auto (auto_import, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id     INT UNSIGNED NOT NULL,
  sku           VARCHAR(190) NOT NULL,
  name          VARCHAR(512) NOT NULL,
  price         DECIMAL(14,2) NULL COMMENT 'Числовое значение — для сортировки',
  price_text    VARCHAR(190) NULL COMMENT 'Цена в том виде, как её передал поставщик',
  promo_price   DECIMAL(14,2) NULL,
  promo_price_text VARCHAR(190) NULL,
  currency      VARCHAR(8)   NOT NULL DEFAULT 'RUB',
  stock_qty     INT          NULL,
  stock_text    VARCHAR(190) NULL COMMENT 'Наличие в том виде, как его передал поставщик',
  availability  ENUM('in_stock','out_of_stock') NOT NULL DEFAULT 'out_of_stock',
  image_url     VARCHAR(1000) NULL,
  extra         TEXT         NULL COMMENT 'JSON с дополнительными полями источника',
  import_run_id BIGINT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_source_sku (source_id, sku),
  KEY idx_products_name (name(120)),
  KEY idx_products_sku (sku),
  KEY idx_products_avail (availability),
  KEY idx_products_price (price),
  KEY idx_products_run (source_id, import_run_id),
  CONSTRAINT fk_products_source FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id     INT UNSIGNED NOT NULL,
  trigger_type  ENUM('manual','cron','upload') NOT NULL DEFAULT 'manual',
  status        ENUM('running','ok','error') NOT NULL DEFAULT 'running',
  started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   DATETIME     NULL,
  rows_read     INT UNSIGNED NOT NULL DEFAULT 0,
  rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped  INT UNSIGNED NOT NULL DEFAULT 0,
  rows_deleted  INT UNSIGNED NOT NULL DEFAULT 0,
  message       TEXT         NULL,
  PRIMARY KEY (id),
  KEY idx_runs_source (source_id, started_at),
  CONSTRAINT fk_runs_source FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(190) NOT NULL,
  applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История цен: снимки только существовавших товаров после успешного импорта.
CREATE TABLE IF NOT EXISTS price_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id INT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  import_run_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(512) NOT NULL,
  sku VARCHAR(190) NOT NULL,
  old_price DECIMAL(14,2) NULL,
  new_price DECIMAL(14,2) NULL,
  old_price_text VARCHAR(190) NULL,
  new_price_text VARCHAR(190) NULL,
  old_promo_price DECIMAL(14,2) NULL,
  new_promo_price DECIMAL(14,2) NULL,
  old_promo_price_text VARCHAR(190) NULL,
  new_promo_price_text VARCHAR(190) NULL,
  old_currency VARCHAR(8) NOT NULL,
  new_currency VARCHAR(8) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_change_run (import_run_id, product_id),
  KEY idx_price_changes_date (changed_at, id),
  KEY idx_price_changes_source (source_id),
  CONSTRAINT fk_price_changes_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_changes_run FOREIGN KEY (import_run_id) REFERENCES import_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
