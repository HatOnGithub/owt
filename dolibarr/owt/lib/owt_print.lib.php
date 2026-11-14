<?php
/** Print-only locale selection; never changes the user's Dolibarr language. */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit();
}

function owt_print_locale($requested = null) {
    global $langs;
    $supported = ['en_US', 'nl_NL', 'fr_FR'];
    if ($requested !== null && $requested !== '') {
        if (!in_array($requested, $supported, true)) {
            throw new InvalidArgumentException('Unsupported print language');
        }
        return $requested;
    }
    $prefix = substr((string) ($langs->defaultlang ?? 'en_US'), 0, 2);
    foreach ($supported as $locale) {
        if (substr($locale, 0, 2) === $prefix) {
            return $locale;
        }
    }
    return 'en_US';
}

function owt_print_translations($locale) {
    $locale = owt_print_locale($locale);
    $read = function ($locale) {
        $words = [];
        foreach (
            file(__DIR__ . '/../langs/' . $locale . '/owt.lang', FILE_IGNORE_NEW_LINES)
            as $line
        ) {
            if (preg_match('/^(OWTUI_[^=]+)=(.*)$/', $line, $match)) {
                $words[$match[1]] = owt_plain_translation($match[2]);
            }
        }
        return $words;
    };
    $translated = $read($locale);
    $result = [];
    foreach ($read('en_US') as $key => $english) {
        $result[$english] = $translated[$key] ?? $english;
    }
    $extra = __DIR__ . '/../langs/' . substr($locale, 0, 2) . '.spa.json';
    if (is_file($extra)) {
        $result = array_merge(
            $result,
            array_map(
                'owt_plain_translation',
                json_decode(file_get_contents($extra), true, 512, JSON_THROW_ON_ERROR),
            ),
        );
    }
    return $result;
}

function owt_print_data($locale, $selection) {
    $data = [
        'locale' => $locale,
        'selection' => (object) $selection,
        'translations' => (object) owt_print_translations($locale),
    ];
    print '<script id="owt-print-language-data" type="application/json">' .
        json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        ) .
        '</script><script type="module" src="' .
        owt_h(owt_base() . 'spa/print/language.js') .
        '"></script>';
}
