<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Storage;

use Db;

/**
 * Odczyt i zapis słowników.
 *
 * Dwa pytania, pod które ta klasa istnieje: „co pasuje do tego produktu"
 * (karta produktu) i „jakie produkty pasują do tego wpisu" (filtrowanie).
 * Reszta to zapis idempotentny i podgląd w panelu.
 */
final class Repository
{
    /** Zapis słownika po jego kluczu. Definicja przychodzi z systemu zewnętrznego jako dane. */
    public function saveDictionary(string $klucz, array $names, array $levels, string $source = Schema::SOURCE_SYNC, string $schemaVersion = ''): int
    {
        $teraz = date('Y-m-d H:i:s');
        $db = Db::getInstance();
        $tabela = _DB_PREFIX_ . Schema::DICTIONARY;

        $id = (int) $db->getValue('SELECT id_dictionary FROM `' . $tabela . '` WHERE dict_key = "' . pSQL($klucz) . '"');

        $dane = [
            'names' => pSQL((string) json_encode($names), true),
            'levels' => pSQL((string) json_encode($levels), true),
            'source' => pSQL($source),
            'schema_version' => pSQL($schemaVersion),
            'date_upd' => $teraz,
        ];

        if ($id > 0) {
            $db->update(Schema::DICTIONARY, $dane, 'id_dictionary = ' . $id);

            return $id;
        }

        $db->insert(Schema::DICTIONARY, $dane + ['dict_key' => pSQL($klucz), 'date_add' => $teraz]);

        return (int) $db->Insert_ID();
    }

