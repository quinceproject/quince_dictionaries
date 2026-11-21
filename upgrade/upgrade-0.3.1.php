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
 * 0.3.1: nazwy ról w definicji słownika.
 *
 * Nowa kolumna `roles`. Do czasu ponownego przesłania definicji jest pusta,
 * a karta produktu pokazuje klucz roli, jak dotąd.
 *
 * @param Module $module
 */
function upgrade_module_0_3_1($module): bool
{
    return \Quince\Dictionaries\Storage\Schema::addRolesColumn();
}
