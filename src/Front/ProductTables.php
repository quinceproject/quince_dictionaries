<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Dictionaries\Settings\DictionariesSettings;
use Quince\Dictionaries\Storage\Repository;

/**
 * Tabele słowników produktu: po jednej na rolę powiązania.
 *
 * Z tej klasy korzysta karta produktu (pierwsza strona każdej tabeli) i kontroler
 * kolejnych stron, żeby obie drogi liczyły te same kolumny: strona druga musi mieć
 * dokładnie te kolumny co pierwsza, więc liczymy je zawsze z całej tabeli.
 */
final class ProductTables
{
    /** Wierszy na jednej stronie tabeli. Do tylu tabela przychodzi w całości i szuka w przeglądarce. */
    public const PAGE_SIZE = 100;

    /** Od ilu wierszy tabela dostaje wyszukiwarkę (więcej niż tyle). */
    public const SEARCH_FROM = 10;

    /**
     * @param callable(bool):string $takNie napis „Tak"/„Nie" w języku sklepu
     */
    public function __construct(
        private readonly string $jezyk,
        private $takNie,
        private readonly Repository $repozytorium = new Repository(),
    ) {
    }

    /**
     * Wszystkie widoczne tabele produktu, z kompletem wierszy.
     *
     * @return array<string,array<string,mixed>> klucz roli => tabela
     */
    public function forProduct(int $idProduct, ?DictionariesSettings $ustawienia = null): array
    {
        $ustawienia ??= new DictionariesSettings();
        $role = $this->repozytorium->forProduct($idProduct);

        if ($role === []) {
            return [];
        }

        // Definicje i przodków czytamy raz dla całej karty, nie na tabelę.
        $idsSlownikow = [];
        $rodzice = [];

        foreach ($role as $wiersze) {
            foreach ($wiersze as $wiersz) {
                $idsSlownikow[] = $wiersz['idDictionary'];
                $rodzice[$wiersz['idDictionary']][] = $wiersz['parentCode'];
            }
        }

        $definicje = $this->repozytorium->definitions($idsSlownikow);
        $przodkowie = $this->repozytorium->ancestors($rodzice);
        $nazwyRol = $this->repozytorium->roleNames();
        $wynik = [];

        foreach ($role as $rola => $wiersze) {
            $rola = (string) $rola;

            if ($ustawienia->roleVisible($rola)) {
                $wynik[$rola] = $this->tabela($rola, $wiersze, $definicje, $przodkowie, $nazwyRol[$rola] ?? []);
            }
        }

        return $wynik;
    }

    /**
     * Wiersze pasujące do frazy. Bez rozróżniania wielkości liter i polskich znaków,
     * po wszystkich kolumnach, jak wyszukiwarka w przeglądarce przy krótkiej tabeli.
     *
     * @param array<int,array{values:array<int,string>,dictionary:string}> $wiersze
     *
     * @return array<int,array{values:array<int,string>,dictionary:string}>
     */
    public static function filter(array $wiersze, string $fraza): array
    {
        $szukane = self::bezZnakow(trim($fraza));

        if ($szukane === '') {
            return $wiersze;
        }

        return array_values(array_filter(
            $wiersze,
            static fn (array $w): bool => str_contains(self::bezZnakow(implode(' ', $w['values'])), $szukane)
        ));
    }

    /**
     * Liczba wierszy na słownik: do licznika „Pojazdy: 12 z 90".
     *
     * @param array<int,array{values:array<int,string>,dictionary:string}> $wiersze
     *
     * @return array<string,int>
     */
    public static function countByDictionary(array $wiersze): array
    {
        $wynik = [];

        foreach ($wiersze as $wiersz) {
            $wynik[$wiersz['dictionary']] = ($wynik[$wiersz['dictionary']] ?? 0) + 1;
        }

        return $wynik;
    }

    private static function bezZnakow(string $tekst): string
    {
        $tekst = mb_strtolower($tekst, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $tekst = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($tekst, \Normalizer::FORM_D));
        }

