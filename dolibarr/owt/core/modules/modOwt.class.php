<?php
/** Open Warehouse Tracker (OWT), a native Dolibarr warehouse extension for Dolibarr 21+. */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit();
}
require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
class modOwt extends DolibarrModules {
    public function __construct($db) {
        $this->db = $db;
        $this->numero = 503871;
        $this->rights_class = 'owt';
        $this->family = 'products';
        $this->module_position = '91';
        $this->name = 'Owt';
        $this->description =
            'Native Dolibarr warehouse tracking: barcode identification, stock workflows, locations, physical bins, picking, loans and operation history.';
        $this->version = '0.4.0';
        $this->phpmin = [8, 0];
        $this->need_dolibarr_version = [21, 0, 2];
        $this->const_name = 'MAIN_MODULE_OWT';
        // The fa- prefix registers native icon metadata. The top-menu prefix below
        // supplies the glyph markup; registration alone only hides the generic fallback.
        $this->picto = 'fa-warehouse';
        $this->langfiles = ['owt@owt'];
        $this->dirs = [];
        $this->config_page_url = ['setup.php@owt'];
        $this->depends = ['modProduct', 'modStock', 'modProductBatch'];
        $this->requiredby = [];
        $this->conflictwith = [];
        $this->module_parts = [
            'hooks' => [
                'data' => [
                    'productcard',
                    'warehousecard',
                    'productlotcard',
                    'product_lotlist',
                    'usercard',
                    'ordercard',
                    'mocard',
                    'expeditioncard',
                ],
            ],
        ];
        $this->const = [];
        $this->tabs = [];
        // Native dol_eval only permits selected user properties/methods. Keep this
        // expression within that contract; internal-only menus use user=0 below,
        // and every module endpoint independently rejects external/inactive users.
        $warehouseRead =
            'isModEnabled("owt") && isModEnabled("product") && isModEnabled("stock") && isModEnabled("productbatch") && $user->id > 0 && ($user->admin || ($user->hasRight("owt", "use") && $user->hasRight("owt", "read") && $user->hasRight("produit", "lire") && $user->hasRight("stock", "lire")))';
        foreach (
            ['product' => 'product', 'stock' => 'warehouse', 'productlot' => 'lot']
            as $native => $kind
        ) {
            $this->tabs[] = [
                'data' =>
                    $native .
                    ':+owt:OWTRelatedEntries:owt@owt:' .
                    $warehouseRead .
                    ':/owt/native.php?kind=' .
                    $kind .
                    '&id=__ID__',
            ];
        }
        $this->tabs[] = [
            'data' =>
                'stock:+owtlocations:Locations:owt@owt:' .
                $warehouseRead .
                ':/owt/index.php#/locations?warehouse_id=__ID__',
        ];
        // Numeric IDs persist in user/group grants. Keep identities stable when adding rights;
        // manage controls configuration and must never imply the independent action grants.
        $this->rights = [];
        $this->rights[] = [5038711, 'Read warehouse inventory and scan labels', 'r', 1, 'read'];
        $this->rights[] = [
            5038712,
            'Configure QR locations, label formats and module administration',
            'w',
            0,
            'manage',
        ];
        $this->rights[] = [5038713, 'View QR operation history', 'r', 0, 'history'];
        $this->rights[] = [5038714, 'Use Open Warehouse Tracker', 'r', 0, 'use'];
        $this->rights[] = [
            5038715,
            'Create QR labels, items and physical bins; register labels',
            'w',
            0,
            'create',
        ];
        $this->rights[] = [
            5038716,
            'Modify QR metadata, locations and bin membership',
            'w',
            0,
            'modify',
        ];
        $this->rights[] = [5038717, 'Delete unused QR labels', 'd', 0, 'delete'];
        $this->rights[] = [5038718, 'Move warehouse inventory and physical bins', 'w', 0, 'move'];
        $this->rights[] = [5038719, 'Consume warehouse inventory', 'w', 0, 'consume'];
        $this->rights[] = [50387110, 'Return consumed warehouse inventory', 'w', 0, 'return'];
        $this->rights[] = [
            50387111,
            'Count, correct and transfer container quantities',
            'w',
            0,
            'adjust',
        ];
        $this->rights[] = [50387112, 'Check out and check in employee loans', 'w', 0, 'loans'];
        $this->rights[] = [50387113, 'Save and manually complete pick lists', 'w', 0, 'picking'];
        $this->rights[] = [50387114, 'Print labels and inventory reports', 'r', 0, 'print'];
        $this->rights[] = [
            50387115,
            'Reconcile and recover pending QR operations',
            'w',
            0,
            'recover',
        ];
        $this->menu = [
            [
                'fk_menu' => 0,
                'type' => 'top',
                'titre' => 'Open Warehouse Tracker',
                'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
                'mainmenu' => 'owt',
                'leftmenu' => 'owt',
                'url' => '/owt/index.php',
                'langs' => 'owt@owt',
                'position' => 120,
                'enabled' => 'isModEnabled("owt")',
                'perms' => $warehouseRead,
                'target' => '',
                'user' => 0,
            ],
        ];
        $this->menu[] = [
            'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
            'type' => 'left',
            'titre' => 'OWTRelatedEntries',
            'mainmenu' => 'products',
            'leftmenu' => 'owt_inventory',
            'url' => '/owt/index.php#/inventory',
            'langs' => 'owt@owt',
            'position' => 150,
            'enabled' => 'isModEnabled("owt")',
            'perms' => $warehouseRead,
            'target' => '',
            'user' => 0,
        ];
    }
    public function init($options = '') {
        if ($this->_load_tables('/owt/sql/') < 0) {
            return -1;
        }
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extra = new ExtraFields($this->db);
        $extra->fetch_name_optionals_label('product');
        if (!empty($extra->error)) {
            $this->error = $extra->error;
            return -1;
        }
        if (!isset($extra->attributes['product']['label']['owt_serial_prefix'])) {
            if (
                $extra->addExtraField(
                    'owt_serial_prefix',
                    'OWTSerialPrefix',
                    'varchar',
                    100,
                    76,
                    'product',
                    0,
                    0,
                    '',
                    '',
                    0,
                    '',
                    '3',
                    'OWTSerialPrefixHelp',
                    '',
                    '',
                    'owt@owt',
                    'isModEnabled("owt")',
                ) <= 0
            ) {
                $this->error = $extra->error;
                return -1;
            }
        }
        return $this->_init([], $options);
    }
    public function remove($options = '') {
        return $this->_remove([], $options);
    }
}
