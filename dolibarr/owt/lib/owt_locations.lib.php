<?php
/** Thin adapter for entity-scoped location rules owned by the C# service. */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit();
}
function owt_location_levels($db, $warehouseId) {
    return owt_service('POST', 'query-location-levels', ['warehouse_id' => (int) $warehouseId]);
}
function owt_location_choice($db, $warehouseId, $posted, $previous = null, $keepLegacy = false) {
    if (
        $keepLegacy &&
        $previous &&
        (int) $previous['warehouse_id'] === (int) $warehouseId &&
        empty($previous['bin_values'])
    ) {
        return array_intersect_key($previous, array_flip(['bin', 'bin_key', 'bin_values']));
    }
    return owt_service('POST', 'query-location-choice', [
        'warehouse_id' => (int) $warehouseId,
        'levels' => (object) $posted,
        'warehouses' => (object) owt_warehouses($db),
    ]);
}
function owt_location_local_label($db, $warehouseId, $item, $state = null) {
    return owt_service('POST', 'query-location-label', [
        'code' => $item['code'] ?? '',
        'warehouse_id' => (int) $warehouseId,
        'levels' => (object) ($item['bin_values'] ?? []),
        'warehouses' => (object) owt_warehouses($db),
    ]);
}

/** Location labels encode level identities and values, independently of display formatting. */
function owt_location_qr($warehouseId, $values) {
    global $conf;
    return 'OWTLOC:1:' .
        (int) $conf->entity .
        ':' .
        (int) $warehouseId .
        ':' .
        rtrim(
            strtr(base64_encode(json_encode((object) $values, JSON_THROW_ON_ERROR)), '+/', '-_'),
            '=',
        );
}
function owt_absolute_app_url($route, $params) {
    $url = owt_app_link($route, $params);
    if (preg_match('~^https?://~', $url)) {
        return $url;
    }
    $root = parse_url(DOL_MAIN_URL_ROOT);
    return $root['scheme'] .
        '://' .
        $root['host'] .
        (isset($root['port']) ? ':' . $root['port'] : '') .
        $url;
}
function owt_location_qr_url($payload) {
    return owt_absolute_app_url('locations', ['location' => $payload]);
}
/** A scanned payload identifies a choice; recheck entity/access and current level definitions. */
function owt_resolve_location_qr($db, $payload) {
    global $conf;
    if (
        strlen($payload) > 4096 ||
        !preg_match(
            '/^OWTLOC:1:([1-9][0-9]{0,8}):([1-9][0-9]{0,8}):([A-Za-z0-9_-]+)$/D',
            $payload,
            $match,
        )
    ) {
        throw new InvalidArgumentException('Scan a Open Warehouse Tracker location label');
    }
    if ((int) $match[1] !== (int) $conf->entity) {
        throw new DomainException('Location belongs to another entity');
    }
    $wid = (int) $match[2];
    if (!isset(owt_warehouses($db)[$wid])) {
        throw new DomainException('Location warehouse is unavailable');
    }
    $decoded = base64_decode(strtr($match[3], '-_', '+/'), true);
    $values = $decoded === false ? null : json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($values) || !$values) {
        throw new InvalidArgumentException('Location label has no location levels');
    }
    $choice = owt_location_choice($db, $wid, $values);
    if (!$choice['bin_key'] || count((array) $choice['bin_values']) !== count($values)) {
        throw new InvalidArgumentException(
            'Location label does not match the current location configuration',
        );
    }
    return [
        'warehouse_id' => $wid,
        'levels' => (object) $choice['bin_values'],
        'bin' => $choice['bin'],
        'label' => owt_location_local_label($db, $wid, $choice),
    ];
}