        // „ł" nie rozkłada się na literę i znak diakrytyczny.
        return strtr($tekst, ['ł' => 'l']);
    }

    /**
     * Jedna tabela: nagłówki kolumn i wiersze.
     *
     * Najpierw kolumna na każdy poziom słownika (Typ, Marka, Model...), potem pola.
     * Wartości poziomów to nazwy wpisu i jego przodków w języku sklepu, złożone po
     * `parent_code`; napisu `path` nie tniemy, bo nazwa może zawierać separator,
     * a `path` jest tylko w jednym języku. Kolumna poziomu pusta we wszystkich
     * wierszach znika: produkt powiązany tylko z markami nie pokazuje pustych modeli.
     * Pola liczymy z danych, bo każda rola niesie inne: przy pojazdach lata produkcji
     * siedzą na powiązaniu, przy numerach OEM pola stoją przy samym wpisie.
     *
     * @param array<int,array<string,mixed>> $wiersze
     * @param array<int,array{levels:array,fields:array,roles:array}> $definicje
     * @param array<int,array<string,array{parent:string,level:string,labels:array}>> $przodkowie
     * @param array<string,string> $nazwyRoli
     *
     * @return array<string,mixed>
     */
    private function tabela(string $rola, array $wiersze, array $definicje, array $przodkowie, array $nazwyRoli): array
    {
        // Poziomy w kolejności z definicji, słowniki w kolejności pojawienia się w tabeli.
        $poziomy = [];
        $definicjePol = [];

        foreach ($wiersze as $wiersz) {
            $definicja = $definicje[$wiersz['idDictionary']] ?? ['levels' => [], 'fields' => []];

            foreach ($definicja['levels'] as $poziom) {
                $klucz = is_array($poziom) ? (string) ($poziom['key'] ?? '') : (string) $poziom;

                if ($klucz !== '' && !isset($poziomy[$klucz])) {
                    $poziomy[$klucz] = $this->nazwa(is_array($poziom) ? ($poziom['names'] ?? []) : [], $klucz);
                }
            }

            foreach ($definicja['fields'] as $pole) {
                $klucz = is_array($pole) ? (string) ($pole['key'] ?? '') : '';

                if ($klucz !== '' && !isset($definicjePol[$klucz])) {
                    $definicjePol[$klucz] = $pole;
                }
            }
        }

        // Pola: te z definicji w jej kolejności, nieopisane na końcu.
        $obecnePola = [];

        foreach ($wiersze as $wiersz) {
            foreach (array_merge(array_keys($wiersz['fields']), array_keys($wiersz['linkFields'])) as $pole) {
                $obecnePola[(string) $pole] = true;
            }
        }

        $pola = array_merge(
            array_values(array_filter(array_keys($definicjePol), static fn ($k): bool => isset($obecnePola[$k]))),
            array_values(array_diff(array_keys($obecnePola), array_keys($definicjePol)))
        );

        $komorkiPoziomow = [];

        foreach ($wiersze as $i => $wiersz) {
            $komorkiPoziomow[$i] = $this->poziomyWiersza($wiersz, $przodkowie[$wiersz['idDictionary']] ?? []);

            // Wpis bez poziomu z definicji: ścieżka w pierwszej kolumnie, żeby wiersz nie był pusty.
            if ($poziomy !== [] && array_intersect_key($komorkiPoziomow[$i], $poziomy) === []) {
                $komorkiPoziomow[$i] = [array_key_first($poziomy) => $wiersz['path'] !== '' ? $wiersz['path'] : $wiersz['code']];
            }
        }

        $poziomy = array_filter(
            $poziomy,
            static function ($klucz) use ($komorkiPoziomow): bool {
                foreach ($komorkiPoziomow as $komorki) {
                    if (($komorki[$klucz] ?? '') !== '') {
                        return true;
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_KEY
        );

        $kolumny = array_values($poziomy);

        foreach ($pola as $pole) {
            $definicja = $definicjePol[$pole] ?? [];
            $naglowek = $this->nazwa(is_array($definicja['names'] ?? null) ? $definicja['names'] : [], (string) $pole);
            $jednostka = trim((string) ($definicja['unit'] ?? ''));
            $kolumny[] = $jednostka !== '' ? $naglowek . ' (' . $jednostka . ')' : $naglowek;
        }

        $gotowe = [];
        $liczniki = [];

        foreach ($wiersze as $i => $wiersz) {
            $wartosci = [];

            foreach (array_keys($poziomy) as $poziom) {
                $wartosci[] = $komorkiPoziomow[$i][$poziom] ?? '';
            }

            foreach ($pola as $pole) {
                $wartosci[] = $this->wartoscPola($wiersz['linkFields'][$pole] ?? $wiersz['fields'][$pole] ?? '');
            }

            $slownik = (string) $wiersz['dictionary'];
            $gotowe[] = ['values' => $wartosci, 'dictionary' => $slownik];

            // Licznik nad tabelą: „Pojazdy: 90". Nazwa pochodzi ze słownika, bo moduł
            // nie wie, czy pozycje to pojazdy, czy kuchenki. Zapis „nazwa: liczba"
            // omija odmianę przez liczby, której nazwa ze słownika nie zna.
            if (!isset($liczniki[$slownik])) {
                $liczniki[$slownik] = [
                    'key' => $slownik,
                    'name' => $this->nazwa($wiersz['dictionaryNames'], $slownik),
                    'count' => 0,
                ];
            }

            ++$liczniki[$slownik]['count'];
        }

        return [
            'key' => $rola,
            'role' => $this->nazwa($nazwyRoli, $rola),
            'columns' => $kolumny,
            'rows' => $gotowe,
            'counts' => array_values($liczniki),
            // Kilka wierszy obejmuje się wzrokiem, wyszukiwarka byłaby tam szumem.
            'searchable' => count($gotowe) > self::SEARCH_FROM,
        ];
    }

    /**
     * Nazwy wpisu i jego przodków po kluczu poziomu, w języku sklepu.
     *
     * @param array<string,mixed> $wiersz
     * @param array<string,array{parent:string,level:string,labels:array}> $przodkowie kod => wpis
     *
     * @return array<string,string> klucz poziomu => nazwa
     */
    private function poziomyWiersza(array $wiersz, array $przodkowie): array
    {
        $komorki = [];

        if ($wiersz['level'] !== '') {
            $komorki[$wiersz['level']] = $this->nazwa($wiersz['labels'], $wiersz['code']);
        }

        $kod = $wiersz['parentCode'];

        // Ogranicznik chroni przed pętlą w źle przysłanym drzewie.
        for ($krok = 0; $kod !== '' && isset($przodkowie[$kod]) && $krok < 20; ++$krok) {
            $przodek = $przodkowie[$kod];

            if ($przodek['level'] !== '' && !isset($komorki[$przodek['level']])) {
                $komorki[$przodek['level']] = $this->nazwa($przodek['labels'], $kod);
            }

            $kod = $przodek['parent'];
        }

        return $komorki;
    }

    /**
     * Napis w języku sklepu z mapy {język: napis}; brak języka sklepu = pierwszy dostępny,
     * pusta mapa = zapasowy napis (klucz albo kod).
     *
     * @param mixed $nazwy
     */
    private function nazwa($nazwy, string $zapas): string
    {
        if (!is_array($nazwy) || $nazwy === []) {
            return $zapas;
        }

        $nazwa = $nazwy[$this->jezyk] ?? reset($nazwy);

        return is_scalar($nazwa) && (string) $nazwa !== '' ? (string) $nazwa : $zapas;
    }

    /**
     * Wartość pola do komórki. Pola tłumaczone i listy wyboru przychodzą jako {język: tekst}.
     *
     * @param mixed $wartosc
     */
    private function wartoscPola($wartosc): string
    {
        if (is_bool($wartosc)) {
            return ($this->takNie)($wartosc);
        }

        if (is_array($wartosc)) {
            return $this->nazwa($wartosc, '');
        }

        return is_scalar($wartosc) ? (string) $wartosc : '';
    }
}
