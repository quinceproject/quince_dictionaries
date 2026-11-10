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

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Resources\ProductLinks;
use Quince\Dictionaries\Sync\Intake;

/**
 * Zapis kompletu powiązań jednego produktu, SKU z adresu.
 *
 * @implements ProcessorInterface<ProductLinks, ProductLinks>
 */
final class ProductLinksProcessor implements ProcessorInterface
{
    public function __construct(private readonly Intake $intake)
    {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductLinks
    {
        if (!$data instanceof ProductLinks) {
            throw new InvalidPayloadException('Unexpected payload for product links.');
        }

        $klucz = Guard::key($uriVariables);
        $sku = Guard::key($uriVariables, 'sku');
        Guard::schemaVersion($data->schemaVersion);
        $slownik = Guard::dictionary($klucz);

        $wynik = $this->intake->saveLinks(
            (int) $slownik['id_dictionary'],
            $klucz,
            [['sku' => $sku, 'links' => $data->links]],
            $data->cursor
        );

        $data->key = $klucz;
        $data->sku = $sku;
        $data->savedLinks = $wynik['saved'];
        $data->rejected = $wynik['rejected'];
        $data->productFound = $wynik['unmatched'] === [];
        $data->links = [];

        return $data;
    }
}
