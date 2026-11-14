<?php
/** Administrator-only connection settings, stored through Dolibarr's native configuration API. */
$res = 0;
if (is_file(__DIR__ . '/../../../main.inc.php')) {
    $res = include __DIR__ . '/../../../main.inc.php';
}
if (!$res) {
    http_response_code(503);
    exit('Dolibarr bootstrap unavailable');
}
if (empty($user->admin) || empty($user->id) || !empty($user->socid)) {
    accessforbidden();
}
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once __DIR__ . '/../lib/owt.lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$url = (string) ($conf->global->OWT_SERVICE_URL ?? 'http://127.0.0.1:5087');
$hasKey = !empty($conf->global->OWT_SERVICE_API_KEY);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        owt_check_post();
    } catch (RuntimeException $exception) {
        accessforbidden($exception->getMessage());
    }
    $url = (string) GETPOST('service_url', 'alphanohtml');
    // Read the password without HTML filtering: it is never rendered or logged.
    $key = (string) GETPOST('service_api_key', 'none');
    $preserveKey = $key === '';
    if ($key === '') {
        $key = (string) ($conf->global->OWT_SERVICE_API_KEY ?? '');
    }
    try {
        $settings = owt_validate_service_settings($url, $key);
        $previousUrl = $conf->global->OWT_SERVICE_URL ?? '';
        $previousKey = $conf->global->OWT_SERVICE_API_KEY ?? '';
        if ($preserveKey && $settings['url'] !== rtrim(trim((string) $previousUrl), '/')) {
            throw new InvalidArgumentException(
                'Re-enter the service API key when changing the service URL.',
            );
        }
        $db->begin();
        // Constants ending in _KEY receive Dolibarr's native secret encryption.
        if (
            dolibarr_set_const(
                $db,
                'OWT_SERVICE_URL',
                $settings['url'],
                'chaine',
                0,
                '',
                $conf->entity,
            ) <= 0 ||
            dolibarr_set_const(
                $db,
                'OWT_SERVICE_API_KEY',
                $settings['key'],
                'chaine',
                0,
                '',
                $conf->entity,
            ) <= 0
        ) {
            $db->rollback();
            $conf->global->OWT_SERVICE_URL = $previousUrl;
            $conf->global->OWT_SERVICE_API_KEY = $previousKey;
            throw new RuntimeException('Unable to save the connection settings.');
        }
        $db->commit();
        setEventMessages(owt_t('Settings saved.'), null, 'mesgs');
        header('Location: ' . dol_buildpath('/owt/admin/setup.php', 1));
        exit();
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(500);
        $error = $exception->getMessage();
    }
}

owt_page_header('Open Warehouse Tracker setup');
?>
<h1><?php print owt_h(owt_t('Open Warehouse Tracker setup')); ?></h1>
<?php if ($error !== '') { ?>
    <div class="error" role="alert"><?php print owt_h(owt_t($error)); ?></div>
<?php } ?>
<form method="post" action="<?php print owt_h(
    dol_buildpath('/owt/admin/setup.php', 1),
); ?>" autocomplete="off">
    <?php print owt_form_token(); ?>
    <div class="div-table-responsive">
        <table class="noborder centpercent">
            <tr class="liste_titre">
                <th colspan="2"><?php print owt_h(owt_t('Business service connection')); ?></th>
            </tr>
            <tr class="oddeven">
                <td><label for="service_url"><?php print owt_h(
                    owt_t('Service URL'),
                ); ?></label></td>
                <td>
                    <input class="flat minwidth300" type="url" id="service_url" name="service_url" value="<?php print owt_h(
                        $url,
                    ); ?>" maxlength="2048" required>
                    <p class="opacitymedium"><?php print owt_h(
                        owt_t(
                            'Use http://127.0.0.1:5087 for PHP on the same host, or http://owt-api:8080 for containers on a shared network.',
                        ),
                    ); ?></p>
                </td>
            </tr>
            <tr class="oddeven">
                <td><label for="service_api_key"><?php print owt_h(
                    owt_t('Service API key'),
                ); ?></label></td>
                <td>
                    <input class="flat minwidth300" type="password" id="service_api_key" name="service_api_key" value="" minlength="32" maxlength="512" autocomplete="new-password"<?php print $hasKey
                        ? ''
                        : ' required'; ?>>
                    <p class="opacitymedium"><?php print owt_h(
                        owt_t(
                            $hasKey
                                ? 'An API key is saved. Leave this field empty to keep it when the service URL is unchanged.'
                                : 'Enter the same API key configured on the business service.',
                        ),
                    ); ?></p>
                </td>
            </tr>
        </table>
    </div>
    <div class="center">
        <input class="button" type="submit" value="<?php print owt_h(owt_t('Save')); ?>">
    </div>
</form>
<?php llxFooter(); ?>
