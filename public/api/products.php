<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Models\ProductRepository;
use App\Models\SourceRepository;
use App\Support\CatalogRequest;
use App\View\Catalog;

header('Cache-Control: no-store');

try {
    $filters = CatalogRequest::fromQuery($_GET);
    $result  = ProductRepository::search($filters);

    $format = ($_GET['format'] ?? 'html') === 'json' ? 'json' : 'html';

    $payload = [
        'ok'       => true,
        'total'    => $result['total'],
        'page'     => $result['page'],
        'pages'    => $result['pages'],
        'has_more' => $result['has_more'],
        'label'    => Catalog::foundLabel($result['total']),
    ];

    if ($format === 'json') {
        $payload['items'] = array_map(static function (array $item): array {
            return [
                'id'           => (int) $item['id'],
                'sku'          => $item['sku'],
                'name'         => $item['name'],
                'price'        => $item['price'] === null ? null : (float) $item['price'],
                'price_text'   => price_label(
                    (string) ($item['price_text'] ?? ''),
                    $item['price'] === null ? null : (float) $item['price'],
                    (string) $item['currency']
                ),
                'promo_price'  => isset($item['promo_price']) ? (float) $item['promo_price'] : null,
                'promo_price_text' => isset($item['promo_price']) && (float) $item['promo_price'] > 0
                    ? price_label((string) ($item['promo_price_text'] ?? ''), (float) $item['promo_price'], (string) $item['currency']) : '',
                'currency'     => $item['currency'],
                'stock_qty'    => $item['stock_qty'] === null ? null : (int) $item['stock_qty'],
                'stock_text'   => $item['stock_text'],
                'availability' => $item['availability'],
                'image_url'    => $item['image_url'],
                'supplier'     => $item['supplier_name'],
            ];
        }, $result['items']);
    } else {
        $payload['html'] = $result['items'] === []
            ? ($result['page'] === 1 ? Catalog::emptyState() : '')
            : Catalog::cards($result['items']);
    }

    if (!empty($_GET['with_suppliers'])) {
        $payload['suppliers'] = SourceRepository::listForCatalog();
    }

    json_response($payload);
} catch (Throwable $exception) {
    error_log('[catalog-api] ' . $exception->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить товары. Попробуйте обновить страницу.'], 500);
}
