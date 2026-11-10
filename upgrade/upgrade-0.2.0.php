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
 * 0.2.0: odbiór słowników przez Admin API (system zewnętrzny wysyła, sklep przyjmuje).
 *
 * Definicja słownika niesie teraz pola z typem, więc tabela słowników dostaje
 * na nie kolumnę. Zasoby API rdzeń wczytuje sam z `src/ApiPlatform/Resources`.
 *
 * @param Module $module
 */
function upgrade_module_0_2_0($module): bool
{
    return \Quince\Dictionaries\Storage\Schema::addFieldsColumn();
}
