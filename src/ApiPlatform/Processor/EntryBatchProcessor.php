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
use Quince\Dictionaries\ApiPlatform\Resources\EntryBatch;
use Quince\Dictionaries\Sync\Intake;

/**
 * Zapis paczki wpisów.
 *
 * @implements ProcessorInterface<EntryBatch, EntryBatch>
 */
final class EntryBatchProcessor implements ProcessorInterface
{
    public function __construct(private readonly Intake $intake)
    {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EntryBatch
    {
        if (!$data instanceof EntryBatch) {
            throw new InvalidPayloadException('Unexpected payload for dictionary entries.');
        }

        $klucz = Guard::key($uriVariables);
        Guard::schemaVersion($data->schemaVersion);
        $slownik = Guard::dictionary($klucz);

        if (count($data->entries) > Intake::MAX_ENTRIES) {
            throw new InvalidPayloadException(sprintf('At most %d entries per request. Nothing was saved.', Intake::MAX_ENTRIES));
        }

        $poziomy = json_decode((string) $slownik['levels'], true);
        $wynik = $this->intake->saveEntries(
            (int) $slownik['id_dictionary'],
            $klucz,
            is_array($poziomy) ? $poziomy : [],
            $data->entries,
            $data->cursor
        );

        $data->key = $klucz;
        $data->saved = $wynik['saved'];
        $data->rejected = $wynik['rejected'];
        $data->entries = [];

        return $data;
    }
}
