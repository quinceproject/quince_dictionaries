<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Sync;

use Db;
use Quince\Dictionaries\Storage\Schema;

/**
 * Przyjmowanie danych wysłanych przez Admin API.
 *
 * System zewnętrzny (PIM, ERP) wysyła, sklep przyjmuje. Reguły zapisu:
 *
 * - wpis rozpoznajemy po `code`, więc ta sama paczka wysłana dwa razy niczego
 *   nie zmienia,
 * - wpis i powiązanie wprowadzone w sklepie ręcznie są nietykalne,
 * - `previousCode` przenosi wpis i jego powiązania pod nowy kod,
 * - wycofany wpis zostaje w bazie, tylko przestaje być pokazywany,
 * - powiązania produktu przychodzą kompletem i zastępują poprzedni komplet.
 *
 * Błędny wiersz nie blokuje reszty paczki: odpada z powodem, który wraca
 * do nadawcy. Całą paczkę odrzucamy tylko wtedy, gdy nie da się jej
 * zinterpretować wcale (to sprawdzają procesory API, zanim tu trafi).
 */
final class Intake
{
    /** Wersje formatu przesyłki, które sklep rozumie. Inna wersja odpada w całości. */
    public const SCHEMA_VERSIONS = ['1.0'];

    /** Najwięcej wpisów w jednej paczce. */
    public const MAX_ENTRIES = 1000;

    /** Najwięcej powiązań w jednej paczce, chyba że paczka niesie jeden produkt. */
    public const MAX_LINKS = 5000;

    private const MAX_KEY = 64;

    /**
     * Zapis definicji słownika.
     *
     * @param array<string,mixed> $names
     * @param array<int,mixed> $levels
     * @param array<int,mixed> $fields
     */
    public function saveDefinition(string $key, string $schemaVersion, array $names, array $levels, array $fields): int
    {
        $db = Db::getInstance();
        $teraz = date('Y-m-d H:i:s');
        $id = $this->dictionaryId($key);

        $dane = [
            'names' => pSQL((string) json_encode($names, JSON_UNESCAPED_UNICODE), true),
            'levels' => pSQL((string) json_encode($levels, JSON_UNESCAPED_UNICODE), true),
            'fields' => pSQL((string) json_encode($fields, JSON_UNESCAPED_UNICODE), true),
            'schema_version' => pSQL($schemaVersion),
            'source' => Schema::SOURCE_SYNC,
            'date_upd' => $teraz,
        ];

        if ($id > 0) {
            $db->update(Schema::DICTIONARY, $dane, 'id_dictionary = ' . $id);
        } else {
            $db->insert(Schema::DICTIONARY, $dane + ['dict_key' => pSQL($key), 'date_add' => $teraz]);
            $id = (int) $db->Insert_ID();
        }

        $this->log($id, $key, 0, 0, 'ok', 'definition', '');

        return $id;
    }

    /**
     * Paczka wpisów.
     *
     * @param array<int,mixed> $entries
     *
     * @return array{saved:int,rejected:array<int,array{code:string,reason:string}>}
     */
    public function saveEntries(int $idDictionary, string $key, array $levels, array $entries, string $cursor): array
    {
        $poziomy = $this->levelKeys($levels);
        $zapisane = 0;
        $odrzucone = [];

        foreach ($entries as $wpis) {
            if (!is_array($wpis)) {
                $odrzucone[] = ['code' => '', 'reason' => 'invalid_row'];

                continue;
            }

            $kod = trim((string) ($wpis['code'] ?? ''));
            $powod = $this->entryProblem($wpis, $kod, $poziomy);

            if ($powod !== '') {
                $odrzucone[] = ['code' => $kod, 'reason' => $powod];

                continue;
            }

            $wynik = $this->saveEntry($idDictionary, $kod, $wpis);

            if ($wynik === '') {
                ++$zapisane;
            } else {
                $odrzucone[] = ['code' => $kod, 'reason' => $wynik];
            }
        }

        $this->log($idDictionary, $key, $zapisane, 0, $odrzucone === [] ? 'ok' : 'partial', $this->summary($odrzucone), $cursor);

        return ['saved' => $zapisane, 'rejected' => $odrzucone];
    }

