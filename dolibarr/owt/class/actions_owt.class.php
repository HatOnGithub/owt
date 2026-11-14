<?php
/** Native cards remain independent of the QR service until a link is opened. */
class ActionsOwt {
    public $resprints = '';
    public $results = [];
    public $error = '';
    public $errors = [];
    private $db;
    private $lotLinks = null;

    public function __construct($db) {
        $this->db = $db;
    }

    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager = null) {
        global $user;
        require_once __DIR__ . '/../lib/owt.lib.php';
        require_once __DIR__ . '/../lib/owt_related.lib.php';
        if (
            !owt_inventory_right($user) ||
            empty($object->id) ||
            (int) $object->id < 1 ||
            $action === 'create'
        ) {
            return 0;
        }
        $contexts = explode(
            ':',
            (string) ($parameters['currentcontext'] ?? ($parameters['context'] ?? '')),
        );
        $kinds = [
            'productcard' => 'product',
            'warehousecard' => 'warehouse',
            'productlotcard' => 'lot',
            'usercard' => 'user',
            'ordercard' => 'salesorder',
            'mocard' => 'production',
            'expeditioncard' => 'shipment',
        ];
        foreach ($kinds as $context => $kind) {
            if (!in_array($context, $contexts, true)) {
                continue;
            }
            try {
                owt_related_target($this->db, $user, $kind, (int) $object->id);
            } catch (Throwable $error) {
                // A QR extension must never prevent the native card from opening.
                return 0;
            }
            print '<a class="butAction" href="' .
                owt_h(owt_related_url($kind, (int) $object->id)) .
                '">' .
                owt_h(owt_t('QR entries')) .
                '</a>';
            if ($kind === 'warehouse') {
                print '<a class="butAction" href="' .
                    owt_h(owt_app_link('locations', ['warehouse_id' => (int) $object->id])) .
                    '">' .
                    owt_h(owt_t('Locations')) .
                    '</a>';
            }
            if ($kind === 'lot') {
                print $this->associatedLotLinks((int) $object->id, 'butAction');
            }
            break;
        }
        return 0;
    }

    private function serialList($parameters) {
        global $user;
        require_once __DIR__ . '/../lib/owt.lib.php';
        return owt_inventory_right($user) &&
            in_array(
                'product_lotlist',
                explode(
                    ':',
                    (string) ($parameters['currentcontext'] ?? ($parameters['context'] ?? '')),
                ),
                true,
            );
    }

    /** Cache only authorized QR projections, once per native page rather than once per row. */
    private function associatedLotLinks($id, $class = '') {
        global $user;
        require_once __DIR__ . '/../lib/owt_related.lib.php';
        try {
            $target = owt_related_target($this->db, $user, 'lot', $id);
            if ($this->lotLinks === null) {
                $this->lotLinks = [];
                $inventory = owt_browser_inventory($this->db);
                foreach ($inventory['state']['items'] as $item) {
                    $lot = (int) ($item['product_lot_id'] ?? 0);
                    if ($lot > 0) {
                        $this->lotLinks[$lot][] = $item;
                    }
                }
            }
            $links = [];
            foreach ($this->lotLinks[$id] ?? [] as $item) {
                if ((int) $item['product_id'] !== (int) $target['object']->fk_product) {
                    continue;
                }
                $links[] =
                    '<a class="' .
                    owt_h($class) .
                    '" href="' .
                    owt_h(owt_app_link('items/' . $item['code'])) .
                    '">' .
                    owt_h('QR ' . $item['code']) .
                    '</a>';
            }
            return implode(' ', $links);
        } catch (Throwable $error) {
            // The native list/card remains usable when the service or an association is inaccessible.
            return '';
        }
    }

    public function printFieldListTitle(
        $parameters,
        &$object,
        &$action = null,
        $hookmanager = null
    ) {
        $this->resprints = '';
        if ($this->serialList($parameters)) {
            $this->resprints = '<th class="liste_titre">' . owt_h(owt_t('QR entries')) . '</th>';
            if (isset($parameters['totalarray'])) {
                $parameters['totalarray']['nbfield']++;
            }
        }
        return 0;
    }

    public function printFieldListOption(
        $parameters,
        &$object,
        &$action = null,
        $hookmanager = null
    ) {
        $this->resprints = $this->serialList($parameters) ? '<td class="liste_titre"></td>' : '';
        return 0;
    }

    public function printFieldListValue(
        $parameters,
        &$object,
        &$action = null,
        $hookmanager = null
    ) {
        $this->resprints = '';
        if ($this->serialList($parameters)) {
            $id = (int) ($parameters['obj']->rowid ?? ($object->id ?? 0));
            $this->resprints = '<td>' . ($id > 0 ? $this->associatedLotLinks($id) : '') . '</td>';
            if (empty($parameters['i']) && isset($parameters['totalarray'])) {
                $parameters['totalarray']['nbfield']++;
            }
        }
        return 0;
    }
}
