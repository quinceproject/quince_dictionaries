<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Settings;

use Configuration;
use Quince\Engine\Settings\ConfigStore;

/**
 * Ustawienia słowników.
 *
 * Sklep nie redaguje struktury słownika: ta przychodzi z systemu zewnętrznego jako dane.
 * Ustawieniem jest to, co należy do sklepu: które role pokazywać klientowi.
 * Rola to znaczenie powiązania („dopasowanie do pojazdu", „numer OEM"), i to
 * ona decyduje o widoku, bo ten sam mechanizm niesie w sklepie kilka różnych
 * rzeczy. Dane przysyła system zewnętrzny przez Admin API modułu, więc sklep nie ma tu
 * adresu ani sekretu do pobierania.
 */
final class DictionariesSettings
{
    private const PREFIX = 'QUINCE_DICT_';

    /**
     * Klucze z wersji, w której sklep pobierał dane sam (do 0.2.0). Kasuje je
     * aktualizacja 0.2.1.
     */
    public const RETIRED_KEYS = ['QUINCE_DICT_ENDPOINT', 'QUINCE_DICT_TOKEN', 'QUINCE_DICT_PAGE_SIZE'];

    /** @var array<string,string> klucz => wartość domyślna */
    private const SCHEMA = [
        // Role wyłączone z pokazywania, rozdzielone przecinkiem. Domyślnie
        // pokazujemy każdą rolę, która przyszła z danymi: sklep, który dostał
        // dane, chce je zobaczyć, a nie szukać włącznika.
        'ROLES_OFF' => '',
    ];

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $klucz => $domyslna) {
            $wartosc = Configuration::get(self::PREFIX . $klucz);
            $out[$klucz] = ($wartosc === false || $wartosc === null) ? $domyslna : (string) $wartosc;
        }

        return $out;
    }

    /**
     * Klucze do skasowania przy odinstalowaniu, razem z kluczami z wersji
     * pobierającej, gdyby sklep ominął aktualizację 0.2.1.
     *
     * @return string[]
     */
    public function configurationKeys(): array
    {
        return array_merge(
            array_map(static fn (string $k): string => self::PREFIX . $k, array_keys(self::SCHEMA)),
            self::RETIRED_KEYS
        );
    }

    /** @return string[] role, których sklep nie pokazuje */
    public function rolesOff(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->all()['ROLES_OFF']))));
    }

    public function roleVisible(string $rola): bool
    {
        return !in_array($rola, $this->rolesOff(), true);
    }

    /**
     * Zapis formularza.
     *
     * @param array<string,mixed> $values
     */
    public function save(array $values): void
    {
        // Role przychodzą jako lista zaznaczonych „pokazuj". Znacznik wysyłki
        // odróżnia „nic nie zaznaczono" od „tych pól w formularzu nie było".
        if (array_key_exists('ROLES_SUBMITTED', $values)) {
            $wszystkie = array_map('strval', (array) ($values['ROLES_ALL'] ?? []));
            $widoczne = array_map('strval', (array) ($values['ROLES_ON'] ?? []));
            $wylaczone = array_values(array_diff($wszystkie, $widoczne));

            ConfigStore::set(self::PREFIX . 'ROLES_OFF', implode(',', $wylaczone));
        }
    }
}
