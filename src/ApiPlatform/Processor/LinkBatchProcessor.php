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
use Quince\Dictionaries\ApiPlatform\Resources\LinkBatch;
use Quince\Dictionaries\Sync\Intake;

/**
 * Zapis powiązań kilku produktów.
 *
 * @implements ProcessorInterface<LinkBatch, LinkBatch>
 */
final class LinkBatchProcessor implements ProcessorInterface
{
    public function __construct(private readonly Intake $intake)
    {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LinkBatch
    {
        if (!$data instanceof LinkBatch) {
            throw new InvalidPayloadException('Unexpected payload for dictionary links.');
        }

        $klucz = Guard::key($uriVariables);
        Guard::schemaVersion($data->schemaVersion);
        $slownik = Guard::dictionary($klucz);

        // Jeden produkt wchodzi zawsze w całości, nawet ponad limit: jego
        // komplet nie da się podzielić na dwa żądania, bo każde zastępuje
        // poprzedni komplet.
        if (count($data->products) > 1 && Intake::countLinks($data->products) > Intake::MAX_LINKS) {
            throw new InvalidPayloadException(sprintf(
                'At most %d links per request, unless the request carries a single product. Nothing was saved.',
                Intake::MAX_LINKS
            ));
        }

        $wynik = $this->intake->saveLinks((int) $slownik['id_dictionary'], $klucz, $data->products, $data->cursor);

        $data->key = $klucz;
        $data->savedProducts = $wynik['products'];
        $data->savedLinks = $wynik['saved'];
        $data->rejected = $wynik['rejected'];
        $data->unmatchedSkus = $wynik['unmatched'];
        $data->products = [];

        return $data;
    }
}
