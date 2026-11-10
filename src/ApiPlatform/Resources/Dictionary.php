<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use Quince\Dictionaries\ApiPlatform\Exception\DictionaryNotFoundException;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Exception\ManualDictionaryException;
use Quince\Dictionaries\ApiPlatform\Processor\DictionaryProcessor;
use Quince\Dictionaries\ApiPlatform\Provider\DictionaryProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Słownik w Admin API: definicja wysyłana przez system zewnętrzny i stan po stronie sklepu.
 *
 * Definicja przychodzi pierwsza, przed wpisami: z niej sklep zna poziomy,
 * po których sprawdza wpisy. Klucz słownika bierzemy wyłącznie z adresu,
 * nigdy z ciała żądania.
 *
 * Dwa zakresy, bo to dwie różne rzeczy: narzędzie, które tylko sprawdza stan,
 * nie powinno móc niczego nadpisać.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/quince-dictionaries/dictionaries/{key}',
            requirements: ['key' => '[a-z0-9][a-z0-9_-]{0,63}'],
            provider: DictionaryProvider::class,
            extraProperties: ['scopes' => ['quince_dictionaries_read']],
            exceptionToStatus: [DictionaryNotFoundException::class => Response::HTTP_NOT_FOUND],
            openapiContext: [
                'summary' => 'A dictionary and what the shop holds of it',
                'description' => 'The definition as last sent, the number of entries and links, '
                    . 'links whose SKU the shop does not have, and the last thing received.',
            ],
        ),
        new Put(
            uriTemplate: '/quince-dictionaries/dictionaries/{key}',
            requirements: ['key' => '[a-z0-9][a-z0-9_-]{0,63}'],
            read: false,
            processor: DictionaryProcessor::class,
            extraProperties: ['scopes' => ['quince_dictionaries_write']],
            exceptionToStatus: [
                InvalidPayloadException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
                ManualDictionaryException::class => Response::HTTP_CONFLICT,
            ],
            openapiContext: [
                'summary' => 'Create or replace the definition of a dictionary',
                'description' => 'Names per language, levels and fields. Send it before the entries. '
                    . 'An unknown `schemaVersion` is refused with 422 and nothing is saved.',
            ],
        ),
    ],
)]
class Dictionary
{
    /** Klucz słownika, np. `vehicles`. Wyłącznie z adresu. */
    #[ApiProperty(identifier: true)]
    public string $key = '';

    /** Wersja formatu przesyłki. Sklep rozumie wersje z `Intake::SCHEMA_VERSIONS`. */
    public string $schemaVersion = '';

    /** @var array<string,string> nazwa słownika per kod języka */
    public array $names = [];

    /** @var array<int,mixed> poziomy od góry: klucz, nazwy, czy tłumaczony */
    public array $levels = [];

    /** @var array<int,mixed> pola wpisu i powiązania: klucz, typ, zakres, nazwy */
    public array $fields = [];

    /** Odpowiedź: `sync` (przysłany przez API) albo `manual` (prowadzony w sklepie). */
    public string $source = '';

    /** Odpowiedź: wszystkie wpisy, razem z wycofanymi. */
    public int $entries = 0;

    /** Odpowiedź: ile z nich wycofano. */
    public int $retiredEntries = 0;

    /** Odpowiedź: wszystkie powiązania z produktami. */
    public int $links = 0;

    /** Odpowiedź: powiązania, których SKU sklep nie ma (`id_product` równe zero). */
    public int $unmatchedLinks = 0;

    /** @var array<string,mixed>|null odpowiedź: ostatni ślad przyjęcia */
    public ?array $lastSync = null;
}
