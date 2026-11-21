<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Quince\Dictionaries\Settings\DictionariesSettings;
use Quince\Dictionaries\Storage\Repository;
use Quince\Dictionaries\Storage\Schema;

/**
 * Quince Dictionaries: słowniki katalogowe na karcie produktu.
 *
 * Następca modułu dopasowania do pojazdów, tyle że uniwersalny: pojazdy są
 * jednym ze słowników, nie całą funkcją. Strukturę i dane przysyła system
 * zewnętrzny (PIM, ERP) przez Admin API modułu, sklep je przyjmuje i pokazuje.
 * Sklep nie jest miejscem do redagowania słownika: przyjmuje dane, pozwala
 * poprawić pojedynczy wiersz i decyduje, gdzie klient to zobaczy.
 */
class Quince_Dictionaries extends Module
{
    private const DOMENA_PANELU = 'Modules.Quincedictionaries.Admin';
    private const DOMENA_SKLEPU = 'Modules.Quincedictionaries.Shop';

    /** Sekcja słownikowa na karcie produktu, w miejscu ustawionym w panelu strony produktu. */
    public const HOOK_PRODUCT = 'displayQuinceDictionaries';

    /** Od ilu wierszy tabela na karcie produktu dostaje wyszukiwarkę (więcej niż tyle). */
    private const SEARCH_FROM = 10;

    public function __construct()
    {
        $this->name = 'quince_dictionaries';
        $this->tab = 'front_office_features';
        $this->version = '0.3.1';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        $this->dependencies = ['quince_engine'];

        $this->tabs = [
            [
                'class_name' => 'AdminQuinceDictionaries',
                'visible' => true,
                'name' => 'Dictionaries',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_dictionaries',
                'wording' => 'Dictionaries',
                'wording_domain' => self::DOMENA_PANELU,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Dictionaries', [], self::DOMENA_PANELU);
        $this->description = $this->trans(
            'Catalogue dictionaries sent through the Admin API: vehicle fitment, OEM numbers, engine codes. Shows them on the product page. Requires Quince Engine.',
            [],
            self::DOMENA_PANELU
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling deletes the dictionaries and their links to products. They come back when the sending system sends them again.',
            [],
            self::DOMENA_PANELU
        );
    }

    public function install(): bool
    {
        return parent::install()
            && Schema::install()
            && $this->zalozHook()
            && $this->registerHook($this->hooks())
            && $this->installModuleGroups();
    }

    public function uninstall(): bool
    {
        foreach ((new DictionariesSettings())->configurationKeys() as $klucz) {
            Configuration::deleteByName($klucz);
        }

        return Schema::uninstall() && parent::uninstall();
    }

    /** @return string[] */
    private function hooks(): array
    {
        return [
            'actionQuinceRegisterFeature',
            self::HOOK_PRODUCT,
        ];
    }

    /**
     * Ogłoszenie własnego miejsca.
     *
     * `Hook::add()` przed rejestracją: PrestaShop po cichu pomija rejestrację
     * na hook, którego nie ma jeszcze w tabeli.
     */
    private function zalozHook(): bool
    {
        if (Hook::getIdByName(self::HOOK_PRODUCT)) {
            return true;
        }

        $hook = new Hook();
        $hook->name = self::HOOK_PRODUCT;
        $hook->title = 'Quince: słowniki na karcie produktu';
        $hook->description = 'Tabele dopasowania i innych słowników, po jednej na rolę powiązania.';
        $hook->position = false;

        return (bool) $hook->add();
    }

    /**
     * Wpisy w ps_module_group.
     *
     * Bez nich hook frontendowy w ogóle nie odpala: PrestaShop dokłada do listy
     * modułów warunek na grupę klienta i moduł bez wpisów znika z wyników.
     */
    private function installModuleGroups(): bool
    {
        $grupy = Group::getGroups((int) Context::getContext()->language->id, true);

        foreach (Shop::getShops(true, null, true) as $idShop) {
            foreach ($grupy as $grupa) {
                Db::getInstance()->insert(
                    'module_group',
                    [
                        'id_module' => (int) $this->id,
                        'id_shop' => (int) $idShop,
                        'id_group' => (int) $grupa['id_group'],
                    ],
                    false,
                    true,
                    Db::INSERT_IGNORE
                );
            }
        }

        return true;
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie są nasze ustawienia.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'dictionaries',
            'id' => 'quince_dictionaries',
            'label' => $this->trans('Dictionaries', [], self::DOMENA_PANELU),
            'route' => 'quince_dictionaries',
        ]];
    }

