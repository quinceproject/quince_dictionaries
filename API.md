# Quince Dictionaries API

Admin API endpoints through which an outside system (for example a PIM) sends catalogue
dictionaries to the shop: the definition, the entries and the links between entries and
products. The shop only receives; it never fetches anything by itself.

All addresses below are relative to `https://shop.example.com/admin-api`.

---

## 1. Authentication

PrestaShop Admin API, OAuth2, `client_credentials`. Create the client in the back office
(**Advanced Parameters → Admin API**) or from the console:

```
php bin/console prestashop:api-client create my-pim \
  --name="My PIM" \
  --scopes=quince_dictionaries_read,quince_dictionaries_write
```

Token:

```http
POST /admin-api/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id=my-pim
&client_secret=…
&scope=quince_dictionaries_read quince_dictionaries_write
```

The scope has to be given **both when the client is created and when the token is
requested**. A token without it gets `403 Invalid scope`.

| Scope | For |
|---|---|
| `quince_dictionaries_read` | reading the state of a dictionary |
| `quince_dictionaries_write` | sending definitions, entries and links |

**Multistore.** Every request needs the shop context, `?shopId=1` (or `shopGroupId`, or
`allShops`). Dictionaries are shared by the whole installation, but the parameter is
still required.

**Format version.** Every write request carries `schemaVersion`. The shop reads `1.0`.
Any other value is refused with `422` and nothing is saved.

---

## 2. Order of sending

1. The definition of the dictionary (`PUT /quince-dictionaries/dictionaries/{key}`).
2. The entries, in packages of up to 1000 (`POST …/entries/batch`).
3. The links of products (`PUT …/links/batch` or `PUT …/products/{sku}/links`).

A link to a code the dictionary does not have yet is refused (`unknown_entry`), so entries
go before links. Within a package of entries the order does not matter.

---

## 3. Definition

```http
PUT /quince-dictionaries/dictionaries/vehicles?shopId=1
Content-Type: application/json

{
  "schemaVersion": "1.0",
  "names": { "pl": "Pojazdy", "en": "Vehicles" },
  "levels": [
    { "key": "brand", "names": { "pl": "Marka", "en": "Brand" } },
    { "key": "model", "names": { "pl": "Model", "en": "Model" } }
  ],
  "fields": [
    { "key": "years", "type": "text", "scope": "link", "names": { "en": "Years" } }
  ]
}
```

- `{key}`: lower case letters, digits, `-` and `_`, up to 64 characters. Taken from the
  address only; a `key` in the body is ignored.
- `levels`: at least one, each with a `key`. Entries are checked against these keys.
- `names`, `fields`: stored as sent and shown by the shop; the shop does not edit them.

Answer `200` with the state of the dictionary (see §6). Sending the same definition again
replaces the previous one.

A dictionary maintained by hand in the shop is not overwritten: `409`.

---

## 4. Entries

```http
POST /quince-dictionaries/dictionaries/vehicles/entries/batch?shopId=1
Content-Type: application/json

{
  "schemaVersion": "1.0",
  "cursor": "2026-11-10T08:00:00Z",
  "entries": [
    {
      "code": "v-1001",
      "level": "brand",
      "path": "Example Motors",
      "labels": { "pl": "Example Motors", "en": "Example Motors" },
      "tags": { "brand": "example-motors" },
      "fields": {}
    },
    {
      "code": "v-1002",
      "previousCode": "v-0999",
      "parentCode": "v-1001",
      "level": "model",
      "path": "Example Motors > Roadster",
      "labels": { "pl": "Roadster" },
      "tags": { "brand": "example-motors", "model": "roadster" },
      "retired": false
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `code` | stable identifier of the entry, up to 64 characters. Required. Saving is idempotent by `code` |
| `previousCode` | the code the entry had before. Moves the entry, its links and its children to `code` |
| `parentCode` | the parent entry. Not checked, so the order of entries does not matter |
| `level` | one of the level keys of the definition |
| `path` | the ready path shown in the shop, up to 512 characters |
| `labels` | name per language |
| `tags` | filter keys per level, computed by the sender |
| `fields` | values of the entry fields |
| `retired` | `true` hides the entry in the shop; the entry and its links stay |
| `cursor` | optional, the sender's progress marker. The shop only records it in the sync log |

At most 1000 entries per request; more is refused with `422` and nothing is saved.

Answer `201`:

```json
{
  "key": "vehicles",
  "saved": 1,
  "rejected": [
    { "code": "v-0001", "reason": "manual" }
  ]
}
```

A row that cannot be saved does not stop the rest of the package. Reasons:

| Reason | Why |
|---|---|
| `invalid_row` | the row is not an object |
| `invalid_code` | `code` empty or longer than 64 characters (also `previousCode`, `parentCode`) |
| `unknown_level` | `level` is not in the definition |
| `invalid_field` | `labels`, `tags` or `fields` is not an object, or `path` is too long |
| `manual` | the entry was entered by hand in the shop and is left as it is |

When `previousCode` points at an entry entered by hand, that entry is not moved.

---

## 5. Links

Links of a product always come as a **complete set**: the set replaces everything the
shop received for that SKU in this dictionary before. Links entered by hand in the shop
stay; if a sent link is the same as a manual one (same entry, product and role), the
manual one stays.

### 5.1 Several products

```http
PUT /quince-dictionaries/dictionaries/vehicles/links/batch?shopId=1
Content-Type: application/json

