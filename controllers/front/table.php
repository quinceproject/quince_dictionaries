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

use Quince\Dictionaries\Front\ProductTables;

/**
 * Strona tabeli słownika na karcie produktu, jako JSON.
 *
 * Karta przynosi tylko pierwszą stronę długiej tabeli (produkt bywa powiązany z prawie
 * dwoma tysiącami pojazdów). Kolejne strony i wyszukiwanie w całej tabeli przychodzą
 * stąd. Tabelę liczymy tą samą klasą co karta, więc kolumny są te same na każdej stronie.
 *
 * Tylko odczyt danych, które i tak widać na karcie: produkt aktywny w tym sklepie,
 * rola niewyłączona w ustawieniach. Wartości oddajemy jako tekst, wyspa motywu
 * wstawia je przez `textContent`.
 */
class Quince_DictionariesTableModuleFrontController extends ModuleFrontController
{
    /** @var bool nie renderujemy strony, tylko JSON */
    public $ajax = true;

    /** Najdłuższa fraza wyszukiwania; dłuższa niczego by nie znalazła. */
    private const MAX_QUERY = 100;

    public function postProcess(): void
    {
        $this->ajax = true;
    }

    public function displayAjax(): void
    {
        header('Content-Type: application/json');
        header('X-Robots-Tag: noindex');
        echo json_encode($this->strona(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @return array<string,mixed> */
    private function strona(): array
    {
        $idProduct = (int) Tools::getValue('id_product');
        $rola = (string) Tools::getValue('role');
        $fraza = mb_substr(trim((string) Tools::getValue('q')), 0, self::MAX_QUERY);
        $strona = max(1, (int) Tools::getValue('page', 1));

        $produkt = new Product($idProduct, false, (int) $this->context->language->id, (int) $this->context->shop->id);

        if (!Validate::isLoadedObject($produkt) || !$produkt->active || !$produkt->isAssociatedToShop((int) $this->context->shop->id)) {
            return ['ok' => false];
        }

        /** @var Quince_Dictionaries $modul */
        $modul = $this->module;
        $tabele = $modul->productTables()->forProduct($idProduct);

        if (!isset($tabele[$rola])) {
            return ['ok' => false];
        }

        $wiersze = ProductTables::filter($tabele[$rola]['rows'], $fraza);
        $razem = count($wiersze);
        $strony = max(1, (int) ceil($razem / ProductTables::PAGE_SIZE));
        $strona = min($strona, $strony);

        return [
            'ok' => true,
            'page' => $strona,
            'pages' => $strony,
            'total' => $razem,
            'counts' => ProductTables::countByDictionary($wiersze),
            'rows' => array_map(
                static fn (array $w): array => ['values' => $w['values'], 'dictionary' => $w['dictionary']],
                array_slice($wiersze, ($strona - 1) * ProductTables::PAGE_SIZE, ProductTables::PAGE_SIZE)
            ),
        ];
    }
}
