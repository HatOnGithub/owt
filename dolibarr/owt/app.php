<?php
/** Angular host inside the authenticated Dolibarr shell. */
// Dolibarr loads the selected menu during bootstrap. Bare URLs and printed deep
// links must select OWT instead of inheriting Home (or a stale menu ID) from session.
// Preserve an explicit menu context supplied by a native Dolibarr link.
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
    $_GET['mainmenu'] = 'owt';
    $_GET['leftmenu'] = '';
    $_GET['idmenu'] = 0;
}
$res = 0;
if (is_file(__DIR__ . '/../../main.inc.php')) {
    $res = include __DIR__ . '/../../main.inc.php';
}
if (!$res) {
    http_response_code(503);
    exit('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/lib/owt.lib.php';
if (!owt_inventory_right($user)) {
    accessforbidden();
}
header('Cache-Control: no-store');
$index = __DIR__ . '/spa/index.html';
if (!is_file($index)) {
    http_response_code(503);
    owt_page_header('Open Warehouse Tracker');
    print '<p>Open Warehouse Tracker frontend is not built. Run python tools/build-frontend.py before packaging the module.</p>';
    llxFooter();
    exit();
}
$markup = file_get_contents($index);
$base = dol_buildpath('/owt/spa/', 1);
owt_page_header('Open Warehouse Tracker');
preg_match_all(
    '~<link\b[^>]*rel="stylesheet"[^>]*>|<script\b[^>]*src="[^"]+"[^>]*></script>~i',
    $markup,
    $tags,
);
$scripts = [];
foreach ($tags[0] as $tag) {
    $tag = preg_replace_callback(
        '~\b(src|href)="([^"]+)"~',
        function ($match) use ($base) {
            return $match[1] . '="' . owt_h($base . $match[2]) . '"';
        },
        $tag,
    );
    if (stripos($tag, '<script') === 0) {
        $scripts[] = $tag;
    } else {
        print $tag;
    }
}
print '<owt-root data-api="' . owt_h(dol_buildpath('/owt/api.php', 1)) . '"></owt-root>';
print '<noscript>Open Warehouse Tracker requires JavaScript.</noscript>' . implode("\n", $scripts);
llxFooter();
