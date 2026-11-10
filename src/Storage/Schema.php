<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Storage;

use Db;

/**
 * Tabele słowników: kształt spłaszczony pod odczyt.
 *
 * Świadomie NIE odwzorowujemy modelu systemu źródłowego (słownik → poziomy →
 * pola EAV → wpisy → powiązania z polami). Same pojazdy to tam 14 tysięcy wpisów i 60
 * tysięcy powiązań, a pytanie „co pasuje do tego produktu" musiałoby przy takim
 * modelu złączyć drzewo wpisów z tabelą pól przy każdym wejściu klienta na
 * kartę. Sklep jest odczytem, nie drugim PIM-em: wpis niesie gotową ścieżkę
 * („Audi › A4 B5 › S4 quattro") i własne pola obok wiersza, a powiązanie jest
 * płaskim wierszem z dwoma indeksami — pod „co pasuje do produktu" i „jakie
 * produkty pasują do wpisu".
 */
final class Schema
{
    public const DICTIONARY = 'quince_dictionary';
    public const ENTRY = 'quince_dictionary_entry';
    public const LINK = 'quince_dictionary_link';
    public const SYNC = 'quince_dictionary_sync';

    /** Wiersz przysłany przez Admin API. Synchronizacja może go nadpisać. */
    public const SOURCE_SYNC = 'sync';

    /** Wiersz wpisany w sklepie. Synchronizacja go nie rusza. */
    public const SOURCE_MANUAL = 'manual';

    public static function install(): bool
    {
        $silnik = _MYSQL_ENGINE_;
        $zapytania = [];

        // Definicja słownika przychodzi z zewnątrz jako dane: poziomy i pola
        // trzymamy jako JSON, bo sklep ich nie redaguje, tylko czyta i pokazuje.
        $zapytania[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::DICTIONARY . '` (
            `id_dictionary` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `dict_key` VARCHAR(64) NOT NULL,
            `names` TEXT NULL,
            `levels` TEXT NULL,
            `fields` TEXT NULL,
            `source` ENUM(\'sync\', \'manual\') NOT NULL DEFAULT \'sync\',
            `schema_version` VARCHAR(16) NOT NULL DEFAULT \'\',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_dictionary`),
            UNIQUE KEY `dict_key` (`dict_key`)
        ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4;';

        // `code` jest złączem z systemem źródłowym i kluczem zapisu idempotentnego. `path` to
        // ścieżka-napis: pozwala pokazać wiersz bez odtwarzania drzewa.
        $zapytania[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::ENTRY . '` (
            `id_entry` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_dictionary` INT(11) UNSIGNED NOT NULL,
            `code` VARCHAR(64) NOT NULL,
            `parent_code` VARCHAR(64) NULL,
            `level_key` VARCHAR(64) NOT NULL DEFAULT \'\',
            `labels` TEXT NULL,
            `path` VARCHAR(512) NOT NULL DEFAULT \'\',
            `tags` TEXT NULL,
            `fields` TEXT NULL,
            `source` ENUM(\'sync\', \'manual\') NOT NULL DEFAULT \'sync\',
            `retired` TINYINT(1) NOT NULL DEFAULT 0,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_entry`),
            UNIQUE KEY `wpis` (`id_dictionary`, `code`),
            KEY `poziom` (`id_dictionary`, `level_key`, `retired`),
            KEY `rodzic` (`id_dictionary`, `parent_code`)
        ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4;';

        // Powiązanie trzyma SKU i wyliczony `id_product`: SKU przeżywa
        // odtworzenie sklepu z kopii, identyfikator PrestaShopa nie.
        $zapytania[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::LINK . '` (
            `id_link` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_dictionary` INT(11) UNSIGNED NOT NULL,
            `code` VARCHAR(64) NOT NULL,
            `sku` VARCHAR(64) NOT NULL,
            `id_product` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `role` VARCHAR(64) NOT NULL DEFAULT \'\',
            `fields` TEXT NULL,
            `source` ENUM(\'sync\', \'manual\') NOT NULL DEFAULT \'sync\',
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_link`),
            UNIQUE KEY `powiazanie` (`id_dictionary`, `code`, `sku`, `role`),
            KEY `po_produkcie` (`id_product`, `id_dictionary`, `role`),
            KEY `po_wpisie` (`id_dictionary`, `code`),
            KEY `po_sku` (`sku`)
        ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4;';

        // Ślad synchronizacji: bez niego nikt nie zauważy, że dane przestały
        // przychodzić.
        $zapytania[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::SYNC . '` (
            `id_sync` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_dictionary` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `dict_key` VARCHAR(64) NOT NULL DEFAULT \'\',
            `date_add` DATETIME NOT NULL,
            `entries` INT(11) NOT NULL DEFAULT 0,
            `links` INT(11) NOT NULL DEFAULT 0,
            `cursor` VARCHAR(128) NOT NULL DEFAULT \'\',
            `status` ENUM(\'ok\', \'error\', \'partial\') NOT NULL DEFAULT \'ok\',
            `message` TEXT NULL,
            PRIMARY KEY (`id_sync`),
            KEY `slownik` (`id_dictionary`, `date_add`)
        ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4;';

        foreach ($zapytania as $zapytanie) {
            if (!Db::getInstance()->execute($zapytanie)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kolumna z definicjami pól słownika (typ, zakres, wielojęzyczność).
     *
     * Doszła w 0.2.0 razem z odbiorem przez Admin API. Sprawdzamy, czy jest,
     * zamiast `ADD COLUMN IF NOT EXISTS`, bo MySQL tej składni nie zna.
     */
    public static function addFieldsColumn(): bool
    {
        $tabela = _DB_PREFIX_ . self::DICTIONARY;
        $jest = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $tabela . '` LIKE "fields"');

        if (is_array($jest) && $jest !== []) {
            return true;
        }

        return (bool) Db::getInstance()->execute('ALTER TABLE `' . $tabela . '` ADD `fields` TEXT NULL AFTER `levels`');
    }

    public static function uninstall(): bool
    {
        foreach ([self::SYNC, self::LINK, self::ENTRY, self::DICTIONARY] as $tabela) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $tabela . '`');
        }

        return true;
    }
}