{
  "schemaVersion": "1.0",
  "products": [
    {
      "sku": "EX-4471",
      "links": [
        { "code": "v-1002", "role": "fitment", "fields": { "years": "2001-2005" } }
      ]
    },
    { "sku": "EX-4472", "links": [] }
  ]
}
```

At most 5000 links per request in total. A single product with more links (complete sets
of a few thousand happen) goes alone in one request and is accepted in full. An empty
`links` removes the product's links.

Answer `200`:

```json
{
  "key": "vehicles",
  "savedProducts": 2,
  "savedLinks": 1,
  "rejected": [],
  "unmatchedSkus": ["EX-4472"]
}
```

### 5.2 One product

```http
PUT /quince-dictionaries/dictionaries/vehicles/products/EX-4471/links?shopId=1
Content-Type: application/json

{
  "schemaVersion": "1.0",
  "links": [ { "code": "v-1002", "role": "fitment" } ]
}
```

Answer `200` with `savedLinks`, `rejected` and `productFound`. A SKU with `/` does not fit
in the address; send such a product with §5.1.

### 5.3 SKU and products

SKU is the product's `reference`. A SKU the shop does not have is **not** refused: its
links are saved without a product and listed in `unmatchedSkus` (or `productFound:
false`). They show up on the product page once a product with that reference exists and
**Match links to products by SKU** is clicked in the module's settings, for example after
restoring the shop from a backup. Nothing has to be sent again.

Reasons for a refused link:

| Reason | Why |
|---|---|
| `invalid_product` | a product without `sku` or without a `links` list |
| `invalid_code` | `code` empty or longer than 64 characters |
| `missing_role` | `role` empty or longer than 64 characters |
| `invalid_field` | `fields` is not an object |
| `unknown_entry` | the dictionary has no entry with that `code` |

---

## 6. State

```http
GET /quince-dictionaries/dictionaries/vehicles?shopId=1
```

```json
{
  "key": "vehicles",
  "schemaVersion": "1.0",
  "names": { "pl": "Pojazdy", "en": "Vehicles" },
  "levels": [ { "key": "brand", "names": { "pl": "Marka" } } ],
  "fields": [],
  "source": "sync",
  "entries": 2,
  "retiredEntries": 0,
  "links": 1,
  "unmatchedLinks": 0,
  "lastSync": {
    "date": "2026-11-10 08:00:05",
    "entries": 1,
    "links": 0,
    "status": "partial",
    "message": "rejected 1: v-0001: manual",
    "cursor": "2026-11-10T08:00:00Z"
  }
}
```

Every write request leaves a record in the sync log (`lastSync` shows the last one):
how many entries or links were saved, `ok` or `partial`, and the first rejected rows.

---

## 7. Errors

| Status | When |
|---|---|
| `400` | no shop context (`shopId`) on a multistore installation |
| `401` | no token, or the token expired |
| `403` | the token does not have the scope |
| `404` | the dictionary does not exist yet; send its definition first |
| `409` | the dictionary is maintained by hand in the shop |
| `422` | the whole request was refused: unknown `schemaVersion`, too many rows, no levels in a definition. Nothing was saved |

Trust the HTTP status, not the `status` field in the body: in development mode
PrestaShop writes `500` in the body even when the answer is `404` or `422`.
