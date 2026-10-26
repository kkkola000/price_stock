<?php
declare(strict_types=1);

namespace App\View;


/**
 * Рендер карточек товаров. Используется и при первой отрисовке страницы,
 * и в API подгрузки — разметка всегда одна и та же.
 */
final class Catalog
{
    /** @param array<string,mixed> $product */
    public static function card(array $product): string
    {
        $availability = (string) $product['availability'];

        // Наличие показываем ровно так, как его передал поставщик.
        // Пусто — значит поставщик его не передаёт: тогда бейджа просто нет.
        $stockText = trim((string) ($product['stock_text'] ?? ''));

        $image = $product['image_url'] ?? null;
        $thumb = $image
            ? '<img class="card__img" src="' . e($image) . '" alt="' . e($product['name']) . '" loading="lazy" decoding="async"'
                . ' onerror="this.closest(\'.card__thumb\').classList.add(\'card__thumb--empty\');this.remove();">'
            : '';

        $priceLabel = price_label(
            (string) ($product['price_text'] ?? ''),
            $product['price'] === null ? null : (float) $product['price'],
            (string) $product['currency']
        );

        $hasPromo = isset($product['promo_price']) && (float) $product['promo_price'] > 0;
        $promoLabel = $hasPromo ? price_label(
            (string) ($product['promo_price_text'] ?? ''),
            (float) $product['promo_price'],
            (string) $product['currency']
        ) : '';

        // Данные для заказа держим прямо в карточке: страница заказов собирает
        // список из них, не обращаясь к серверу повторно
        $html = '<article class="card"'
            . ' data-id="' . (int) $product['id'] . '"'
            . ' data-sku="' . e($product['sku']) . '"'
            . ' data-name="' . e($product['name']) . '"'
            . ' data-price="' . e($hasPromo ? $promoLabel : $priceLabel) . '"'
            . ' data-stock="' . e($stockText) . '"'
            . ' data-supplier="' . e($product['supplier_name']) . '"'
            . ' data-supplier-id="' . (int) $product['source_id'] . '">';
        $html .= '<div class="card__thumb' . ($image ? '' : ' card__thumb--empty') . '">' . $thumb . '</div>';
        $html .= '<div class="card__body">';
        // Пустой бейдж всё равно выводим: в списке на ПК он держит колонку «Наличие»
        $html .= $stockText !== ''
            ? '<span class="badge badge--' . e($availability) . '" title="Наличие по данным поставщика">'
                . e($stockText) . '</span>'
            : '<span class="badge badge--none"></span>';
        $html .= '<h3 class="card__title">' . e($product['name']) . '</h3>';
        $html .= '<div class="card__sku" title="Артикул"><span class="card__sku-icon">#</span>' . e($product['sku']) . '</div>';
        $html .= '<div class="card__footer">';
        $html .= $hasPromo
            ? '<span class="card__price card__price--promo"><s class="card__price-old">' . e($priceLabel)
                . '</s><span class="card__price-caption">Цена по акции</span><span class="card__price-current">'
                . e($promoLabel) . '</span></span>'
            : '<span class="card__price">' . e($priceLabel) . '</span>';
        $html .= '<span class="card__supplier" title="Поставщик">' . e($product['supplier_name']) . '</span>';
        $html .= '<button type="button" class="order-btn js-order-toggle" data-order-id="' . (int) $product['id'] . '"'
            . ' title="Добавить в заказ" aria-label="Добавить в заказ" aria-pressed="false">'
            . '<span class="order-btn__plus" aria-hidden="true">+</span>'
            . '<span class="order-btn__check" aria-hidden="true">✓</span>'
            . '</button>';
        $html .= '</div></div></article>';

        return $html;
    }

    /** @param list<array<string,mixed>> $products */
    public static function cards(array $products): string
    {
        $html = '';
        foreach ($products as $product) {
            $html .= self::card($product);
        }

        return $html;
    }

    public static function emptyState(): string
    {
        return '<div class="empty">'
            . '<div class="empty__icon">🔍</div>'
            . '<p class="empty__title">Ничего не найдено</p>'
            . '<p class="empty__text">Попробуйте изменить запрос или сбросить фильтры.</p>'
            . '</div>';
    }

    /** Текст «Найдено N товаров» с правильным окончанием. */
    public static function foundLabel(int $total): string
    {
        $mod10 = $total % 10;
        $mod100 = $total % 100;
        $word = 'товаров';
        if ($mod10 === 1 && $mod100 !== 11) {
            $word = 'товар';
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            $word = 'товара';
        }

        return number_format($total, 0, ',', ' ') . ' ' . $word;
    }
}
