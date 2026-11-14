<?php
define('DOL_DOCUMENT_ROOT', __DIR__);
require_once __DIR__ . '/../dolibarr/owt/lib/owt.lib.php';
require_once __DIR__ . '/../dolibarr/owt/lib/owt_labels.lib.php';
$checks = 0;
function label_check($condition, $description) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}
function dol_buildpath($path, $type = 0) {
    return '/custom' . $path;
}
foreach (owt_label_profiles() as $profile) {
    label_check(owt_label_format($profile['id']) === $profile['id'], 'Configured formats accepted');
    label_check(
        $profile['width'] > $profile['qr'] && $profile['height'] > $profile['qr'],
        'QR fits physical label',
    );
    label_check($profile['detail_limit'] >= 3, 'Assigned label has room for identification');
}
label_check(owt_label_format('') === 'a4', 'Default A4 profile');
foreach (['22222', 'ABCD2', '2222222', 'ABCD234'] as $payload) {
    $code = $payload . owt_short_check_character($payload);
    label_check(owt_code_valid($code), 'Both identifier lengths retain their checksum');
    for ($i = 0; $i < strlen($code); $i++) {
        $changed = $code;
        $changed[$i] = $code[$i] === '2' ? '3' : '2';
        label_check(!owt_code_valid($changed), 'Changed identifiers are rejected');
    }
}
foreach (['22222', '2222222', '222222222', '2222222I', '2222222O'] as $invalid) {
    label_check(!owt_code_valid($invalid), 'Unsupported identifier shapes rejected');
}
label_check(owt_barcode_format('')['id'] === 'QRCODE', 'Existing print links retain QR by default');
label_check(
    owt_item_codes([222263 => [], 22222872 => [], 'ABCDEF' => []]) === [
        '222263',
        '22222872',
        'ABCDEF',
    ],
    'Numeric-looking identifiers remain strings in public scopes',
);
label_check(
    owt_code_valid('22222872'),
    'Numeric-looking eight-character ID matches the shared checksum vector',
);
foreach (['QRCODE', 'DATAMATRIX', 'C128', 'PDF417'] as $symbology) {
    label_check(
        owt_barcode_format($symbology)['id'] === $symbology,
        'Alphanumeric barcode format accepted',
    );
}
foreach (['EAN13', 'UPC', 'C128C', '../QRCODE', '0'] as $symbology) {
    try {
        owt_barcode_format($symbology);
        throw new LogicException('Unsupported format accepted');
    } catch (InvalidArgumentException $error) {
        $checks++;
    }
}
label_check(
    array_column(owt_barcode_formats(true), 'id') === ['QRCODE', 'DATAMATRIX'],
    'Long route labels use compact formats',
);
print 'Passed ' . $checks . " label profile checks.\n";
