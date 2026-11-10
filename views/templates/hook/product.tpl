{**
 * Quince Dictionaries: słowniki na karcie produktu.
 *
 * Po jednej tabeli na rolę powiązania: dopasowanie do pojazdów, numery OEM,
 * kody silnika. Nazwa roli jest nagłówkiem, bo to ona niesie znaczenie.
 *
 * Pierwsza kolumna to ścieżka wpisu („Audi › A4 B5 › S4 quattro"), dalej pola
 * powiązania i wpisu. Ścieżka przychodzi gotowa przez API, więc tabela nie odtwarza
 * drzewa słownika.
 *
 * Wygląd: `q-table` motywu, ta sama tabela co specyfikacja produktu.
 *}

{foreach from=$qdSekcje item=qdSekcja}
  <div class="q-dict">
    <p class="q-dict__title">{$qdSekcja.role}</p>

    <div class="q-table-wrap">
      <table class="q-table">
        <thead>
          <tr>
            <th scope="col">{l s='Entry' d='Modules.Quincedictionaries.Shop'}</th>
            {foreach from=$qdSekcja.columns item=qdKolumna}
              <th scope="col">{$qdKolumna}</th>
            {/foreach}
          </tr>
        </thead>
        <tbody>
          {foreach from=$qdSekcja.rows item=qdWiersz}
            <tr>
              <td>{$qdWiersz.path}</td>
              {foreach from=$qdWiersz.values item=qdWartosc}
                <td>{$qdWartosc}</td>
              {/foreach}
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
{/foreach}
