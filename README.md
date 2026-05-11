# e-Timologio PHP Library

A PHP library and HTTP API for automating the [ΑΑΔΕ e-timologio](https://mydata.aade.gr/timologio) invoicing platform — the Greek tax authority's official invoicing system for myDATA.

> **⚠️ Proof of Concept** — This project works but is not a polished production library. It was built by reverse-engineering the e-timologio jQuery AJAX calls. Anyone is free to use, fork, and improve it.

---

## What's included

| File | Purpose |
|------|---------|
| `e-timologio.php` | Core library — use standalone as HTTP API or `require_once` from your own scripts |
| `greek-invoice-generator.php` | Ready-to-use HTML form for issuing invoices from the browser |
| `config.example.php` | Configuration template — copy to `config.php` and fill in your credentials |

---

## What it does

- **Issues invoices** to ΑΑΔΕ (myDATA) and retrieves a MARK number
- **Creates draft invoices** for safe testing before going live
- **Issues credit notes** (πιστωτικά) with automatic data lookup from the original invoice
- **Auto-creates customers** from Taxisnet data (Greek clients)
- **Auto-populates** customer name, address, city, zip from Taxisnet or e-timologio database
- **Fetches invoice PDFs** by MARK number
- **Lists issued and draft invoices** by date range, type, or buyer VAT
- **Supports all common invoice types**: ΑΠΥ, ΑΛΠ, Τιμολόγιο B2B, EU, non-EU (0% VAT)
- **Supports withholding tax** (παρακρατούμενος φόρος)
- **Supports invoice language** (Greek or English)
- **Caches product catalogue and series** to disk — avoids redundant API calls
- **Dual-mode**: works as a PHP library (`require_once`) or standalone HTTP API endpoint

---

## Supported Invoice Types

| Code | myDATA | Description |
|------|--------|-------------|
| `1`  | 1.1 | Τιμολόγιο Πώλησης (B2B, domestic goods) |
| `20` | 2.1 | Τιμολόγιο Παροχής Υπηρεσιών (B2B, GR) |
| `21` | 2.2 | Τιμολόγιο Παροχής / Ενδοκοινοτική (B2B, EU) |
| `22` | 2.3 | Τιμολόγιο Παροχής - Τρίτες Χώρες (0% VAT) |
| `57` | 11.1 | ΑΛΠ (Απόδειξη Λιανικής Πώλησης) |
| `58` | 11.2 | ΑΠΥ (Απόδειξη Παροχής Υπηρεσιών) |
| `61` | 11.4 | Πιστωτικό Στοιχείο Λιανικής (requires `correlated_mark`) |

---

## Setup

### Requirements
- PHP 8.1+
- `curl` extension enabled
- Web server (Apache / Nginx / Synology Web Station)
- Writable directory for cookie and cache files (created automatically under `files/`)

### Installation

```bash
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git
cd YOUR_REPO
cp config.example.php config.php
```

Edit `config.php` and fill in your credentials:

```php
$ETIMOLOGIO_CONFIG = [
    'company_vat'      => '123456789',       // Your company ΑΦΜ
    'username'         => 'your_username',    // e-timologio username
    'subscription_key' => 'your_key_here',   // Ρυθμίσεις → Στοιχεία Χρήστη
    'company_name'     => 'YOUR COMPANY',
];
```

Your subscription key is found in e-timologio under **Ρυθμίσεις → Στοιχεία Χρήστη**.

---

## Usage — HTML Form (greek-invoice-generator.php)

Drop both files on your web server, configure `config.php`, and open `greek-invoice-generator.php` in your browser. You get a clean form for issuing ΑΠΥ and ΤΠΥ invoices, with:

- Automatic customer lookup from Taxisnet by AFM
- Live VAT and withholding calculation
- Draft mode (safe testing) and Live mode (real MARK)
- PDF view and download after issuance
- Greek and English invoice language

**First run:** visit `greek-invoice-generator.php?rebuild=true` once to build the product and series cache.

---

## Usage — HTTP API (e-timologio.php)

All requests go to `e-timologio.php`. Parameters can be passed via GET or POST.

### Build cache (run once after setup)
```
?products=1       — build product catalogue cache
?categories=1     — build series cache
```

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

### Non-EU invoice (0% VAT)
```
?amount=1000&type=22&payment=6&name=ACME+CORP&country=US&live=1
```

### Credit note (auto-fetches amount and customer from original)
```
?correlated_mark=400000000000001&type=61&payment=3&live=1
```

### Invoice with notes printed on the document
```
?amount=500&type=58&notes=Ref+12345&live=1
```

### Invoice in English
```
?amount=500&type=58&language=en&live=1
```

### Retrieve PDF by MARK (base64 JSON)
```
?mark=400000000000001
```

### Retrieve PDF as raw binary (opens in browser)
```
?mark=400000000000001&pdf_raw=1
```

### Customer lookup
```
?afm=801725430
```

### List issued invoices
```
?invoices=1&date_from=01/01/2026&date_to=30/04/2026
?invoices=1&date_from=01/01/2026&date_to=30/04/2026&type=58
```

---

## Usage — PHP Library

```php
require_once 'config.php';
require_once 'e-timologio.php';

// Always start with login — reuse $ch across multiple calls
$ch = etimologio_login();

// Taxisnet lookup (read-only)
$info = etimologio_taxisnet($ch, '007690144');
// Returns: ['name', 'address', 'city', 'zip', 'doy'] or null

// Find or auto-create a Greek customer
$customer = etimologio_customer($ch, '007690144');

// Full customer record including email and phone
$full = etimologio_customer_full($ch, '007690144');

// List all saved customers
$customers = etimologio_customers($ch);

// List products (served from cache)
$products = etimologio_products($ch);

// List invoice categories and series
$categories = etimologio_categories($ch);

// Get series for a specific invoice type (from cache — no network call)
$series = etimologio_series_for_type('58');

// List issued invoices by date range
$invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026');
$invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026', '58');        // filter by type
$invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026', '', '007690144'); // filter by AFM

// Fetch single invoice by MARK
$inv = etimologio_invoice($ch, '400012848306927');

// Get PDF as base64
$pdf = etimologio_pdf($ch, '400012848306927');

// Create a draft invoice
$result = etimologio_create($ch, [
    'amount'      => 500.00,
    'type'        => '58',
    'payment'     => 6,
    'description' => 'ΥΠ001',
    'language'    => 'el',
]);

// Create a live invoice with Greek customer
$result = etimologio_create($ch, [
    'amount'               => 1000.00,
    'type'                 => '20',
    'payment'              => 5,
    'afm'                  => '007690144',
    'withholding_category' => 3,
    'withholding_amount'   => 200.00,
    'live'                 => true,
]);

// Credit note — auto-fetches amount and customer from original
$result = etimologio_create($ch, [
    'type'            => '61',
    'payment'         => 3,
    'correlated_mark' => '400013026753577',
    'live'            => true,
]);

// Always close when done
etimologio_close($ch);
```

---

## Parameters (HTTP API)

| Parameter | Description | Default |
|-----------|-------------|---------|
| `afm` | Greek VAT number (9 digits) or foreign VAT string | — |
| `amount` | Net amount in EUR, VAT calculated automatically | — |
| `type` | Invoice type code (see table above) | `58` |
| `payment` | Payment method code (1–8) | `3` |
| `name` | Customer name (auto-populated if afm given) | — |
| `address` | Street address (auto-populated if afm given) | — |
| `city` | City (auto-populated if afm given) | — |
| `zip` | Postal code (auto-populated if afm given) | — |
| `country` | ISO country code | `GR` |
| `branch` | Branch number | `0` |
| `description` | Product code from your e-timologio catalogue | `ΥΠ001` |
| `language` | Invoice language: `el` or `en` | `el` |
| `notes` | Free-text note printed on the invoice | — |
| `withholding_category` | Withholding tax category (1–7) | — |
| `withholding_amount` | Withheld amount in EUR | — |
| `correlated_mark` | MARK of original invoice (for credit notes) | — |
| `mark` | MARK of issued invoice — returns PDF | — |
| `pdf_raw` | Set to `1` to stream PDF binary instead of base64 | — |
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
    "series": "A",
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
    "qrUrl": "https://...",
    "type": "58",
    "series": "A",
    "amount_net": 500.00,
    "amount_vat": 120.00,
    "amount_total": 620.00
}
```

### PDF retrieval (base64 JSON)
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

1. Logs in via form POST and maintains a session cookie in `files/`
2. Fetches product classifications per invoice type from `/Product/GetProduct` — cached to disk after first use
3. Fetches series configuration from `/series/ListSeries` — also cached
4. Auto-populates customer data from Taxisnet for Greek clients, or from the e-timologio customer database for foreign clients
5. Submits invoices to `/Invoice/create` (live) or `/TempInvoice/savetempinvoice` (draft)
6. Retrieves PDFs from `/Invoice/PrintInvoice2PdfNew`

The field names and payload structure were reverse-engineered by intercepting jQuery AJAX calls in the browser.

---

## Security Notes

- Keep `config.php` out of version control — it is listed in `.gitignore`
- The `files/` directory holds your session cookie and cache — ensure it is not web-accessible
- This API is designed for **private intranet use** — do not expose it to the public internet without adding authentication

---

## Status & Roadmap

This is a working proof of concept. Known gaps and areas for improvement are tracked in `todo.txt`. Contributions and forks are very welcome.

---

## License

MIT License — free to use, modify, and distribute.

---

## Disclaimer

This software is provided as-is, as a proof of concept, with no warranties of any kind. The author assumes no responsibility for any tax, legal, or financial consequences arising from its use. Always verify invoices issued through this tool against your official e-timologio account. Use at your own risk.

---

## Authors

- **Konstantinos N. Rokas, Ph.D.** — initial implementation, library refactor, HTML generator
- **scanmydata** — extended CRUD operations, TODO roadmap, official manual

Released as a community contribution to the Greek developer ecosystem.
