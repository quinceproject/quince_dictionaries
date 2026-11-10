<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Dictionaries\Controller\Admin;

use Quince\Dictionaries\Settings\DictionariesSettings;
use Quince\Dictionaries\Storage\Repository;
use Quince\Engine\Controller\Admin\QuinceController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran słowników: co sklep ma u siebie, kiedy to przyszło i co z tego pokazuje.
 *
 * Dziedziczy po klasie z silnika, więc strona dostaje ten sam sidebar, pasek
 * zapisu i wygląd co reszta panelu Quince.
 */
class SettingsController extends QuinceController
{
    private const DOMENA = 'Modules.Quincedictionaries.Admin';

    public function __construct(
        private readonly DictionariesSettings $settings,
        private readonly Repository $repository
    ) {
    }

    public function indexAction(): Response
    {
        $slowniki = [];

        foreach ($this->repository->dictionaries() as $slownik) {
            $poziomy = json_decode((string) $slownik['levels'], true);
            $nazwy = json_decode((string) $slownik['names'], true);

            $slowniki[] = [
                'key' => (string) $slownik['dict_key'],
                'name' => is_array($nazwy) ? (string) ($nazwy[$this->getContext()->language->iso_code] ?? reset($nazwy)) : (string) $slownik['dict_key'],
                'levels' => is_array($poziomy) ? count($poziomy) : 0,
                'entries' => (int) $slownik['entries'],
                'links' => (int) $slownik['links'],
                'source' => (string) $slownik['source'],
                'sync' => $this->repository->lastSync((int) $slownik['id_dictionary']),
            ];
        }

        return $this->renderPanel(
            '@Modules/quince_dictionaries/views/templates/admin/settings.html.twig',
            [
                'settings' => $this->settings->all(),
                'dictionaries' => $slowniki,
                'roles' => $this->roles(),
                'rolesOff' => $this->settings->rolesOff(),
                'layoutTitle' => 'Dictionaries',
                'layoutTitleDomain' => self::DOMENA,
                'quinceRoute' => 'quince_dictionaries',
                'quinceNavRoute' => 'quince_dictionaries',
            ]
        );
    }

    /**
     * Role, które naprawdę przyszły z danymi.
     *
     * Listy ról nie wymyślamy: to system wysyłający dane mówi, w jakim znaczeniu produkt jest
     * powiązany z wpisem, a sklep tylko decyduje, czy to pokazać.
     *
     * @return string[]
     */
    private function roles(): array
    {
        $wiersze = \Db::getInstance()->executeS(
            'SELECT DISTINCT role FROM `' . _DB_PREFIX_ . \Quince\Dictionaries\Storage\Schema::LINK . '` ORDER BY role'
        );

        return is_array($wiersze)
            ? array_values(array_filter(array_map(static fn (array $w): string => (string) $w['role'], $wiersze)))
            : [];
    }

    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_dictionaries_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_dictionaries');
        }

        $this->settings->save($request->request->all());
        $this->addFlash('success', $this->trans('Settings saved.', self::DOMENA));

        return $this->redirectToRoute('quince_dictionaries');
    }

    /** Ponowne przypisanie powiązań do produktów po numerze katalogowym. */
    public function resolveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_dictionaries_resolve', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_dictionaries');
        }

        $this->repository->resolveProducts();
        $this->addFlash('success', $this->trans('Links matched to products by SKU again.', self::DOMENA));

        return $this->redirectToRoute('quince_dictionaries');
    }
}
