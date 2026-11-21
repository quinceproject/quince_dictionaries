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

    public function __construct()
    {
        $this->name = 'quince_dictionaries';
        $this->tab = 'front_office_features';
        $this->version = '0.3.2';
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
     * Tabela dłuższa niż strona przychodzi tylko pierwszą stroną; kolejne strony
     * i wyszukiwanie w całej tabeli podaje kontroler `table` (wyspa motywu
     * `islands/dictionaries.js`). Krótsza przychodzi w całości i szuka w przeglądarce.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayQuinceDictionaries(array $params): string
    {
        $idProduct = $this->idProduktu($params['product'] ?? null);

        if ($idProduct <= 0) {
            return '';
        }

        $tabele = $this->productTables()->forProduct($idProduct);

        if ($tabele === []) {
            return '';
        }

        $doPokazania = [];

        foreach ($tabele as $tabela) {
            $wszystkie = count($tabela['rows']);
            $strony = (int) ceil($wszystkie / \Quince\Dictionaries\Front\ProductTables::PAGE_SIZE);

            $tabela['paged'] = $strony > 1;
            $tabela['pages'] = $strony;
            $tabela['rows'] = array_slice($tabela['rows'], 0, \Quince\Dictionaries\Front\ProductTables::PAGE_SIZE);
            $tabela['url'] = $tabela['paged']
                ? $this->context->link->getModuleLink($this->name, 'table', [
                    'id_product' => $idProduct,
                    'role' => $tabela['key'],
                ])
                : '';

            $doPokazania[] = $tabela;
        }

        $this->context->smarty->assign('qdSekcje', $doPokazania);

        return $this->fetch('module:quince_dictionaries/views/templates/hook/product.tpl');
    }

    /** Budowa tabel w języku bieżącego sklepu; wspólna dla karty i kontrolera stron. */
    public function productTables(): \Quince\Dictionaries\Front\ProductTables
    {
        return new \Quince\Dictionaries\Front\ProductTables(
            (string) $this->context->language->iso_code,
            fn (bool $tak): string => $tak
                ? $this->trans('Yes', [], self::DOMENA_SKLEPU)
                : $this->trans('No', [], self::DOMENA_SKLEPU)
        );
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
