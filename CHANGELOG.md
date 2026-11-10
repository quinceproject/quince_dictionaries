# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
