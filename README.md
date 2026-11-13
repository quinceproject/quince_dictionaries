# Quince Dictionaries

**Catalogue dictionaries sent through the Admin API and shown on the product page, for
PrestaShop 9.2.**

Some product data does not belong to a single product: which vehicles a part fits, the
OEM numbers it replaces, the engine codes it works with. Quince Dictionaries keeps that
data as dictionaries, receives it from an outside system (a PIM or an ERP) through the
PrestaShop Admin API, and shows it on the product page as tables.

The shop only receives. It never fetches anything by itself, and it is not the place
where the structure of a dictionary is edited.

> Quince Dictionaries is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Admin API for the sending system.** Five endpoints under
  `/admin-api/quince-dictionaries/dictionaries`, with OAuth2 `client_credentials` and
  the scopes `quince_dictionaries_read` and `quince_dictionaries_write`:

  | Method | Address | What it does |
  |--------|---------|--------------|
  | `PUT`  | `/{key}` | the definition: names, levels, fields |
  | `POST` | `/{key}/entries/batch` | up to 1000 entries |
  | `PUT`  | `/{key}/links/batch` | the links of several products, by SKU |
  | `PUT`  | `/{key}/products/{sku}/links` | the links of one product |
  | `GET`  | `/{key}` | the state of the dictionary and the last sync |

  Full description with examples: [API.md](API.md).
- **Safe to send again.** Entries are saved by their code, so the same package sent
  twice changes nothing. A product's links are sent as a complete set and replace the
  previous set of that SKU.
- **Hand-made rows are kept.** Entries and links entered in the shop are never
  overwritten by what the API sends.
- **Code changes without losing links.** An entry sent with `previousCode` moves to its
  new code together with its links and its child entries.
- **Retired, not deleted.** A retired entry disappears from the product page, but the
  entry and its links stay.
- **Partial packages.** A package of entries saves the correct rows and returns the
  refused ones with a reason (`manual`, `unknown_level`, `invalid_code` and others). A
  package as a whole is refused only when it cannot be read: wrong `schemaVersion`,
  unknown dictionary, too many rows.
- **Links before products.** A link to a SKU the shop does not have yet is kept. Once
  the product exists, **Match links to products by SKU** in the back office attaches it,
  without sending anything again. The same button repairs links after a shop is
  restored from a backup.
- **Sync log.** For each dictionary the shop records when data came in, how many entries
  and links, and what was refused.
- **On the product page.** One table per role of the links, for example vehicle fitment
  in one table and OEM numbers in another. The first column is the path of the entry
  ("Brand > Model > Engine"), the next ones the fields of the link and of the entry.
  The back office decides which roles the customer sees.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

Quince Engine hosts the settings screen and places the dictionary tables on the product
page. The module declares it as a dependency, so PrestaShop refuses to install Quince
Dictionaries without it. The tables use the styles of the Quince theme.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_dictionaries.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_dictionaries/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_dictionaries` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Dictionaries and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/dictionaries:*"
php ../bin/console prestashop:module install quince_dictionaries
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_dictionaries/releases).

Uninstalling the module deletes the dictionaries and their links to products.

## Configuration

1. **Create an API client** for the sending system in **Advanced Parameters → Admin
   API** with the scopes `quince_dictionaries_read` and `quince_dictionaries_write`,
   and give its id and secret to that system. See [API.md](API.md), section 1.
2. **Place the tables on the product page.** In Quince Engine, **Product page**, add
   the `displayQuinceDictionaries` hook where the tables should stand.
3. **Choose the roles.** Open **Quince project → Dictionaries**. The screen lists the
   dictionaries in the shop with their last sync, and the roles that came with the
   links; untick a role to hide its table from customers.

## Architecture

- **API Platform resources** in `src/ApiPlatform` with processors that read the
  dictionary key and SKU from the address only, never from the body.
- **`Sync\Intake`** applies the saving rules and writes the sync log.
- **Flat tables for reading**: `quince_dictionary`, `quince_dictionary_entry`,
  `quince_dictionary_link` and `quince_dictionary_sync`, indexed for the two questions
  the shop asks: what fits this product, and which products fit this entry.
- **Symfony controller and Twig** for the settings screen, inside the Quince Engine
  back office shell.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_dictionaries.git
cd quince_dictionaries
composer install
```

The folder must be called `quince_dictionaries`, the same as the module. A clone of
this repository already has that name.

To build an installable archive, zip the `quince_dictionaries/` folder **with its
`vendor/` directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
