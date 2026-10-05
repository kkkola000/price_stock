-- Соответствие остатков («Более 5» -> число для фида) и виртуальные склады.

CREATE TABLE IF NOT EXISTS yx_stock_map (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pattern VARCHAR(190) NOT NULL COMMENT 'Значение остатка из файла, напр. «Более 5»',
  qty     INT          NOT NULL DEFAULT 0 COMMENT 'Какое количество передавать в фид',
  sort    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_stock_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE yx_warehouses
  ADD COLUMN kind ENUM('file','virtual') NOT NULL DEFAULT 'file' COMMENT 'file — остатки из файла, virtual — постоянный остаток без файла' AFTER is_active,
  ADD COLUMN default_stock INT NOT NULL DEFAULT 0 COMMENT 'instock виртуального склада в каждом оффере' AFTER kind;

ALTER TABLE yx_feed_settings
  ADD COLUMN stock_fallback INT NOT NULL DEFAULT 0 COMMENT 'instock, если соответствие в yx_stock_map не найдено';
