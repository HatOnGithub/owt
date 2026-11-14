<?php
/** Authorized single-item print data; standalone TypeScript owns label layout and fitting. */
$res = 0;
if (is_file(__DIR__ . '/../../main.inc.php')) {
    $res = include __DIR__ . '/../../main.inc.php';
}
if (!$res) {
    die('Missing Dolibarr main.inc.php');
}
require_once __DIR__ . '/lib/owt.lib.php';
require_once __DIR__ . '/lib/owt_labels.lib.php';
if (!owt_permission_right($user, 'print')) {
    accessforbidden();
}
$code = owt_input_code(GETPOST('code', 'alphanohtml'));
$state = owt_browser_inventory($db)['state'];
header('Cache-Control: no-store');
$item = $state['items'][$code] ?? null;
if (!$item) {
    http_response_code(404);
    exit('Label not found');
}
$img = owt_link('qr.php', ['code' => $code]);
$format = owt_label_format(GETPOST('format', 'alphanohtml'));
owt_print_document(
    $db,
    [$item],
    'Print QR label',
    $format,
    owt_link('label.php', ['code' => $code, 'format' => 'a4']),
    owt_app_link('items/' . $code),
    ['code' => $code],
);