    /**
     * Karta produktu: po jednej tabeli na rolę powiązania.
     *
     * Rola jest tym, co niesie znaczenie: ten sam mechanizm daje raz tabelę
     * dopasowania do pojazdów, raz listę numerów OEM. Sklep może wyłączyć rolę
     * w ustawieniach modułu.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayQuinceDictionaries(array $params): string
    {
        $idProduct = $this->idProduktu($params['product'] ?? null);

        if ($idProduct <= 0) {
            return '';
        }

        $ustawienia = new DictionariesSettings();
        $repozytorium = new Repository();
        $role = $repozytorium->forProduct($idProduct);
        $doPokazania = [];

        if ($role === []) {
            return '';
        }

        // Definicje i przodków czytamy raz dla całej karty, nie na sekcję.
        $idsSlownikow = [];
        $rodzice = [];

        foreach ($role as $wiersze) {
            foreach ($wiersze as $wiersz) {
                $idsSlownikow[] = $wiersz['idDictionary'];
                $rodzice[$wiersz['idDictionary']][] = $wiersz['parentCode'];
            }
        }

        $definicje = $repozytorium->definitions($idsSlownikow);
        $przodkowie = $repozytorium->ancestors($rodzice);
        $nazwyRol = $repozytorium->roleNames();

        foreach ($role as $rola => $wiersze) {
            if ($ustawienia->roleVisible((string) $rola)) {
                $doPokazania[] = $this->sekcja((string) $rola, $wiersze, $definicje, $przodkowie, $nazwyRol[(string) $rola] ?? []);
            }
        }

        if ($doPokazania === []) {
            return '';
        }

        $this->context->smarty->assign('qdSekcje', $doPokazania);

        return $this->fetch('module:quince_dictionaries/views/templates/hook/product.tpl');
    }

    /**
     * Jedna sekcja karty produktu: nagłówki kolumn i wiersze.
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
    private function sekcja(string $rola, array $wiersze, array $definicje, array $przodkowie, array $nazwyRoli): array
    {
        // Poziomy w kolejności z definicji, słowniki w kolejności pojawienia się w sekcji.
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
            if (array_intersect_key($komorkiPoziomow[$i], $poziomy) === [] && $poziomy !== []) {
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
            $naglowek = $this->nazwa(is_array($definicja['names'] ?? null) ? $definicja['names'] : [], $pole);
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

            $gotowe[] = [
                'values' => $wartosci,
                'dictionary' => $slownik,
            ];

            // Licznik nad tabelą: „Pojazdy: 90". Nazwa pochodzi ze słownika, bo moduł
            // nie wie, czy pozycje to pojazdy, czy kuchenki. Zapis „nazwa: liczba"
            // omija odmianę przez liczby, której nazwa ze słownika nie zna.
            if (!isset($liczniki[$slownik])) {
                $nazwy = $wiersz['dictionaryNames'];
                $liczniki[$slownik] = [
                    'key' => $slownik,
                    'name' => is_array($nazwy) && $nazwy !== []
                        ? (string) ($nazwy[$this->context->language->iso_code] ?? reset($nazwy))
                        : $slownik,
                    'count' => 0,
                ];
            }

            ++$liczniki[$slownik]['count'];
        }

        return [
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

        $nazwa = $nazwy[$this->context->language->iso_code] ?? reset($nazwy);

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
            return $wartosc
                ? $this->trans('Yes', [], self::DOMENA_SKLEPU)
                : $this->trans('No', [], self::DOMENA_SKLEPU);
        }

        if (is_array($wartosc)) {
            return $this->nazwa($wartosc, '');
        }

        return is_scalar($wartosc) ? (string) $wartosc : '';
    }

    /** Identyfikator produktu z parametru hooka: tablicy z prezentera albo obiektu. */
    private function idProduktu($produkt): int
    {
        if ($produkt instanceof Product) {
            return (int) $produkt->id;
        }

        if (is_array($produkt) || $produkt instanceof ArrayAccess) {
            return (int) ($produkt['id_product'] ?? 0);
        }

        return 0;
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów prowadzi na ekran w panelu Quince.
     */
    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('quince_dictionaries'));
    }
}