    /** @return array<int,array<string,mixed>> */
    public function dictionaries(): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT d.*,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::ENTRY . '` e WHERE e.id_dictionary = d.id_dictionary) AS entries,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::LINK . '` l WHERE l.id_dictionary = d.id_dictionary) AS links
             FROM `' . _DB_PREFIX_ . Schema::DICTIONARY . '` d
             ORDER BY d.dict_key'
        );

        return is_array($wiersze) ? $wiersze : [];
    }

    public function dictionaryByKey(string $klucz): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::DICTIONARY . '` WHERE dict_key = "' . pSQL($klucz) . '"'
        );

        return $wiersz ?: null;
    }

    /**
     * Zapis wpisu po kodzie.
     *
     * Wpis ręczny jest nietykalny: synchronizacja go nie nadpisuje.
     * `previous_code` przenosi wpis i jego powiązania pod nowy kod, zamiast
     * zostawiać sierotę po zmianie kodu w systemie źródłowym.
     */
    public function saveEntry(int $idDictionary, array $wpis, string $source = Schema::SOURCE_SYNC): bool
    {
        $db = Db::getInstance();
        $kod = (string) ($wpis['code'] ?? '');

        if ($kod === '') {
            return false;
        }

        $poprzedni = (string) ($wpis['previous_code'] ?? '');

        if ($poprzedni !== '' && $poprzedni !== $kod) {
            $this->renameCode($idDictionary, $poprzedni, $kod);
        }

        $istniejacy = $db->getRow(
            'SELECT id_entry, source FROM `' . _DB_PREFIX_ . Schema::ENTRY . '`
             WHERE id_dictionary = ' . $idDictionary . ' AND code = "' . pSQL($kod) . '"'
        );

        if ($istniejacy && $istniejacy['source'] === Schema::SOURCE_MANUAL && $source === Schema::SOURCE_SYNC) {
            return true;
        }

        $dane = [
            'parent_code' => pSQL((string) ($wpis['parent_code'] ?? '')),
            'level_key' => pSQL((string) ($wpis['level_key'] ?? '')),
            'labels' => pSQL((string) json_encode($wpis['labels'] ?? []), true),
            'path' => pSQL((string) ($wpis['path'] ?? '')),
            'tags' => pSQL((string) json_encode($wpis['tags'] ?? []), true),
            'fields' => pSQL((string) json_encode($wpis['fields'] ?? []), true),
            'source' => pSQL($source),
            'retired' => (int) (bool) ($wpis['retired'] ?? false),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($istniejacy) {
            return (bool) $db->update(Schema::ENTRY, $dane, 'id_entry = ' . (int) $istniejacy['id_entry']);
        }

        return (bool) $db->insert(Schema::ENTRY, $dane + ['id_dictionary' => $idDictionary, 'code' => pSQL($kod)]);
    }

    /** Zmiana kodu wpisu razem z jego powiązaniami. */
    private function renameCode(int $idDictionary, string $stary, string $nowy): void
    {
        $db = Db::getInstance();
        $warunek = 'id_dictionary = ' . $idDictionary . ' AND code = "' . pSQL($stary) . '"';

        $db->update(Schema::ENTRY, ['code' => pSQL($nowy)], $warunek);
        $db->update(Schema::LINK, ['code' => pSQL($nowy)], $warunek);
        $db->update(
            Schema::ENTRY,
            ['parent_code' => pSQL($nowy)],
            'id_dictionary = ' . $idDictionary . ' AND parent_code = "' . pSQL($stary) . '"'
        );
    }

    /**
     * Powiązanie wpisu z produktem po SKU.
     *
     * `id_product` zapisujemy obok SKU, żeby karta produktu nie musiała za
     * każdym razem szukać po numerze katalogowym; po odtworzeniu sklepu z kopii
     * przelicza je `resolveProducts()`.
     */
    public function saveLink(int $idDictionary, array $powiazanie, string $source = Schema::SOURCE_SYNC): bool
    {
        $db = Db::getInstance();
        $kod = (string) ($powiazanie['code'] ?? '');
        $sku = (string) ($powiazanie['sku'] ?? '');

        if ($kod === '' || $sku === '') {
            return false;
        }

        $rola = (string) ($powiazanie['role'] ?? '');
        $warunek = 'id_dictionary = ' . $idDictionary
            . ' AND code = "' . pSQL($kod) . '" AND sku = "' . pSQL($sku) . '" AND role = "' . pSQL($rola) . '"';

        $istniejace = $db->getRow('SELECT id_link, source FROM `' . _DB_PREFIX_ . Schema::LINK . '` WHERE ' . $warunek);

        if ($istniejace && $istniejace['source'] === Schema::SOURCE_MANUAL && $source === Schema::SOURCE_SYNC) {
            return true;
        }

        $dane = [
            'id_product' => (int) ($powiazanie['id_product'] ?? $this->productIdBySku($sku)),
            'fields' => pSQL((string) json_encode($powiazanie['fields'] ?? []), true),
            'source' => pSQL($source),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($istniejace) {
            return (bool) $db->update(Schema::LINK, $dane, 'id_link = ' . (int) $istniejace['id_link']);
        }

        return (bool) $db->insert(Schema::LINK, $dane + [
            'id_dictionary' => $idDictionary,
            'code' => pSQL($kod),
            'sku' => pSQL($sku),
            'role' => pSQL($rola),
        ]);
    }

    /**
     * Powiązania produktu, gotowe do pokazania: ścieżka wpisu, jego pola
     * i pola powiązania, pogrupowane rolą.
     *
     * Wycofany wpis nie znika z bazy, ale przestaje być pokazywany.
     *
     * @return array<string,array<int,array<string,mixed>>> rola => wiersze
     */
    public function forProduct(int $idProduct): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT l.role, l.fields AS link_fields, l.sku,
                    e.code, e.path, e.labels, e.fields AS entry_fields, e.level_key,
                    d.dict_key, d.names
             FROM `' . _DB_PREFIX_ . Schema::LINK . '` l
             INNER JOIN `' . _DB_PREFIX_ . Schema::ENTRY . '` e
                 ON e.id_dictionary = l.id_dictionary AND e.code = l.code AND e.retired = 0
             INNER JOIN `' . _DB_PREFIX_ . Schema::DICTIONARY . '` d ON d.id_dictionary = l.id_dictionary
             WHERE l.id_product = ' . $idProduct . '
             ORDER BY l.role, e.path'
        );

        $wynik = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $wiersz) {
            $wynik[(string) $wiersz['role']][] = [
                'code' => (string) $wiersz['code'],
                'path' => (string) $wiersz['path'],
                'labels' => $this->json((string) $wiersz['labels']),
                'fields' => $this->json((string) $wiersz['entry_fields']),
                'linkFields' => $this->json((string) $wiersz['link_fields']),
                'dictionary' => (string) $wiersz['dict_key'],
                'dictionaryNames' => $this->json((string) $wiersz['names']),
            ];
        }

        return $wynik;
    }

    /** Produkty pasujące do wpisu, pod filtrowanie na listingu. */
    public function productsForEntry(int $idDictionary, string $code): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT id_product FROM `' . _DB_PREFIX_ . Schema::LINK . '`
             WHERE id_dictionary = ' . $idDictionary . ' AND code = "' . pSQL($code) . '" AND id_product > 0'
        );

        return is_array($wiersze) ? array_map(static fn (array $w): int => (int) $w['id_product'], $wiersze) : [];
    }

    /**
     * Ponowne przypisanie powiązań do produktów po SKU.
     *
     * Po odtworzeniu sklepu z kopii identyfikatory produktów bywają inne,
     * a numery katalogowe zostają.
     *
     * @return int ile wierszy dostało produkt
     */
    public function resolveProducts(): int
    {
        return (int) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . Schema::LINK . '` l
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.reference = l.sku
             SET l.id_product = p.id_product
             WHERE l.id_product <> p.id_product'
        );
    }

    public function productIdBySku(string $sku): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE reference = "' . pSQL($sku) . '"'
        );
    }

    /** Ostatni ślad synchronizacji dla każdego słownika. */
    public function lastSync(int $idDictionary): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::SYNC . '`
             WHERE id_dictionary = ' . $idDictionary . ' ORDER BY id_sync DESC'
        );

        return $wiersz ?: null;
    }

    public function logSync(int $idDictionary, string $dictKey, int $entries, int $links, string $status = 'ok', string $message = '', string $cursor = ''): void
    {
        Db::getInstance()->insert(Schema::SYNC, [
            'id_dictionary' => $idDictionary,
            'dict_key' => pSQL($dictKey),
            'date_add' => date('Y-m-d H:i:s'),
            'entries' => $entries,
            'links' => $links,
            'cursor' => pSQL($cursor),
            'status' => pSQL($status),
            'message' => pSQL($message, true),
        ]);
    }

    /** Wpisy słownika do podglądu w panelu. */
    public function entries(int $idDictionary, int $limit = 50, int $offset = 0): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::ENTRY . '`
             WHERE id_dictionary = ' . $idDictionary . '
             ORDER BY path LIMIT ' . $offset . ', ' . $limit
        );

        return is_array($wiersze) ? $wiersze : [];
    }

    /** @return array<string,mixed> */
    private function json(string $surowy): array
    {
        $dane = json_decode($surowy, true);

        return is_array($dane) ? $dane : [];
    }
}
