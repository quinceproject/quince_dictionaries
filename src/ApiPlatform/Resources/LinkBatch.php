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
use ApiPlatform\Metadata\Put;
use Quince\Dictionaries\ApiPlatform\Exception\DictionaryNotFoundException;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Exception\ManualDictionaryException;
use Quince\Dictionaries\ApiPlatform\Processor\LinkBatchProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Powiązania kilku produktów naraz, każdy kompletem.
 *
 * Komplet produktu zastępuje to, co sklep dostał wcześniej dla tego SKU przez API. Limit
 * dotyczy łącznej liczby powiązań w żądaniu; produkt, który sam go
 * przekracza (pojazdy potrafią mieć prawie dwa tysiące), idzie w osobnym
 * żądaniu i wchodzi w całości.
 */
#[ApiResource(
    operations: [
        new Put(
            uriTemplate: '/quince-dictionaries/dictionaries/{key}/links/batch',
            requirements: ['key' => '[a-z0-9][a-z0-9_-]{0,63}'],
            read: false,
            processor: LinkBatchProcessor::class,
            extraProperties: ['scopes' => ['quince_dictionaries_write']],
            exceptionToStatus: [
                DictionaryNotFoundException::class => Response::HTTP_NOT_FOUND,
                InvalidPayloadException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
                ManualDictionaryException::class => Response::HTTP_CONFLICT,
            ],
            openapiContext: [
                'summary' => 'Replace the links of several products',
                'description' => 'Each product carries the complete set of its links in this dictionary, '
                    . 'which replaces the previous set. At most 5000 links per request, unless the request '
                    . 'carries a single product. Links entered by hand in the shop stay.',
            ],
        ),
    ],
)]
class LinkBatch
{
    /** Klucz słownika. Wyłącznie z adresu. */
    #[ApiProperty(identifier: true)]
    public string $key = '';

    public string $schemaVersion = '';

    public string $cursor = '';

    /** @var array<int,array{sku:string,links:array<int,array<string,mixed>>}> */
    public array $products = [];

    /** Odpowiedź: ile produktów przyjęto. */
    public int $savedProducts = 0;

    /** Odpowiedź: ile powiązań zapisano. */
    public int $savedLinks = 0;

    /** @var array<int,array<string,string>> odpowiedź: powiązania i produkty, których nie zapisano */
    public array $rejected = [];

    /** @var array<int,string> odpowiedź: SKU, których sklep nie ma; powiązania zapisano bez produktu */
    public array $unmatchedSkus = [];
}
