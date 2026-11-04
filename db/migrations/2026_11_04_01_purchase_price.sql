ALTER TABLE products
    ADD COLUMN purchase_price DECIMAL(14,2) NULL AFTER promo_price_text,
    ADD COLUMN purchase_price_text VARCHAR(190) NULL AFTER purchase_price;
