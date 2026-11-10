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
use Quince\Dictionaries\ApiPlatform\Processor\ProductLinksProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Komplet powiązań jednego produktu.
 *
 * To samo co paczka produktów, dla jednego SKU w adresie. SKU z ukośnikiem
 * nie przejdzie przez adres, więc taki produkt wysyła się paczką.
 */
#[ApiResource(
    operations: [
        new Put(
            uriTemplate: '/quince-dictionaries/dictionaries/{key}/products/{sku}/links',
            requirements: ['key' => '[a-z0-9][a-z0-9_-]{0,63}', 'sku' => '[^/]{1,64}'],
            read: false,
            processor: ProductLinksProcessor::class,
            extraProperties: ['scopes' => ['quince_dictionaries_write']],
            exceptionToStatus: [
                DictionaryNotFoundException::class => Response::HTTP_NOT_FOUND,
                InvalidPayloadException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
                ManualDictionaryException::class => Response::HTTP_CONFLICT,
            ],
            openapiContext: [
                'summary' => 'Replace the links of one product',
                'description' => 'The complete set of the product\'s links in this dictionary, replacing the '
                    . 'previous set. SKU is `reference` of the product. Links entered by hand stay.',
            ],
        ),
    ],
)]
class ProductLinks
{
    /** Klucz słownika. Wyłącznie z adresu. */
    #[ApiProperty(identifier: true)]
    public string $key = '';

    /** SKU produktu. Wyłącznie z adresu. */
    #[ApiProperty(identifier: true)]
    public string $sku = '';

    public string $schemaVersion = '';

    public string $cursor = '';

    /** @var array<int,array<string,mixed>> */
    public array $links = [];

    /** Odpowiedź: ile powiązań zapisano. */
    public int $savedLinks = 0;

    /** @var array<int,array<string,string>> odpowiedź: powiązania, których nie zapisano */
    public array $rejected = [];

    /** Odpowiedź: czy sklep ma produkt o tym SKU. Bez niego powiązania czekają na przypisanie. */
    public bool $productFound = false;
}
