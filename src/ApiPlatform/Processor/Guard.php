<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\ApiPlatform\Processor;

use Db;
use Quince\Dictionaries\ApiPlatform\Exception\DictionaryNotFoundException;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Exception\ManualDictionaryException;
use Quince\Dictionaries\Storage\Schema;
use Quince\Dictionaries\Sync\Intake;

/**
 * Sprawdzenia całej przesyłki, wspólne dla wszystkich punktów zapisu.
 *
 * To, co tu odpada, odpada w całości i niczego nie zapisuje: przesyłki
 * w nieznanej wersji formatu nie da się przeczytać, a zgadywanie jej znaczenia
 * zapisałoby do sklepu coś, czego nikt nie wysłał.
 */
final class Guard
{
    /** Klucz z adresu. Ciało żądania nie ma tu nic do powiedzenia. */
    public static function key(array $uriVariables, string $nazwa = 'key'): string
    {
        $klucz = trim((string) ($uriVariables[$nazwa] ?? ''));

        if ($klucz === '') {
            throw new InvalidPayloadException(sprintf('The address has no %s.', $nazwa));
        }

        return $klucz;
    }

    public static function schemaVersion(string $wersja): void
    {
        if (!in_array($wersja, Intake::SCHEMA_VERSIONS, true)) {
            throw new InvalidPayloadException(sprintf(
                'Unknown schemaVersion "%s". This shop reads: %s. Nothing was saved.',
                $wersja,
                implode(', ', Intake::SCHEMA_VERSIONS)
            ));
        }
    }

    /**
     * Słownik, do którego idzie przesyłka. Musi istnieć i nie może być ręczny.
     *
     * @return array<string,mixed>
     */
    public static function dictionary(string $klucz): array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::DICTIONARY . '` WHERE dict_key = "' . pSQL($klucz) . '"'
        );

        if (!$wiersz) {
            throw new DictionaryNotFoundException(sprintf(
                'The shop has no dictionary "%s". Send its definition first (PUT /quince-dictionaries/dictionaries/%s).',
                $klucz,
                $klucz
            ));
        }

        self::notManual($wiersz);

        return $wiersz;
    }

    /** @param array<string,mixed> $wiersz */
    public static function notManual(array $wiersz): void
    {
        if (($wiersz['source'] ?? '') === 'manual') {
            throw new ManualDictionaryException(sprintf(
                'The dictionary "%s" is maintained by hand in the shop and is not overwritten.',
                (string) $wiersz['dict_key']
            ));
        }
    }
}
