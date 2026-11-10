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
use ApiPlatform\Metadata\Post;
use Quince\Dictionaries\ApiPlatform\Exception\DictionaryNotFoundException;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Exception\ManualDictionaryException;
use Quince\Dictionaries\ApiPlatform\Processor\EntryBatchProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paczka wpisów słownika.
 *
 * Wpisy idą różnicowo: system wysyłający przesyła to, co się zmieniło od ostatniej wysyłki,
 * i sam pamięta, dokąd doszedł (`cursor` trafia tylko do śladu w sklepie).
 * Błędny wiersz odpada z powodem, reszta paczki wchodzi.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/quince-dictionaries/dictionaries/{key}/entries/batch',
            requirements: ['key' => '[a-z0-9][a-z0-9_-]{0,63}'],
            processor: EntryBatchProcessor::class,
            extraProperties: ['scopes' => ['quince_dictionaries_write']],
            exceptionToStatus: [
                DictionaryNotFoundException::class => Response::HTTP_NOT_FOUND,
                InvalidPayloadException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
                ManualDictionaryException::class => Response::HTTP_CONFLICT,
            ],
            openapiContext: [
                'summary' => 'Add, update or retire entries of a dictionary',
                'description' => 'At most 1000 entries. Each is recognised by `code`; `previousCode` moves '
                    . 'an entry and its links to a new code. Entries entered by hand in the shop are left '
                    . 'alone. Rows that cannot be saved come back in `rejected` with a reason.',
            ],
        ),
    ],
)]
class EntryBatch
{
    /** Klucz słownika. Wyłącznie z adresu. */
    #[ApiProperty(identifier: true)]
    public string $key = '';

    public string $schemaVersion = '';

    /** Znacznik postępu wysyłki po stronie nadawcy. Sklep go tylko zapisuje w śladzie. */
    public string $cursor = '';

    /** @var array<int,array<string,mixed>> */
    public array $entries = [];

    /** Odpowiedź: ile wpisów zapisano. */
    public int $saved = 0;

    /** @var array<int,array{code:string,reason:string}> odpowiedź: wiersze, których nie zapisano */
    public array $rejected = [];
}