    /**
     * Komplety powiązań kilku produktów.
     *
     * @param array<int,mixed> $products
     *
     * @return array{products:int,saved:int,rejected:array<int,array<string,string>>,unmatched:array<int,string>}
     */
    public function saveLinks(int $idDictionary, string $key, array $products, string $cursor): array
    {
        $produkty = 0;
        $zapisane = 0;
        $odrzucone = [];
        $bezProduktu = [];

        foreach ($products as $produkt) {
            if (!is_array($produkt) || trim((string) ($produkt['sku'] ?? '')) === '' || !is_array($produkt['links'] ?? null)) {
                $odrzucone[] = ['sku' => is_array($produkt) ? (string) ($produkt['sku'] ?? '') : '', 'code' => '', 'reason' => 'invalid_product'];

                continue;
            }

            $sku = trim((string) $produkt['sku']);
            $wynik = $this->replaceProductLinks($idDictionary, $sku, $produkt['links']);

            ++$produkty;
            $zapisane += $wynik['saved'];
            $odrzucone = array_merge($odrzucone, $wynik['rejected']);

            if ($wynik['idProduct'] === 0) {
                $bezProduktu[] = $sku;
            }
        }

        $this->log($idDictionary, $key, 0, $zapisane, $odrzucone === [] ? 'ok' : 'partial', $this->summary($odrzucone), $cursor);

        return ['products' => $produkty, 'saved' => $zapisane, 'rejected' => $odrzucone, 'unmatched' => $bezProduktu];
    }

    /** Ile powiązań niesie paczka produktów. Liczone przed zapisem, do limitu. */
    public static function countLinks(array $products): int
    {
        $ile = 0;

        foreach ($products as $produkt) {
            $ile += is_array($produkt) && is_array($produkt['links'] ?? null) ? count($produkt['links']) : 0;
        }

        return $ile;
    }

