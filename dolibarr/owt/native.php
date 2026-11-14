<?php
/** Read-only QR references from native Dolibarr cards and location tabs. */
$res = 0;
if (is_file(__DIR__ . '/../../main.inc.php')) {
    $res = include __DIR__ . '/../../main.inc.php';
}
if (!$res) {
    http_response_code(503);
    exit('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/lib/owt.lib.php';
require_once __DIR__ . '/lib/owt_related.lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!owt_inventory_right($user)) {
    accessforbidden();
}
try {
    $kind = (string) GETPOST('kind', 'aZ09');
    $id = (int) GETPOST('id', 'int');
    $target = owt_related_target($db, $user, $kind, $id);
    $binKey = (string) GETPOST('bin_key', 'alphanohtml');
    if ($binKey !== '' && ($kind !== 'warehouse' || !owt_locations_right($user))) {
        throw new DomainException('Bin locations are inaccessible');
    }
    $view = owt_browser_inventory($db);
    $items = owt_related_items($view['state']['items'], $kind, $id);
    $query = trim((string) GETPOST('q', 'alphanohtml'));
    if ($query !== '') {
        $items = array_filter($items, function ($item) use ($query, $view) {
            $product = $view['products'][(int) $item['product_id']] ?? [];
            return stripos(
                implode(' ', [
                    $item['code'],
                    $item['label'],
                    $item['serial'] ?? '',
                    $item['bin'] ?? '',
                    $item['physical_bin_id'] ?? '',
                    $product['ref'] ?? '',
                    $product['label'] ?? '',
                ]),
                $query,
            ) !== false;
        });
    }
    $bins = [];
    foreach ($items as $item) {
        if (!empty($item['bin_key'])) {
            $bins[$item['bin_key']] = $item['bin'];
        }
    }
    natcasesort($bins);
    if ($binKey !== '') {
        $items = array_filter($items, fn($item) => ($item['bin_key'] ?? '') === $binKey);
    }
    uasort(
        $items,
        fn($a, $b) => strnatcasecmp($a['label'] ?: $a['code'], $b['label'] ?: $b['code']),
    );
    $total = count($items);
    $pages = max(1, (int) ceil($total / 50));
    $page = max(1, min($pages, (int) GETPOST('page', 'int')));
    $items = array_slice($items, ($page - 1) * 50, 50, true);
    $head = owt_related_head($target);
} catch (DomainException $error) {
    accessforbidden();
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit(owt_h($error->getMessage()));
} catch (Throwable $error) {
    dol_syslog('Open Warehouse Tracker native references: ' . $error->getMessage(), LOG_ERR);
    http_response_code(503);
    llxHeader('', owt_t('QR entries'));
    print '<p class="error">' .
        owt_h(owt_t('warehouse inventory is temporarily unavailable.')) .
        '</p>';
    llxFooter();
    exit();
}

// Use the native shell, retaining the warehouse/product navigation and extension tabs.
$langs->load('owt@owt');
llxHeader('', owt_t('QR entries') . ' - ' . $target['ref']);
if ($head) {
    print dol_get_fiche_head($head, 'owt', owt_t('QR entries'), -1, 'barcode');
}
print '<h1>' . owt_h(owt_t('QR entries')) . ': ' . owt_h($target['ref']) . '</h1>';
print '<form method="get"><input type="hidden" name="kind" value="' .
    owt_h($kind) .
    '"><input type="hidden" name="id" value="' .
    $id .
    '"><label>' .
    owt_h(owt_t('Search items or locations')) .
    ' <input name="q" value="' .
    owt_h($query) .
    '"></label><button type="submit">' .
    owt_h(owt_t('Search')) .
    '</button></form>';
print '<p><a href="' . owt_h($target['url']) . '">' . owt_h(owt_t('Back to native card')) . '</a>';
if ($target['locations_url'] !== '') {
    print ' &middot; <a href="' .
        owt_h($target['locations_url']) .
        '">' .
        owt_h(owt_t('Open Warehouse Tracker locations')) .
        '</a>';
}
print ' &middot; <a href="' .
    owt_h(owt_app_link('inventory')) .
    '">' .
    owt_h(owt_t('Inventory')) .
    '</a></p>';
if ($kind === 'warehouse' && $bins && owt_locations_right($user)) {
    print '<form method="get"><input type="hidden" name="kind" value="warehouse"><input type="hidden" name="id" value="' .
        $id .
        '">';
    print '<label>' .
        owt_h(owt_t('Open Warehouse Tracker locations')) .
        ' <select class="flat" name="bin_key"><option value="">' .
        owt_h(owt_t('All locations')) .
        '</option>';
    foreach ($bins as $key => $label) {
        print '<option value="' .
            owt_h($key) .
            '"' .
            ($binKey === (string) $key ? ' selected' : '') .
            '>' .
            owt_h($label) .
            '</option>';
    }
    print '</select></label> <button class="button" type="submit">' .
        owt_h(owt_t('Filter')) .
        '</button></form>';
}
print '<p>' . owt_h(owt_t('QR entries')) . ': ' . $total . '</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent"><thead><tr class="liste_titre">';
foreach (
    ['Item / QR', 'Product', 'Dolibarr lot/serial', 'Warehouse', 'Location', 'Quantity', 'Status']
    as $label
) {
    print '<th>' . owt_h(owt_t($label)) . '</th>';
}
print '</tr></thead><tbody>';
foreach ($items as $code => $item) {
    $pid = (int) $item['product_id'];
    $wid = (int) $item['warehouse_id'];
    $lot = (int) ($item['product_lot_id'] ?? 0);
    $status =
        !empty($item['pending_operation']) || !empty($item['pending_handoff'])
            ? 'Pending'
            : (isset($item['checkout'])
                ? 'On loan'
                : ($item['status'] === 'consumed'
                    ? 'Consumed'
                    : 'In stock'));
    print '<tr class="oddeven"><td><a href="' .
        owt_h(owt_app_link('items/' . rawurlencode($code))) .
        '">' .
        owt_h($item['label'] ?: $code) .
        '</a><br><span class="opacitymedium">' .
        owt_h($code) .
        '</span></td>';
    print '<td><a href="' .
        owt_h(dol_buildpath('/product/card.php', 1) . '?id=' . $pid) .
        '">' .
        owt_h($view['products'][$pid]['ref'] ?? '') .
        '</a></td>';
    print '<td>' .
        ($lot > 0
            ? '<a href="' .
                owt_h(dol_buildpath('/product/stock/productlot_card.php', 1) . '?id=' . $lot) .
                '">' .
                owt_h($item['native_batch'] ?? '') .
                '</a>'
            : '') .
        '</td>';
    print '<td><a href="' .
        owt_h(dol_buildpath('/product/stock/card.php', 1) . '?id=' . $wid) .
        '">' .
        owt_h($view['warehouses'][$wid]['ref'] ?? '') .
        '</a></td>';
    print '<td>';
    if (!empty($item['bin_key']) && owt_locations_right($user)) {
        print '<a href="' .
            owt_h(owt_related_url('warehouse', $wid, ['bin_key' => $item['bin_key']])) .
            '">' .
            owt_h($item['bin']) .
            '</a>';
    } else {
        print owt_h($item['bin'] ?? '');
    }
    if (owt_permission_right($user, 'modify')) {
        print ' <a href="' .
            owt_h(owt_app_link('edit/' . $code)) .
            '">' .
            owt_h(owt_t('Change location')) .
            '</a>';
    }
    print '</td><td>' .
        owt_h($item['qty'] ?? '?') .
        '</td><td>' .
        owt_h(owt_t($status)) .
        '</td></tr>';
}
if (!$items) {
    print '<tr><td colspan="7">' . owt_h(owt_t('No related QR entries.')) . '</td></tr>';
}
print '</tbody></table></div>';
if ($page > 1) {
    print '<a class="butAction" href="' .
        owt_h(
            owt_related_url($kind, $id, ['bin_key' => $binKey, 'q' => $query, 'page' => $page - 1]),
        ) .
        '">' .
        owt_h(owt_t('Previous')) .
        '</a> ';
}
if ($page < $pages) {
    print '<a class="butAction" href="' .
        owt_h(
            owt_related_url($kind, $id, ['bin_key' => $binKey, 'q' => $query, 'page' => $page + 1]),
        ) .
        '">' .
        owt_h(owt_t('Next')) .
        '</a>';
}
if ($head) {
    print dol_get_fiche_end();
}
llxFooter();
