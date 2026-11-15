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
 * Obok nazwy roli licznik pozycji na każdy słownik („Pojazdy: 90"). Dłuższa tabela
 * dostaje pole wyszukiwania; zawężanie wierszy robi wyspa motywu
 * `islands/dictionaries.js`, a licznik pokazuje wtedy „Pojazdy: 12 z 90".
 *
 * Wygląd: `q-table` motywu, ta sama tabela co specyfikacja produktu.
 *}

{foreach from=$qdSekcje item=qdSekcja}
  <div class="q-dict"{if $qdSekcja.searchable} data-q-dict
       data-msg-of="{l s='%name%: %shown% of %total%' d='Modules.Quincedictionaries.Shop'}"
       data-msg-empty="{l s='No entries match “%query%”.' d='Modules.Quincedictionaries.Shop'}"{/if}>
    <div class="q-dict__head">
      <p class="q-dict__title">{$qdSekcja.role}</p>
      <p class="q-dict__count" aria-live="polite">
        {foreach from=$qdSekcja.counts item=qdLicznik name=qdLiczniki}
          <span data-q-dict-count data-dictionary="{$qdLicznik.key}" data-name="{$qdLicznik.name}"
                data-total="{$qdLicznik.count|intval}">{$qdLicznik.name}: {$qdLicznik.count|intval}</span>{if !$smarty.foreach.qdLiczniki.last} · {/if}
        {/foreach}
      </p>
    </div>

    {if $qdSekcja.searchable}
      <input class="q-field q-dict__search" type="search" data-q-dict-search
             placeholder="{l s='Search the table' d='Modules.Quincedictionaries.Shop'}"
             aria-label="{l s='Search the table' d='Modules.Quincedictionaries.Shop'}">
    {/if}

    <div class="q-table-wrap" data-q-dict-table>
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
            <tr data-dictionary="{$qdWiersz.dictionary}">
              <td>{$qdWiersz.path}</td>
              {foreach from=$qdWiersz.values item=qdWartosc}
                <td>{$qdWartosc}</td>
              {/foreach}
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    {if $qdSekcja.searchable}
      <p class="q-dict__empty" data-q-dict-empty hidden></p>
    {/if}
  </div>
{/foreach}
