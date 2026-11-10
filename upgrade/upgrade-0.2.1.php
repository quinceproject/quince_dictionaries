<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.2.1: koniec pobierania przez sklep.
 *
 * Dane przysyła system zewnętrzny przez Admin API, więc adres eksportu, sekret i liczba
 * wierszy na żądanie nic już nie robią. Kasujemy dokładnie te trzy klucze,
 * każdy po nazwie, we wszystkich sklepach.
 *
 * @param Module $module
 */
function upgrade_module_0_2_1($module): bool
{
    foreach (\Quince\Dictionaries\Settings\DictionariesSettings::RETIRED_KEYS as $klucz) {
        Configuration::deleteByName($klucz);
    }

    return true;
}
