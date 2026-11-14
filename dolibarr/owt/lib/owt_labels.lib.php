<?php
/** Fixed-size roll labels printed through the workstation's DYMO printer driver. */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit();
}
require_once __DIR__ . '/owt_print.lib.php';
require_once __DIR__ . '/owt_barcodes.lib.php';

function owt_label_profiles() {
    static $profiles;
    if ($profiles === null) {
        $profiles = json_decode(
            file_get_contents(__DIR__ . '/../assets/label-profiles.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
    return $profiles;
}

function owt_label_profile($format) {
    foreach (owt_label_profiles() as $profile) {
        if ($profile['id'] === $format) {
            return $profile;
        }
    }
    throw new InvalidArgumentException('Unknown label profile');
}

function owt_label_format($value) {
    $format = (string) $value;
    if (
        !in_array($format, array_merge(['', 'a4'], array_column(owt_label_profiles(), 'id')), true)
    ) {
        http_response_code(400);
        exit('Unknown label format');
    }
    return $format === '' ? 'a4' : $format;
}

/** Only authorize and serialize data here; TypeScript owns all print layout and fitting. */
function owt_print_document(
    $db,
    $items,
    $title,
    $format,
    $a4Url,
    $backUrl,
    $selection = [],
    $batch = false
) {
    try {
        $locale = owt_print_locale(GETPOST('lang', 'alphanohtml'));
        $symbology = owt_barcode_format(GETPOST('symbology', 'alphanohtml'));
    } catch (InvalidArgumentException $error) {
        http_response_code(400);
        exit($error->getMessage());
    }
    $translations = owt_print_translations($locale);
    $payload = [
        'items' => array_values($items),
        'products' => (object) owt_products_index($db, array_column($items, 'product_id')),
        'warehouses' => (object) owt_warehouses($db),
        'profiles' => owt_label_profiles(),
        'format' => $format,
        'symbology' => $symbology['id'],
        'barcode_formats' => owt_barcode_formats(),
        'title' => $translations[$title] ?? $title,
        'locale' => $locale,
        'base' => rtrim(owt_base(), '/') . '/',
        'back' => $backUrl,
        'a4' => $a4Url,
        'selection' => (object) $selection,
        'batch' => (bool) $batch,
        'translations' => (object) $translations,
    ];
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    print '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    foreach (['thermal-labels.css', 'a4-labels.css'] as $css) {
        print '<link rel="stylesheet" href="' . owt_h(owt_base() . 'assets/' . $css) . '">';
    }
    print '</head><body><script id="owt-print-data" type="application/json">' .
        json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) .
        '</script><script type="module" src="' .
        owt_h(owt_base() . 'spa/print/main.js') .
        '"></script>' .
        '<noscript>' .
        owt_h(
            $translations['JavaScript is required to prepare this print layout.'] ??
                'JavaScript is required to prepare this print layout.',
        ) .
        '</noscript></body></html>';
}
