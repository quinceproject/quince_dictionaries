# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.1] - 2026-11-21

### Changed

- Product page: one column per dictionary level (for example Type, Make, Model, Engine)
  instead of one column with the whole path. Column headings are the level names from
  the definition, in the shop language; the values are the names of the entry and its
  parents. A level column that is empty in every row of a table is left out.
- Product page: field columns are headed with the field name from the definition and
  its unit, instead of the field key.
- Product page: the table title is the role name, when the definition sends one.

### Added

- Admin API: the dictionary definition accepts optional `roles`, each with a `key` and
  `names` per language. Without it the product page shows the role key, as before.
  The module settings show the role name next to its key.

### Fixed

- Product page: field values sent per language (translated fields and choice lists)
  showed as empty cells. They now show in the shop language.

## [0.3.0] - 2026-11-16

### Added

- Product page: the section heading shows the number of entries in each dictionary,
  and a table with more than 10 rows gets a search field with a clear button. Needs
  the Quince theme 0.1.5.

### Changed

- Admin API: a product carries at most 10 000 dictionary links. A request with more
  is refused with 422 and nothing is saved. The set of links of a product is saved in
  one transaction: when saving fails, the previous set stays.
- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.2.5] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could open and save the module's
  settings. The screens now belong to the module's own menu tab
  (`AdminQuinceDictionaries`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.2.4] - 2026-11-13

### Fixed

- The back office no longer sends you to the shop's home page after this module is
  installed from its release ZIP. The index.php file in every folder of the package
  redirected and stopped the process also when PrestaShop loaded it while scanning
  `src/ApiPlatform/Resources`, so the back office could not be built.

## [0.2.3] - 2026-11-10

### Added

- Polish translation of every text the module shows, in the back office and in the shop.

## [0.2.2] - 2026-11-10

First public release.

### Added

- Admin API endpoints through which an outside system (a PIM or an ERP) sends
  dictionaries to the shop: the definition, entries in packages of up to 1000, and the
  links of products by SKU, several products at a time or one product at a time.
- Scopes `quince_dictionaries_read` and `quince_dictionaries_write`.
- Idempotent saving by entry code; `previousCode` moves an entry and its links to a new
  code; retired entries are hidden in the shop but keep their links.
- Rows entered by hand in the shop are never overwritten by what the API sends.
- A package of entries saves the correct rows and returns the refused ones with a reason.
- Links to a SKU the shop does not have yet are kept and attached later with
  **Match links to products by SKU**.
- A sync log per dictionary: when, how many entries and links, what was refused.
- Back office screen with the dictionaries in the shop and the roles shown on the
  product page.
- One table per role on the product page, placed by the Quince Engine product page
  layout.
