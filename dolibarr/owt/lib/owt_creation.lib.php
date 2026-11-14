<?php
/** Convert creation requests to trusted service fields and native batch identities. */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit();
}

function owt_creation_text($data, $key) {
    $value = $data[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid ' . $key);
    }
    return trim(strip_tags($value));
}

function owt_api_int($data, $key, $default = 0) {
    $value = $data[$key] ?? $default;
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Invalid ' . $key);
    }
    return (int) $value;
}
/** Read the product-owned setting; never accept a serial prefix from the browser. */
function owt_creation_serial_prefix($db, $productId) {
    $product = owt_product($db, $productId);
    if (!$product) {
        throw new DomainException('Physical product inaccessible');
    }
    if ($product->fetch_optionals() < 0) {
        throw new RuntimeException('Cannot read product serial prefix');
    }
    return (string) ($product->array_options['options_owt_serial_prefix'] ?? '');
}
/** Allowlist user choices and resolve native/location facts before calling domain commands. */
function owt_api_fields($data, $existing = null, $prepared = null) {
    global $db, $user;
    $type = $data['type'] ?? 'generic';
    if (!is_string($type)) {
        throw new InvalidArgumentException('Invalid type');
    }
    $pid = owt_api_int($data, 'product_id');
    $wid = owt_api_int($data, 'warehouse_id');
    $qty =
        $type === 'box'
            ? (($data['qty'] ?? null) === null
                ? null
                : owt_api_int($data, 'qty'))
            : ($type === 'unassigned'
                ? 0
                : 1);
    if (!owt_locations_right($user) && (!empty($data['levels']) || !empty($data['keep_legacy']))) {
        throw new DomainException('Location access required');
    }
    $choice = owt_location_choice(
        $db,
        $wid,
        $data['levels'] ?? [],
        $existing,
        !empty($data['keep_legacy']),
    );
    if (!owt_locations_right($user) && $existing && $wid === (int) $existing['warehouse_id']) {
        $choice = array_intersect_key($existing, array_flip(['bin', 'bin_key', 'bin_values']));
    }
    if ($prepared === null) {
        $serial = owt_creation_text($data, 'serial');
        $linked =
            $type === 'tracked' && $serial !== '' && owt_native_serial_exists($db, $pid, $serial);
        $prepared = owt_service('POST', 'prepare-creation', [
            'operation' => $existing ? 'update' : 'create',
            'type' => $type,
            'label' => $data['label'] ?? '',
            'details' => $data['details'] ?? '',
            'serial' => $serial,
            'serial_prefix' => $type === 'tracked' ? owt_creation_serial_prefix($db, $pid) : '',
            'existing_serial' => !empty($existing['product_lot_id'])
                ? (string) $existing['serial']
                : ($linked
                    ? $serial
                    : ''),
        ])['entries'][0];
    }
    if (
        !empty($prepared['generated_serial']) &&
        owt_native_serial_exists($db, $pid, $prepared['serial'])
    ) {
        throw new InvalidArgumentException('Generated serial already exists; retry creation');
    }
    return owt_service_fields(
        $type,
        $prepared['label'],
        $pid,
        $wid,
        $choice,
        $qty,
        $prepared['details'],
        $prepared['serial'],
        $prepared['native_batch'],
    );
}
/** Prepare every native identity before staging so retries reuse the same serials. */
function owt_api_bulk_fields($data) {
    global $db;
    $payload = array_intersect_key(
        $data,
        array_flip(['count', 'type', 'label', 'labels', 'details', 'serial']),
    );
    $payload['serial_prefix'] =
        ($data['type'] ?? '') === 'tracked'
            ? owt_creation_serial_prefix($db, owt_api_int($data, 'product_id'))
            : '';
    $prepared = owt_service('POST', 'prepare-creation', array_merge($payload, ['bulk' => true]));
    $entries = [];
    foreach ($prepared['entries'] as $entry) {
        $fields = owt_api_fields($data, null, $entry);
        if (
            in_array($fields['type'], ['tracked', 'box'], true) &&
            owt_native_serial_exists($db, $fields['product_id'], $fields['native_batch'])
        ) {
            throw new InvalidArgumentException(
                'A lot or serial in this batch already exists in Dolibarr',
            );
        }
        $entries[] = $fields;
    }
    return array_merge(
        $entries[0],
        ['count' => $prepared['count']],
        in_array($entries[0]['type'], ['tracked', 'box'], true) ? ['entries' => $entries] : [],
    );
}
