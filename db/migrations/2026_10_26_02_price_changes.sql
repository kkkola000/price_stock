
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