    public function dictionaryId(string $key): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_dictionary FROM `' . _DB_PREFIX_ . Schema::DICTIONARY . '` WHERE dict_key = "' . pSQL($key) . '"'
        );
    }

    // ---------------------------------------------------------------- wpisy

    /**
     * Co jest nie tak z wierszem wpisu. Pusty napis: wiersz jest dobry.
     *
     * @param array<string,mixed> $wpis
     * @param array<int,string> $poziomy
     */
    private function entryProblem(array $wpis, string $kod, array $poziomy): string
    {
        if ($kod === '' || mb_strlen($kod) > self::MAX_KEY) {
            return 'invalid_code';
        }

        $poprzedni = (string) ($wpis['previousCode'] ?? '');

        if (mb_strlen($poprzedni) > self::MAX_KEY || mb_strlen((string) ($wpis['parentCode'] ?? '')) > self::MAX_KEY) {
            return 'invalid_code';
        }

        $poziom = (string) ($wpis['level'] ?? '');

        if ($poziomy !== [] && !in_array($poziom, $poziomy, true)) {
            return 'unknown_level';
        }

        foreach (['labels', 'tags', 'fields'] as $pole) {
            if (isset($wpis[$pole]) && !is_array($wpis[$pole])) {
                return 'invalid_field';
            }
        }

        if (mb_strlen((string) ($wpis['path'] ?? '')) > 512) {
            return 'invalid_field';
        }

        return '';
    }

    /**
     * Zapis jednego wpisu. Pusty napis: zapisany; inaczej powód odrzucenia.
     *
     * @param array<string,mixed> $wpis
     */
    private function saveEntry(int $idDictionary, string $kod, array $wpis): string
    {
        $db = Db::getInstance();
        $istniejacy = $this->entry($idDictionary, $kod);

        if ($istniejacy !== null && $istniejacy['source'] === 'manual') {
            return 'manual';
        }

        $poprzedni = trim((string) ($wpis['previousCode'] ?? ''));

        if ($poprzedni !== '' && $poprzedni !== $kod) {
            $stary = $this->entry($idDictionary, $poprzedni);

            // Ręczny wpis pod starym kodem zostaje, jak był: zmiana kodu w systemie
            // źródłowym nie ma prawa przenieść cudzej pracy.
            if ($stary !== null && $stary['source'] !== Schema::SOURCE_MANUAL) {
                $this->moveCode($idDictionary, $poprzedni, $kod, $istniejacy !== null);
                $istniejacy = $this->entry($idDictionary, $kod);
            }
        }

        $dane = [
            'parent_code' => pSQL(trim((string) ($wpis['parentCode'] ?? ''))),
            'level_key' => pSQL((string) ($wpis['level'] ?? '')),
            'labels' => pSQL((string) json_encode($wpis['labels'] ?? [], JSON_UNESCAPED_UNICODE), true),
            'path' => pSQL((string) ($wpis['path'] ?? '')),
            'tags' => pSQL((string) json_encode($wpis['tags'] ?? [], JSON_UNESCAPED_UNICODE), true),
            'fields' => pSQL((string) json_encode($wpis['fields'] ?? [], JSON_UNESCAPED_UNICODE), true),
            'source' => Schema::SOURCE_SYNC,
            'retired' => !empty($wpis['retired']) ? 1 : 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($istniejacy !== null) {
            $db->update(Schema::ENTRY, $dane, 'id_entry = ' . (int) $istniejacy['id_entry']);
        } else {
            $db->insert(Schema::ENTRY, $dane + ['id_dictionary' => $idDictionary, 'code' => pSQL($kod)]);
        }

        return '';
    }

    /**
     * Przeniesienie wpisu spod starego kodu pod nowy, razem z powiązaniami
     * i wskazaniami dzieci.
     *
     * Gdy wpis pod nowym kodem już jest, stary wpis znika, a jego powiązania
     * dochodzą do nowego. Powiązanie, które nowy wpis ma już z tym samym
     * produktem i rolą, zostaje jedno.
     */
    private function moveCode(int $idDictionary, string $stary, string $nowy, bool $nowyIstnieje): void
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $warunek = 'id_dictionary = ' . $idDictionary . ' AND code = "' . pSQL($stary) . '"';

        $db->execute(
            'UPDATE IGNORE `' . $prefix . Schema::LINK . '` SET code = "' . pSQL($nowy) . '" WHERE ' . $warunek
        );
        $db->delete(Schema::LINK, $warunek . ' AND source <> "manual"');

        $db->update(
            Schema::ENTRY,
            ['parent_code' => pSQL($nowy)],
            'id_dictionary = ' . $idDictionary . ' AND parent_code = "' . pSQL($stary) . '"'
        );

        if ($nowyIstnieje) {
            $db->delete(Schema::ENTRY, $warunek);

            return;
        }

        $db->update(Schema::ENTRY, ['code' => pSQL($nowy)], $warunek);
    }

    /** @return array<string,mixed>|null */
    private function entry(int $idDictionary, string $kod): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT id_entry, source FROM `' . _DB_PREFIX_ . Schema::ENTRY . '`
             WHERE id_dictionary = ' . $idDictionary . ' AND code = "' . pSQL($kod) . '"'
        );

        return $wiersz ?: null;
    }

    // ---------------------------------------------------------------- powiązania

    /**
     * Komplet powiązań jednego produktu zastępuje poprzedni komplet.
     *
     * Kasujemy tylko powiązania przysłane przez API. Ręczne zostają, a jeśli przysłany
     * wiersz pokrywa się z ręcznym (ten sam wpis, produkt i rola), wygrywa
     * ręczny.
     *
     * Produkt szukamy po SKU (`ps_product.reference`). Gdy sklep go nie ma,
     * powiązanie i tak zapisujemy z `id_product` równym zero: po odtworzeniu
     * sklepu z kopii albo po dodaniu produktu przypisze je „Przypisz produkty"
     * w panelu (`Repository::resolveProducts()`), bez ponownego wysyłania.
     *
     * @param array<int,mixed> $links
     *
     * @return array{saved:int,rejected:array<int,array<string,string>>,idProduct:int}
     */
    public function replaceProductLinks(int $idDictionary, string $sku, array $links): array
    {
        $db = Db::getInstance();
        $idProduct = (int) $db->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE reference = "' . pSQL($sku) . '"'
        );

        $znane = $this->knownCodes($idDictionary, $links);
        $odrzucone = [];
        $wiersze = [];
        $teraz = date('Y-m-d H:i:s');

        foreach ($links as $link) {
            $kod = is_array($link) ? trim((string) ($link['code'] ?? '')) : '';
            $rola = is_array($link) ? trim((string) ($link['role'] ?? '')) : '';

            if (!is_array($link) || $kod === '' || mb_strlen($kod) > self::MAX_KEY) {
                $odrzucone[] = ['sku' => $sku, 'code' => $kod, 'reason' => 'invalid_code'];

                continue;
            }

            if ($rola === '' || mb_strlen($rola) > self::MAX_KEY) {
                $odrzucone[] = ['sku' => $sku, 'code' => $kod, 'reason' => 'missing_role'];

                continue;
            }

            if (isset($link['fields']) && !is_array($link['fields'])) {
                $odrzucone[] = ['sku' => $sku, 'code' => $kod, 'reason' => 'invalid_field'];

                continue;
            }

            if (!isset($znane[$kod])) {
                $odrzucone[] = ['sku' => $sku, 'code' => $kod, 'reason' => 'unknown_entry'];

                continue;
            }

            $wiersze[] = '(' . $idDictionary . ', "' . pSQL($kod) . '", "' . pSQL($sku) . '", ' . $idProduct . ', "'
                . pSQL($rola) . '", "' . pSQL((string) json_encode($link['fields'] ?? [], JSON_UNESCAPED_UNICODE), true)
                . '", "' . Schema::SOURCE_SYNC . '", "' . $teraz . '")';
        }

        $db->delete(Schema::LINK, 'id_dictionary = ' . $idDictionary . ' AND sku = "' . pSQL($sku) . '" AND source = "' . Schema::SOURCE_SYNC . '"');

        $zapisane = 0;

        // Paczkami po kilkaset wierszy: jeden produkt potrafi nieść prawie dwa
        // tysiące powiązań, a pojedyncze zapytanie na wiersz to dwa tysiące
        // podróży do bazy.
        foreach (array_chunk($wiersze, 500) as $czesc) {
            $db->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . Schema::LINK . '`
                 (id_dictionary, code, sku, id_product, role, fields, source, date_upd) VALUES ' . implode(', ', $czesc)
            );
            $zapisane += (int) $db->Affected_Rows();
        }

        return ['saved' => $zapisane, 'rejected' => $odrzucone, 'idProduct' => $idProduct];
    }

    /**
     * Kody z paczki, które słownik ma jako wpisy. Jednym zapytaniem dla całego produktu.
     *
     * @param array<int,mixed> $links
     *
     * @return array<string,bool>
     */
    private function knownCodes(int $idDictionary, array $links): array
    {
        $kody = [];

        foreach ($links as $link) {
            if (is_array($link) && trim((string) ($link['code'] ?? '')) !== '') {
                $kody['"' . pSQL(trim((string) $link['code'])) . '"'] = true;
            }
        }

        if ($kody === []) {
            return [];
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT code FROM `' . _DB_PREFIX_ . Schema::ENTRY . '`
             WHERE id_dictionary = ' . $idDictionary . ' AND code IN (' . implode(', ', array_keys($kody)) . ')'
        );

        $znane = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $wiersz) {
            $znane[(string) $wiersz['code']] = true;
        }

        return $znane;
    }

    // ---------------------------------------------------------------- pomocnicze

    /**
     * Klucze poziomów z definicji. Pusta lista: słownik nie ma jeszcze
     * definicji z poziomami i poziomu wpisu nie da się sprawdzić.
     *
     * @param array<int,mixed> $levels
     *
     * @return array<int,string>
     */
    private function levelKeys(array $levels): array
    {
        $klucze = [];

        foreach ($levels as $poziom) {
            if (is_array($poziom) && isset($poziom['key'])) {
                $klucze[] = (string) $poziom['key'];
            } elseif (is_string($poziom)) {
                $klucze[] = $poziom;
            }
        }

        return $klucze;
    }

    /** @param array<int,array<string,string>> $odrzucone */
    private function summary(array $odrzucone): string
    {
        if ($odrzucone === []) {
            return '';
        }

        $opis = array_map(
            static fn (array $w): string => trim(($w['sku'] ?? '') . ' ' . ($w['code'] ?? '')) . ': ' . $w['reason'],
            array_slice($odrzucone, 0, 20)
        );

        return 'rejected ' . count($odrzucone) . ': ' . implode(', ', $opis);
    }

    private function log(int $idDictionary, string $key, int $entries, int $links, string $status, string $message, string $cursor): void
    {
        Db::getInstance()->insert(Schema::SYNC, [
            'id_dictionary' => $idDictionary,
            'dict_key' => pSQL($key),
            'date_add' => date('Y-m-d H:i:s'),
            'entries' => $entries,
            'links' => $links,
            'cursor' => pSQL(mb_substr($cursor, 0, 128)),
            'status' => pSQL($status),
            'message' => pSQL($message, true),
        ]);
    }
}
