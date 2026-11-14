<?php
/** Same-origin Angular gateway. All native facts and service identities come from Dolibarr. */
$res = 0;
if (is_file(__DIR__ . '/../../main.inc.php')) {
    $res = include __DIR__ . '/../../main.inc.php';
}
if (!$res) {
    http_response_code(503);
    exit('Dolibarr bootstrap unavailable');
}
require_once __DIR__ . '/lib/owt.lib.php';
require_once __DIR__ . '/lib/owt_labels.lib.php';
require_once __DIR__ . '/lib/owt_creation.lib.php';
require_once __DIR__ . '/lib/owt_product_search.lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function owt_api_permit($allowed) {
    if (!$allowed) {
        throw new DomainException('Missing Dolibarr permission');
    }
}
function owt_api_translations() {
    global $langs;
    $result = [];
    if (isset($langs) && is_object($langs) && method_exists($langs, 'load')) {
        $langs->load('owt@owt');
    }
    foreach (file(__DIR__ . '/langs/en_US/owt.lang', FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^(OWTUI_[^=]+)=(.*)$/', $line, $m)) {
            continue;
        }
        $english = owt_plain_translation($m[2]);
        $result[$english] = owt_translation_text($m[1], $m[2]);
    }
    $locale = substr((string) ($langs->defaultlang ?? 'en_US'), 0, 2);
    $extra = __DIR__ . '/langs/' . $locale . '.spa.json';
    if (is_file($extra)) {
        $result = array_merge(
            $result,
            array_map(
                'owt_plain_translation',
                json_decode(file_get_contents($extra), true, 512, JSON_THROW_ON_ERROR),
            ),
        );
    }
    return (object) $result;
}
/** Read resources return authorized projections, never a raw private-service document. */
function owt_api_get($resource) {
    global $db, $user, $langs;
    if ($resource === 'print-translations') {
        owt_api_permit(owt_inventory_right($user));
        return (object) owt_print_translations(owt_print_locale(GETPOST('lang', 'alphanohtml')));
    }
    if ($resource === 'history') {
        owt_api_permit(owt_history_right($user));
        $code = trim((string) GETPOST('code', 'alphanohtml'));
        if ($code !== '' && !owt_code_valid($code)) {
            throw new InvalidArgumentException('Invalid QR identifier');
        }
        return owt_service('POST', 'query-history', [
            'visibility' => !empty($user->admin) ? null : (object) owt_history_visibility($db),
            'code' => $code,
            'page' => max(1, (int) GETPOST('page', 'int')),
            'page_size' => 50,
            'q' => trim((string) GETPOST('q', 'alphanohtml')),
            'action' => (string) GETPOST('event', 'alphanohtml'),
            'user_id' => (int) GETPOST('user_id', 'int'),
            'from' => (string) GETPOST('from', 'alphanohtml'),
            'to' => (string) GETPOST('to', 'alphanohtml'),
        ]);
    }
    if ($resource === 'bootstrap') {
        $inventory = owt_browser_inventory($db);
        $inventory['products'] = owt_product_search_metadata($db, $inventory['products']);
        $state = $inventory['state'];
        foreach (['items', 'batches', 'personal_warehouses', 'location_label_settings'] as $map) {
            $state[$map] = (object) ($state[$map] ?? []);
        }
        return [
            'state' => $state,
            'consumption_references' => (object) $inventory['consumption_references'],
            'label_formats' => owt_label_profiles(),
            'barcode_formats' => owt_barcode_formats(),
            'location_import_available' =>
                !empty($user->admin) &&
                isModEnabled('binloc') &&
                is_file(dol_buildpath('/binloc/class/binlocwarehouselevel.class.php', 0)),
            'warehouses' => (object) $inventory['warehouses'],
            'users' => (object) $inventory['users'],
            'products' => (object) $inventory['products'],
            'permissions' => [
                ...array_combine(
                    owt_permission_keys(),
                    array_map(
                        fn($right) => owt_permission_right($user, $right),
                        owt_permission_keys(),
                    ),
                ),
                'use' => owt_read_right($user),
                'manage' => owt_write_right($user),
                'stock' => owt_stock_write_right($user),
                'admin' => !empty($user->admin),
                'history' => owt_history_right($user),
                'orders' => owt_orders_right($user),
                'locations' => owt_locations_right($user),
                'users' => owt_users_read_all($user),
                'references' => [
                    'salesorder' => owt_reference_right($user, 'salesorder'),
                    'production' => owt_reference_right($user, 'production'),
                    'shipment' => owt_reference_right($user, 'shipment'),
                ],
            ],
            'csrf' => owt_token(),
            'token' => newToken(),
            'locale' => (string) ($langs->defaultlang ?? 'en_US'),
            'translations' => owt_api_translations(),
        ];
    }
    if ($resource === 'serial-check') {
        owt_api_permit(
            owt_permission_right($user, 'create') || owt_permission_right($user, 'modify'),
        );
        $serial = trim((string) GETPOST('serial', 'alphanohtml'));
        if ($serial === '' || strlen($serial) > 100) {
            throw new InvalidArgumentException('Enter a serial number of at most 100 bytes');
        }
        $pid = (int) GETPOST('product_id', 'int');
        $exists = owt_native_serial_exists($db, $pid, $serial);
        $associated = false;
        if ($exists) {
            foreach (owt_load_state()['items'] as $item) {
                if (
                    (int) $item['product_id'] === $pid &&
                    strcasecmp((string) ($item['native_batch'] ?? ''), $serial) === 0
                ) {
                    $associated = true;
                    break;
                }
            }
        }
        return ['exists' => $exists, 'associated' => $associated];
    }
    if ($resource === 'levels') {
        owt_api_permit(owt_locations_right($user));
        $wid = (int) GETPOST('warehouse_id', 'int');
        $warehouses = owt_warehouses($db);
        if (!isset($warehouses[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        return owt_location_levels($db, $wid);
    }
    $q = trim((string) GETPOST('q', 'alphanohtml'));
    if (strlen($q) > 100) {
        throw new InvalidArgumentException('Search is too long');
    }
    if ($resource === 'products') {
        $sql =
            'SELECT rowid,ref,label FROM ' .
            MAIN_DB_PREFIX .
            'product WHERE entity IN (' .
            getEntity('product') .
            ') AND fk_product_type=0';
        if ($q !== '') {
            $sql .=
                " AND (ref LIKE '%" .
                $db->escape($q) .
                "%' OR label LIKE '%" .
                $db->escape($q) .
                "%')";
        }
        $sql .= ' ORDER BY ref LIMIT 50';
    } elseif ($resource === 'orders') {
        owt_api_permit(owt_orders_right($user));
        $sql =
            'SELECT c.rowid,c.ref,s.nom AS customer FROM ' .
            MAIN_DB_PREFIX .
            'commande c LEFT JOIN ' .
            MAIN_DB_PREFIX .
            'societe s ON s.rowid=c.fk_soc WHERE c.entity IN (' .
            getEntity('commande') .
            ')';
        if ($q !== '') {
            $sql .=
                " AND (c.ref LIKE '%" .
                $db->escape($q) .
                "%' OR s.nom LIKE '%" .
                $db->escape($q) .
                "%')";
        }
        $sql .= ' ORDER BY c.rowid DESC LIMIT 50';
    } elseif ($resource === 'references' || $resource === 'manufacturing-orders') {
        $type =
            $resource === 'manufacturing-orders'
                ? 'production'
                : (string) GETPOST('type', 'alphanohtml');
        $schema = owt_reference_schema($type);
        owt_api_permit(owt_reference_right($user, $type));
        $sql =
            'SELECT rowid,ref FROM ' .
            MAIN_DB_PREFIX .
            $schema .
            ' WHERE entity=' .
            (int) $GLOBALS['conf']->entity;
        if ($q !== '') {
            $sql .= " AND ref LIKE '%" . $db->escape($q) . "%'";
        }
        $sql .= ' ORDER BY rowid DESC LIMIT 100';
    } elseif ($resource === 'physical-bins') {
        owt_api_permit(owt_locations_right($user));
        return owt_physical_bins($db);
    } elseif ($resource === 'location-qr') {
        owt_api_permit(owt_locations_right($user));
        $location = owt_resolve_location_qr($db, (string) GETPOST('payload', 'alphanohtml'));
        $state = owt_browser_inventory($db)['state'];
        $products = owt_products_index($db, array_column($state['items'], 'product_id'));
        $location['summary'] = owt_bin_summary(
            $state['items'],
            $location['warehouse_id'],
            $location['bin'],
            $products,
        );
        $location['pending_count'] = count(
            array_filter(
                $state['items'],
                fn($item) => (int) $item['warehouse_id'] === (int) $location['warehouse_id'] &&
                    $item['bin'] === $location['bin'] &&
                    (!empty($item['pending_operation']) ||
                        !empty($item['pending_handoff']) ||
                        !empty($item['pending_bin_move'])),
            ),
        );
        $location['updated_at'] = gmdate('c');
        return $location;
    } elseif ($resource === 'locations') {
        owt_api_permit(owt_locations_right($user));
        $wid = (int) GETPOST('warehouse_id', 'int');
        $warehouses = owt_warehouses($db);
        if (!isset($warehouses[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        $bin = (string) GETPOST('bin', 'alphanohtml');
        $state = owt_browser_inventory($db)['state'];
        $products = owt_products_index($db, array_column($state['items'], 'product_id'));
        return [
            'bins' => owt_known_bins($state['items'], $wid),
            'summary' => owt_bin_summary(
                $state['items'],
                $wid,
                $bin,
                $products,
                GETPOST('level_id', 'int'),
                GETPOST('level_value', 'alphanohtml'),
            ),
            'qr' => owt_warehouse_summary($state['items'], $wid),
            'native' => (object) owt_stock_totals($db, array_keys($products)),
        ];
    } elseif ($resource === 'pick-lists') {
        owt_api_permit(owt_orders_right($user));
        $lists = owt_command('query-pick-lists', []);
        return array_values(
            array_filter(
                $lists,
                fn($list) => owt_reference_visible($user, 'salesorder', (int) $list['order_id']),
            ),
        );
    } elseif ($resource === 'picking') {
        $type = (string) GETPOST('order_type', 'alphanohtml') ?: 'salesorder';
        if (!in_array($type, ['salesorder', 'production'], true)) {
            throw new InvalidArgumentException('Unsupported picking source');
        }
        owt_api_permit(owt_reference_right($user, $type));
        $wid = (int) GETPOST('warehouse_id', 'int');
        $warehouses = owt_warehouses($db);
        if (!isset($warehouses[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        $order = owt_order_lines($db, (int) GETPOST('order_id', 'int'), $type);
        $state = owt_browser_inventory($db)['state'];
        $products = owt_products_index($db, array_column($order['lines'], 'product_id'));
        $lines = array_values(
            array_filter($order['lines'], fn($line) => isset($products[$line['product_id']])),
        );
        return owt_pick_proposal(
            $lines,
            $state['items'],
            $wid,
            $products,
            $type === 'salesorder' ? (int) GETPOST('order_id', 'int') : 0,
        );
    } else {
        throw new InvalidArgumentException('Unknown resource');
    }
    $rs = $db->query($sql);
    if (!$rs) {
        throw new RuntimeException('Dolibarr lookup unavailable');
    }
    $out = [];
    while ($row = $db->fetch_object($rs)) {
        if (
            in_array($resource, ['orders', 'references', 'manufacturing-orders'], true) &&
            !owt_reference_visible(
                $user,
                $resource === 'orders' ? 'salesorder' : $type,
                (int) $row->rowid,
            )
        ) {
            continue;
        }
        if ($resource === 'products' && !owt_product($db, (int) $row->rowid)) {
            continue;
        }
        $out[] = [
            'id' => (int) $row->rowid,
            'text' =>
                (string) $row->ref .
                (isset($row->label)
                    ? ' — ' . $row->label
                    : (isset($row->customer)
                        ? ' — ' . $row->customer
                        : '')),
        ];
        if ($resource === 'products') {
            $out[array_key_last($out)]['label'] = (string) $row->label;
            $out[array_key_last($out)]['ref'] = (string) $row->ref;
        }
    }
    $db->free($rs);
    return $out;
}
/**
 * Browser command boundary: check the independent grant and rebuild command-specific fields.
 * Native catalogs, proofs, choices and service actor headers must come from server helpers.
 * The private protocol is documented in docs/code/api-contracts.md and synchronization.md.
 */
function owt_api_post($action, $data) {
    global $db, $user;
    owt_require_operation_right($user, $action, $data);
    if (
        in_array($action, ['physical-bin-create', 'physical-bin-assign', 'physical-bin-move'], true)
    ) {
        owt_api_permit(owt_locations_right($user));
        if ($action === 'physical-bin-create') {
            $wid = owt_api_int($data, 'warehouse_id');
            if (!isset(owt_warehouses($db)[$wid])) {
                throw new InvalidArgumentException('Unknown warehouse');
            }
            return owt_command($action, [
                'label' => trim(strip_tags((string) ($data['label'] ?? ''))),
                'warehouse_id' => $wid,
                'choice' => owt_location_choice($db, $wid, $data['levels'] ?? []),
            ]);
        }
        $bin = owt_physical_bin($db, (string) ($data['bin_id'] ?? ''));
        if ($action === 'physical-bin-assign') {
            $codes = owt_workflow_codes($data['codes'] ?? []);
            $visible = owt_browser_inventory($db)['state']['items'];
            foreach ($codes as $code) {
                if (!isset($visible[$code])) {
                    throw new DomainException('Item is unavailable');
                }
            }
            return owt_command($action, [
                'bin_id' => $bin['id'],
                'codes' => $codes,
                'remove' => !empty($data['remove']),
            ]);
        }
        owt_api_permit(owt_stock_write_right($user));
        $wid = owt_api_int($data, 'warehouse_id');
        $choice = !empty($bin['pending_move'])
            ? $bin['pending_move']['target']
            : owt_location_choice($db, $wid, $data['levels'] ?? []);
        if (!empty($bin['pending_move'])) {
            $wid = (int) $choice['warehouse_id'];
        }
        if (!isset(owt_warehouses($db)[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        $move = owt_command('physical-bin-move-start', [
            'bin_id' => $bin['id'],
            'warehouse_id' => $wid,
            'choice' => $choice,
        ]);
        $results = [];
        $target = $move['target'];
        foreach ($move['codes'] as $code) {
            try {
                $item = owt_load_state()['items'][$code];
                owt_require_item_visible($db, $item);
                if (!empty($item['pending_operation']) || !empty($item['pending_handoff'])) {
                    throw new RuntimeException(
                        'Reconcile the pending item operation before resuming the bin move',
                    );
                }
                if (
                    (int) $item['warehouse_id'] !== (int) $target['warehouse_id'] ||
                    $item['bin_key'] !== $target['bin_key'] ||
                    $item['bin'] !== $target['bin']
                ) {
                    owt_run_inventory_action(
                        $db,
                        $user,
                        $code,
                        'move',
                        [
                            'warehouse_id' => $target['warehouse_id'],
                            'choice' => $target,
                            'reason' => 'Move physical bin ' . $bin['id'],
                            'physical_bin_move' => $bin['id'],
                        ],
                        owt_warehouses($db),
                    );
                }
                $results[] = ['code' => $code, 'ok' => true, 'message' => 'Completed'];
            } catch (Throwable $e) {
                $results[] = ['code' => $code, 'ok' => false, 'message' => owt_public_error($e)];
            }
        }
        $complete = !array_filter($results, fn($result) => !$result['ok']);
        if ($complete) {
            owt_command('physical-bin-move-finish', ['bin_id' => $bin['id']]);
        }
        return ['complete' => $complete, 'results' => $results];
    }
    if ($action === 'pick-list-save' || $action === 'pick-list-complete') {
        owt_api_permit(owt_orders_right($user));
        $orderId = owt_api_int($data, 'order_id');
        owt_check_reference($db, $user, 'salesorder', $orderId);
        if ($action === 'pick-list-complete') {
            return owt_command($action, [
                'order_id' => $orderId,
                'reason' => trim(strip_tags((string) ($data['reason'] ?? ''))),
            ]);
        }
        $wid = owt_api_int($data, 'warehouse_id');
        if (!isset(owt_warehouses($db)[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        $order = owt_order_lines($db, $orderId);
        return owt_command($action, [
            'order_id' => $orderId,
            'warehouse_id' => $wid,
            'lines' => $order['lines'],
        ]);
    }
    if (in_array($action, ['native-recover', 'native-cancel'], true)) {
        $pending = owt_service('GET', '')['pending_native'] ?? null;
        if (!$pending) {
            throw new InvalidArgumentException('No pending synchronization');
        }
        owt_require_operation_right($user, $pending['action']);
        if (time() - strtotime($pending['at']) < 30) {
            throw new RuntimeException(
                'Synchronization may still be running; wait 30 seconds before recovery',
            );
        }
        return $action === 'native-cancel'
            ? owt_native_cancel($db, $user, $pending)
            : owt_native_complete($db, $user, $pending);
    }
    // Never forward the browser's object wholesale to the C# service.
    if ($action === 'delete-unassigned') {
        $code = owt_input_code($data['code'] ?? '');
        owt_require_item_visible($db, owt_load_state()['items'][$code] ?? null);
        return owt_command('delete-unassigned', ['code' => $code]);
    }
    if (in_array($action, ['create', 'update', 'bulk', 'register'], true)) {
        $code = owt_input_code($data['code'] ?? '');
        $existing = null;
        if ($action === 'update') {
            $existing = owt_load_state()['items'][$code] ?? null;
            owt_require_item_visible($db, $existing);
        }
        $fields =
            $action === 'bulk' ? owt_api_bulk_fields($data) : owt_api_fields($data, $existing);
        if ($action === 'update') {
            $fields['code'] = $code;
        }
        if ($action === 'register') {
            $codes = owt_workflow_codes($data['codes'] ?? []);
            $current = owt_load_state()['items'];
            foreach ($codes as $selected) {
                owt_require_item_visible($db, $current[$selected] ?? null);
            }
            $posted = [
                'label' => $fields['label'],
                'product_id' => $fields['product_id'],
                'warehouse_id' => $fields['warehouse_id'],
                'qty' => $fields['qty'],
                'serial' => $fields['serial'],
                'details' => $fields['details'],
                'native_batch' => $fields['native_batch'],
                'owt_levels' => $data['levels'] ?? [],
            ];
            $validated = owt_registration_fields(
                $db,
                $user,
                $posted,
                owt_warehouses($db),
                $fields['type'],
            );
            $bytes =
                $fields['type'] === 'tracked'
                    ? owt_thumbnail_decode($data['thumbnail'] ?? null)
                    : null;
            $registered = owt_register_codes($db, $user, $codes, $fields['type'], $validated);
            $warning = '';
            if ($bytes !== null) {
                try {
                    owt_thumbnail_store($registered[0], $bytes);
                } catch (Throwable $e) {
                    $warning = 'Registration saved, but the thumbnail could not be stored.';
                }
            }
            return ['codes' => $registered, 'warning' => $warning];
        }
        if ($action === 'update' && !empty($existing['product_lot_id'])) {
            // QR metadata and internal locations do not change native lot identities or stock.
            return owt_command('update', $fields);
        }
        return in_array($fields['type'], ['tracked', 'box'], true)
            ? owt_native_command($db, $user, $action, $fields)
            : owt_command($action, $fields);
    }
    if ($action === 'count') {
        $fields = [
            'code' => owt_input_code($data['code'] ?? ''),
            'quantity' => owt_api_int($data, 'quantity', -1),
            'reason' => (string) ($data['reason'] ?? ''),
        ];
        $item = owt_load_state()['items'][$fields['code']] ?? null;
        owt_require_item_visible($db, $item);
        return !empty($item['product_lot_id'])
            ? owt_native_command($db, $user, 'count', $fields)
            : owt_command('count', $fields);
    }
    if ($action === 'box-transfer') {
        $fields = [
            'from' => owt_input_code($data['from'] ?? ''),
            'to' => owt_input_code($data['to'] ?? ''),
            'quantity' => owt_api_int($data, 'quantity'),
        ];
        $current = owt_load_state()['items'];
        owt_require_item_visible($db, $current[$fields['from']] ?? null);
        owt_require_item_visible($db, $current[$fields['to']] ?? null);
        return owt_native_command($db, $user, 'box-transfer', $fields);
    }
    if ($action === 'mapping') {
        owt_api_permit(!empty($user->admin));
        return owt_command('mapping', [
            'user_id' => owt_api_int($data, 'user_id'),
            'warehouse_id' => owt_api_int($data, 'warehouse_id'),
            'users' => (object) owt_personal_users($db),
            'warehouses' => (object) owt_warehouses($db),
        ]);
    }
    if ($action === 'label-settings') {
        owt_api_permit(owt_locations_right($user));
        $wid = owt_api_int($data, 'warehouse_id');
        $warehouses = owt_warehouses($db);
        if (!isset($warehouses[$wid])) {
            throw new InvalidArgumentException('Unknown warehouse');
        }
        $prefs = $data['format'] ?? [];
        $prefs['enabled'] = true;
        return owt_command('label-settings', [
            'warehouse_id' => $wid,
            'prefs' => (object) $prefs,
            'warehouses' => (object) $warehouses,
        ]);
    }
    if ($action === 'location-configure') {
        return owt_command('location-configure', [
            'warehouse_id' => owt_api_int($data, 'warehouse_id'),
            'names' => $data['names'] ?? [],
            'datatypes' => $data['datatypes'] ?? null,
            'option_additions' => $data['option_additions'] ?? null,
            'options' => (object) ($data['options'] ?? []),
            'warehouses' => (object) owt_warehouses($db),
        ]);
    }
    if ($action === 'location-import') {
        owt_api_permit(!empty($user->admin));
        require_once __DIR__ . '/lib/owt_location_import.lib.php';
        return owt_command('location-import', owt_location_import($db));
    }
    if ($action === 'reconcile') {
        owt_api_permit(owt_stock_write_right($user));
        $code = owt_input_code($data['code'] ?? '');
        $entry = owt_load_state()['items'][$code] ?? [];
        owt_require_item_visible($db, $entry);
        $pending = $entry['pending_handoff'] ?? ($entry['pending_operation'] ?? []);
        owt_require_operation_right($user, 'workflow', ['kind' => $pending['kind'] ?? '']);
        if (!empty($entry['pending_handoff'])) {
            return owt_reconcile_handoff($db, $user, $code, $entry['pending_handoff']);
        }
        return owt_reconcile_inventory_action($db, $user, $code);
    }
    if ($action !== 'workflow') {
        throw new InvalidArgumentException('Unknown action');
    }
    $kind = (string) ($data['kind'] ?? '');
    if (!in_array($kind, ['move', 'consume', 'return', 'correct', 'checkout', 'checkin'], true)) {
        throw new InvalidArgumentException('Unknown workflow');
    }
    $codes = owt_workflow_codes($data['codes'] ?? []);
    if (!$codes) {
        throw new InvalidArgumentException('Scan at least one QR label');
    }
    $warehouses = owt_warehouses($db);
    $users = owt_personal_users($db);
    $reason = trim(strip_tags((string) ($data['reason'] ?? '')));
    if (strlen($reason) > 200) {
        throw new InvalidArgumentException('Reason is too long');
    }
    $person = owt_api_int($data, 'person_id');
    $wid = owt_api_int($data, 'warehouse_id');
    if (!owt_locations_right($user) && !empty($data['levels'])) {
        throw new DomainException('Location access required');
    }
    $choice = owt_location_choice($db, $wid, $data['levels'] ?? []);
    $ref = (string) ($data['reference_type'] ?? '');
    $refId = owt_api_int($data, 'reference_id');
    $pick = null;
    $pickOrder = owt_api_int($data, 'pick_order_id');
    if ($pickOrder) {
        if ($kind !== 'consume') {
            throw new InvalidArgumentException('Pick lists are only supported for consumption');
        }
        owt_api_permit(owt_orders_right($user));
        owt_check_reference($db, $user, 'salesorder', $pickOrder);
        $order = owt_order_lines($db, $pickOrder);
        $pickWid = owt_api_int($data, 'pick_warehouse_id');
        if (!isset($warehouses[$pickWid])) {
            throw new InvalidArgumentException('Unknown pick warehouse');
        }
        $pick = [
            'lines' => $order['lines'],
            'order_id' => $pickOrder,
            'warehouse_id' => $pickWid,
            'visible_codes' => owt_item_codes(owt_browser_inventory($db)['state']['items']),
        ];
        $ref = 'salesorder';
        $refId = $pickOrder;
    }
    if ($ref !== '') {
        owt_check_reference($db, $user, $ref, $refId);
    }
    if ($kind === 'checkout') {
        owt_api_permit(owt_stock_write_right($user));
        owt_ensure_personal_warehouse($db, $user, $person, $users);
        $warehouses = owt_warehouses($db);
    }
    $results = [];
    foreach ($codes as $code) {
        try {
            $item = owt_load_state()['items'][$code] ?? null;
            if (!$item) {
                throw new RuntimeException('Unknown QR label');
            }
            owt_require_item_visible($db, $item);
            if ($kind === 'checkout' || $kind === 'checkin') {
                owt_api_permit(owt_stock_write_right($user));
                $borrower =
                    $kind === 'checkin' ? (int) ($item['checkout']['user_id'] ?? 0) : $person;
                $pending = owt_stage_handoff(
                    $db,
                    $user,
                    $code,
                    $kind,
                    $borrower,
                    $reason,
                    $warehouses,
                    $users,
                );
                owt_do_native_transfer($db, $user, $item, $pending);
                owt_finish_handoff($db, $user, $code, $pending);
            } else {
                $quantities = $data['quantities'] ?? [];
                $quantity = owt_api_int($quantities, $code, $item['type'] === 'box' ? 0 : 1);
                owt_run_inventory_action(
                    $db,
                    $user,
                    $code,
                    $kind,
                    [
                        'warehouse_id' => $wid,
                        'choice' => $choice,
                        'quantity' => $quantity,
                        'reason' => $reason,
                        'post_stock' => !empty($data['post_stock']),
                        'reference_type' => $ref,
                        'reference_id' => $refId,
                        'pick_list' => $pick,
                    ],
                    $warehouses,
                );
            }
            $results[] = ['code' => $code, 'ok' => true, 'message' => 'Completed'];
        } catch (Throwable $e) {
            $results[] = ['code' => $code, 'ok' => false, 'message' => owt_public_error($e)];
        }
    }
    return $results;
}
// Native bootstrap has already established the session. All methods still need inventory
// access; POST additionally validates the module token and bounds the decoded request size.
try {
    owt_api_permit(owt_inventory_right($user));
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $response = owt_api_get((string) GETPOST('resource', 'alphanohtml'));
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        owt_check_post();
        $payload = (string) ($_POST['payload'] ?? '');
        if (strlen($payload) > 262144) {
            throw new InvalidArgumentException('Request too large');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Expected operation data');
        }
        $response = ['result' => owt_api_post((string) GETPOST('action', 'alphanohtml'), $data)];
    } else {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new InvalidArgumentException('Unsupported method');
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(
            $e instanceof DomainException
                ? 403
                : ($e instanceof InvalidArgumentException || $e instanceof JsonException
                    ? 400
                    : 409),
        );
    }
    echo json_encode(['message' => owt_public_error($e)], JSON_HEX_TAG | JSON_HEX_AMP);
}
