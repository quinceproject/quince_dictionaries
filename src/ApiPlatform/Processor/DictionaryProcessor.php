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
use Db;
use Quince\Dictionaries\ApiPlatform\Exception\InvalidPayloadException;
use Quince\Dictionaries\ApiPlatform\Provider\DictionaryProvider;
use Quince\Dictionaries\ApiPlatform\Resources\Dictionary;
use Quince\Dictionaries\Storage\Schema;
use Quince\Dictionaries\Sync\Intake;

/**
 * Zapis definicji słownika. Odpowiada stanem słownika po zapisie.
 *
 * @implements ProcessorInterface<Dictionary, Dictionary>
 */
final class DictionaryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Intake $intake,
        private readonly DictionaryProvider $provider
    ) {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Dictionary
    {
        if (!$data instanceof Dictionary) {
            throw new InvalidPayloadException('Unexpected payload for a dictionary.');
        }

        $klucz = Guard::key($uriVariables);
        Guard::schemaVersion($data->schemaVersion);

        $istniejacy = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::DICTIONARY . '` WHERE dict_key = "' . pSQL($klucz) . '"'
        );

        if ($istniejacy) {
            Guard::notManual($istniejacy);
        }

        if ($data->levels === []) {
            throw new InvalidPayloadException('A dictionary needs at least one level. Nothing was saved.');
        }

        foreach ($data->levels as $poziom) {
            if (!is_array($poziom) || trim((string) ($poziom['key'] ?? '')) === '') {
                throw new InvalidPayloadException('Every level needs a "key". Nothing was saved.');
            }
        }

        $this->intake->saveDefinition($klucz, $data->schemaVersion, $data->names, $data->levels, $data->fields);

        return $this->provider->state($klucz);
    }
}
