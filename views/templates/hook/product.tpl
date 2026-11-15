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
      <div class="q-dict__searchbox">
        <input class="q-field q-dict__search" type="search" data-q-dict-search
               placeholder="{l s='Search the table' d='Modules.Quincedictionaries.Shop'}"
               aria-label="{l s='Search the table' d='Modules.Quincedictionaries.Shop'}">
        {* Krzyżyk motywu (ten sam co `q-modal__close`), nie przeglądarki. *}
        <button class="q-dict__clear" type="button" data-q-dict-clear hidden
                aria-label="{l s='Clear search' d='Modules.Quincedictionaries.Shop'}">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/></svg>
        </button>
      </div>
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
