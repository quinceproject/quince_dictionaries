<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\ApiPlatform\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Db;
use Quince\Dictionaries\ApiPlatform\Exception\DictionaryNotFoundException;
use Quince\Dictionaries\ApiPlatform\Resources\Dictionary;
use Quince\Dictionaries\Storage\Schema;

/**
 * Stan słownika po stronie sklepu.
 *
 * Nadawca porównuje go ze swoim, zanim uzna wysyłkę za zakończoną: liczby
 * wpisów i powiązań, powiązania czekające na produkt i ostatnie przyjęcie.
 *
 * @implements ProviderInterface<Dictionary>
 */
final class DictionaryProvider implements ProviderInterface
{
    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Dictionary
    {
        return $this->state(trim((string) ($uriVariables['key'] ?? '')));
    }

    public function state(string $klucz): Dictionary
    {
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $wiersz = $db->getRow('SELECT * FROM `' . $p . Schema::DICTIONARY . '` WHERE dict_key = "' . pSQL($klucz) . '"');

        if (!$wiersz) {
            throw new DictionaryNotFoundException(sprintf('The shop has no dictionary "%s".', $klucz));
        }

        $id = (int) $wiersz['id_dictionary'];
        $wpisy = $db->getRow(
            'SELECT COUNT(*) AS wszystkie, COALESCE(SUM(retired), 0) AS wycofane
             FROM `' . $p . Schema::ENTRY . '` WHERE id_dictionary = ' . $id
        );
        $powiazania = $db->getRow(
            'SELECT COUNT(*) AS wszystkie, COALESCE(SUM(id_product = 0), 0) AS bez_produktu
             FROM `' . $p . Schema::LINK . '` WHERE id_dictionary = ' . $id
        );
        $slad = $db->getRow(
            'SELECT `date_add`, `entries`, `links`, `status`, `message`, `cursor` FROM `' . $p . Schema::SYNC . '`
             WHERE id_dictionary = ' . $id . ' ORDER BY id_sync DESC'
        );

        $slownik = new Dictionary();
        $slownik->key = (string) $wiersz['dict_key'];
        $slownik->schemaVersion = (string) $wiersz['schema_version'];
        $slownik->names = $this->json($wiersz['names'] ?? '');
        $slownik->levels = array_values($this->json($wiersz['levels'] ?? ''));
        $slownik->fields = array_values($this->json($wiersz['fields'] ?? ''));
        $slownik->source = (string) $wiersz['source'];
        $slownik->entries = (int) ($wpisy['wszystkie'] ?? 0);
        $slownik->retiredEntries = (int) ($wpisy['wycofane'] ?? 0);
        $slownik->links = (int) ($powiazania['wszystkie'] ?? 0);
        $slownik->unmatchedLinks = (int) ($powiazania['bez_produktu'] ?? 0);
        $slownik->lastSync = $slad ? [
            'date' => (string) $slad['date_add'],
            'entries' => (int) $slad['entries'],
            'links' => (int) $slad['links'],
            'status' => (string) $slad['status'],
            'message' => (string) $slad['message'],
            'cursor' => (string) $slad['cursor'],
        ] : null;

        return $slownik;
    }

    /** @return array<mixed> */
    private function json(?string $surowy): array
    {
        $dane = json_decode((string) $surowy, true);

        return is_array($dane) ? $dane : [];
    }
}
