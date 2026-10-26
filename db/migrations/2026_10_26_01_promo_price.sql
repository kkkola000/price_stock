-- Необязательная промоцена, существующие цены сохраняются.
ALTER TABLE products
    ADD COLUMN promo_price DECIMAL(14,2) NULL AFTER price_text,
    ADD COLUMN promo_price_text VARCHAR(190) NULL AFTER promo_price;
