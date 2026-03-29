# e-Timologio PHP API

A lightweight PHP API for automating the [ΑΑΔΕ e-timologio](https://mydata.aade.gr/timologio) invoicing platform — the Greek tax authority's official invoicing system for myDATA.

This is believed to be the first open-source PHP implementation of the e-timologio API.

> **⚠️ Proof of Concept** — This project works but is not a polished production library. It was built by reverse-engineering the e-timologio jQuery AJAX calls. Anyone is free to use, fork, and improve it.

---

## What it does

- **Issues invoices** to ΑΑΔΕ (myDATA) and retrieves a MARK number
- **Creates draft invoices** for safe testing before going live
- **Auto-creates customers** from Taxisnet data (Greek clients)
- **Auto-populates** customer name, address, city, zip from Taxisnet or e-timologio database
- **Fetches invoice PDFs** by MARK number
- **Supports all common invoice types**: ΑΠΥ, ΑΛΠ, Τιμολόγιο, non-EU (0% VAT)
- **Supports withholding tax** (παρακρατούμενος φόρος)
- **Works as a REST API** — accepts both GET and POST parameters
- Returns clean JSON responses

> **Note on invoice series:** This implementation assumes **Series A** (`'series' => 'A'`) for all invoices. If your e-timologio setup uses a different series, update the `series` field in `createInvoice()` accordingly.

---

## Supported Invoice Types

| Code | Type | Description |
|------|------|-------------|
| `20` | 2.1 | Τιμολόγιο Παροχής Υπηρεσιών (B2B, GR) |
| `21` | 2.2 | Τιμολόγιο Παροχής / Ενδοκοινοτική (B2B, EU) |
| `22` | 2.3 | Τιμολόγιο Παροχής - Τρίτες Χώρες (0% VAT) |
| `57` | 11.1 | ΑΛΠ (Απόδειξη Λιανικής Πώλησης) |
| `58` | 11.2 | ΑΠΥ (Απόδειξη Παροχής Υπηρεσιών) |

---

## Setup

### Requirements
- PHP 8.0+
- `curl` extension enabled
- Web server (Apache/Nginx) or Synology Web Station

### Installation

```bash
git clone https://github.com/pixieapps/mydata-etimologio-bridge.git
cd mydata-etimologio-bridge
cp config.example.php config.php
```

Edit `config.php` and fill in your credentials:

```php
const COMPANY_VAT      = '123456789';         // Your company ΑΦΜ
const USERNAME         = 'your_username';      // e-timologio username
const SUBSCRIPTION_KEY = 'your_key_here';      // Found in Ρυθμίσεις → Στοιχεία Χρήστη
```

Your subscription key is found in e-timologio under **Ρυθμίσεις → Στοιχεία Χρήστη**.

---

## Usage

All requests go to `etimologio.php`. Parameters can be passed via GET or POST.

### Draft invoice (safe for testing)
```
?amount=500&type=58&payment=3
```

### Live invoice (submitted to AADE, real MARK assigned)
```
?amount=500&type=58&payment=3&live=1
```

### Invoice with customer AFM (auto-creates customer, auto-populates details)
```
?afm=801725430&amount=500&type=58&payment=6&live=1
```

### B2B invoice with withholding tax (20%)
```
?afm=801725430&amount=1000&type=20&payment=5&withholding_category=3&withholding_amount=200&live=1
```

### Non-EU client invoice (0% VAT, customer pre-saved in e-timologio)
```
?afm=FOREIGN&amount=1000&type=22&payment=6&live=1
```

### Retrieve PDF by MARK (returns base64 JSON)
```
?mark=400000000000001
```

### Retrieve PDF as raw binary (opens in browser)
```
?mark=400000000000001&pdf_raw=1
```

### Customer lookup only
```
?afm=801725430
```

---

## Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `afm` | Greek VAT number (9 digits) or foreign VAT string | — |
| `amount` | Net amount in EUR, VAT calculated automatically | — |
| `type` | Invoice type code (see table above) | `58` |
| `payment` | Payment method (1–8, see below) | `3` |
| `name` | Customer name (auto-populated if afm given) | — |
| `address` | Street address (auto-populated if afm given) | — |
| `city` | City (auto-populated if afm given) | — |
| `zip` | Postal code (auto-populated if afm given) | — |
| `country` | ISO country code | `GR` |
| `branch` | Branch number | `0` |
| `description` | Product code from your e-timologio catalogue | `ΥΠ001` |
| `withholding_category` | Withholding tax category (1–7) | — |
| `withholding_amount` | Withheld amount in EUR | — |
| `mark` | MARK of issued invoice — returns PDF | — |
| `live` | Set to `1` to issue real invoice | `0` |

### Payment Methods

| Code | Method |
|------|--------|
| `1` | Επαγγελματικός Λογαριασμός Πληρωμών Ημεδαπής |
| `2` | Επαγγελματικός Λογαριασμός Πληρωμών Αλλοδαπής |
| `3` | Μετρητά |
| `4` | Επιταγή |
| `5` | Επί πιστώσει |
| `6` | Web Banking |
| `7` | POS / e-POS |
| `8` | Άμεσες Πληρωμές IRIS |

### Withholding Tax Categories

| Code | Description |
|------|-------------|
| `1` | Περ. β' - Τόκοι 15% |
| `2` | Περ. γ' - Δικαιώματα 20% |
| `3` | Περ. δ' - Αμοιβές Συμβούλων Διοίκησης 20% |
| `4` | Περ. δ' - Τεχνικά Έργα 3% |
| `7` | Παροχή Υπηρεσιών 8% |

---

## Response Format

### Successful draft invoice
```json
{
    "success": true,
    "live": false,
    "temp_id": "abc123...",
    "type": "58",
    "amount_net": 500.00,
    "amount_vat": 120.00,
    "amount_total": 620.00,
    "note": "DRAFT only - not submitted to AADE, no MARK assigned"
}
```

### Successful live invoice
```json
{
    "success": true,
    "live": true,
    "mark": "400000000000001",
    "aa": "5",
    "qrUrl": "",
    "type": "58",
    "amount_net": 500.00,
    "amount_vat": 120.00,
    "amount_total": 620.00
}
```

### PDF retrieval (default JSON/base64)
```json
{
    "success": true,
    "mark": "400000000000001",
    "filename": "invoice-400000000000001.pdf",
    "mime": "application/pdf",
    "size": 45231,
    "pdf_base64": "JVBERi0x..."
}
```

---

## How it works

e-timologio is an ASP.NET server-rendered application with jQuery AJAX. This library:

1. Logs in via form POST, maintains a session cookie
2. Fetches product/classification data per invoice type from `/Product/GetProduct`
3. Auto-populates customer data from Taxisnet (`/Customer/GetCustomerByTaxis`) for Greek clients, or from the e-timologio customer database (`/Customer/GetProposedCustomersByName`) for foreign clients
4. Submits invoices to `/Invoice/create` (live) or `/TempInvoice/savetempinvoice` (draft)
5. Retrieves PDFs from `/Invoice/PrintInvoice2PdfNew`

The field names and payload structure were reverse-engineered by intercepting jQuery AJAX calls in the browser.

---

## Security Notes

- Keep `config.php` out of version control (it is in `.gitignore`)
- This API is designed for **private intranet use** — do not expose it to the public internet without adding authentication
- The cookie file (`/tmp/etimologio_cookies.txt`) contains your session — ensure it is not web-accessible

---

## Status

This is a **proof of concept**. It works, but it is not a polished library. Contributions, improvements, and forks are very welcome. Some areas that could use work:

- Support for more invoice types (11.1 ΑΛΠ verified, others assumed)
- Multi-line invoice support
- Proper error handling and retry logic
- Unit tests
- Refactor into a proper PHP class/library
- Laravel/Symfony package

If you build on this, please share your work with the Greek developer community.

---

## License

MIT License — free to use, modify, and distribute.

---

## Disclaimer

This software is provided as-is, as a proof of concept, with no warranties of any kind. The author assumes no responsibility for any tax, legal, or financial consequences arising from its use. Always verify invoices issued through this tool against your official e-timologio account. Use at your own risk.

---

## Author

Konstantinos N. Rokas, Ph.D.

Released as a community contribution to the Greek developer ecosystem. Feel free to open issues or submit pull requests.
