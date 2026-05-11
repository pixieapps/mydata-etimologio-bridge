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

### Create personal customer (without AFM)
```
?create_personal_customer=1&cust_name=ΔΟΥΡΑΜΑΝΗΣ%20ΑΝΤΩΝΙΟΣ&cust_city=ΒΑΡΗ&cust_zip=16672&cust_address=ΠΑΡΑΔΕΙΣΟΥ%2016&cust_job_description=ΙΔΙΩΤΗΣ
```

### List customers (first page)
```
?list_customers=1
```

### List all customers (paginated backend fetch)
```
?all_customers=1&customers_page_size=1000&customers_max_pages=20
```

### Search issued invoices
```
?search_invoices=1&issue_date_from=2026-01-01&issue_date_to=2026-12-31
```

### Search issued invoices with status/type filters
```
?search_invoices=1&issue_date_from=01/04/2026&issue_date_to=11/05/2026&invoice_status=0&search_invoice_type=
```

### Search temporary invoices
```
?search_temp=1&save_date_from=2026-01-01&save_date_to=2026-12-31
```

### Delete temporary invoice
```
?delete_temp_id=<TEMP_ID>&seller_vat=123456789
```

### Delete customer by code
```
?delete_customer_code=21
```

### Delete customer by VAT
```
?delete_customer_vat=159712098
```

### Delete customer with strict cross-check (VAT + code)
```
?delete_customer_code=28&delete_customer_vat=159712098
```

### Update customer fields (by VAT)
```
?update_customer=1&update_customer_vat=159712098&update_phone1=2108970726
```

### Update customer fields (by customer code)
```
?update_customer=1&update_customer_code=28&update_phone1=2108970726&update_email=test@example.com
```

### Update customer fields with strict cross-check (VAT + code)
```
?update_customer=1&update_customer_vat=159712098&update_customer_code=28&update_phone1=2108970726
```

Note: Customer `update/delete` now require a unique exact match. If more than one exact match is returned, the API stops with an `Ambiguous customer selection` error instead of updating/deleting the wrong record.

### List invoice series
```
?list_series=1
```

### Delete series by id
```
?delete_series_id=1176448
```

### Create new invoice series (invoice category)
```
?new_series=1&series_invoice_type=58&series_code=API&series_start_aa=1&series_description=API%20Series
```

### Update invoice series (invoice category)
```
?update_series_id=1176448&series_description=Updated%20Description
```

### List deductions
```
?list_deductions=1
```

### Create deduction
```
?new_deduction=1&deduction_description=Παρακράτηση%20test&deduction_amount_type=2&deduction_amount=10&deduction_decrease_total_paid=2
```

### Update deduction
```
?update_deduction_code=123&deduction_description=Updated&deduction_amount_type=2&deduction_amount=5&deduction_decrease_total_paid=2
```

### Delete deduction by code
```
?delete_deduction_code=123
```

### List products / services catalogue
```
?list_products=1
```

### Delete product by code
```
?delete_product_code=1
```

### List product categories
```
?list_product_categories=1
```

### Create new product
```
?new_product=1&product_type=2&product_code=PROD-001&product_description=Service Name&product_category=974082&unit=1&vat_category=1
```

### Update existing product
```
?update_product_code=PROD-001&product_type=2&product_description=Updated Description&product_category=974082&unit=1&vat_category=1
```

### Delete product by code
```
?delete_product_code=PROD-001
```

### Create new product category
```
?new_product_category=1&category_name=New Category Name
```

### Update product category
```
?update_category_id=974082&category_name=Updated Category Name
```

### Delete product category by id
```
?delete_product_category_id=904494
```

### Get company profile (current saved values)
```
?company_profile=1
```

### Get company data from Taxisnet
```
?company_from_taxis=1
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
| `list_customers` | Return customer list page (supports `afm`, `customer_code`, `customer_name`) | `0` |
| `all_customers` | Iterate customer pages and return aggregated list | `0` |
| `customers_page_size` | Requested customer batch size (max `1000`) | `1000` |
| `customers_max_pages` | Safety cap for multi-page customer fetch | `20` |
| `search_invoices` | Search issued invoices | `0` |
| `issue_date_from` | Invoice search start date (`YYYY-MM-DD` or `DD/MM/YYYY`) | first day of month |
| `issue_date_to` | Invoice search end date (`YYYY-MM-DD` or `DD/MM/YYYY`) | today |
| `search_invoice_type` | Invoice type filter used by search form (`InvoiceType`) | empty |
| `invoice_status` | Invoice status filter: `0`=all, `1`=cancelled, `2`=valid | `0` |
| `buyer_vat` | Buyer VAT filter for invoice/temp search | — |
| `series` | Invoice series filter for invoice search | — |
| `include_cancelled` | Include canceled invoices in results | `0` |
| `search_counterpart` | Search as counterpart in invoice list | `0` |
| `search_b2g` | Filter B2G invoices in invoice list | `0` |
| `search_temp` | Search temporary saved invoices | `0` |
| `save_date_from` | Temp invoice search start date (`YYYY-MM-DD` or `DD/MM/YYYY`) | first day of month |
| `save_date_to` | Temp invoice search end date (`YYYY-MM-DD` or `DD/MM/YYYY`) | today |
| `temp_id` | Temporary invoice id filter during `search_temp` | — |
| `delete_temp_id` | Delete temp invoice by id | — |
| `seller_vat` | Seller VAT used by temp delete endpoint | `COMPANY_VAT` |
| `delete_customer_code` | Delete customer by internal customer code (strict exact selector) | — |
| `delete_customer_vat` | Delete customer by VAT (strict exact selector) | — |
| `update_customer` | Update existing customer fields | `0` |
| `update_customer_vat` | Target customer VAT for update (strict selector, can combine with code) | — |
| `update_customer_code` | Target customer code for update (strict selector, can combine with VAT) | — |
| `update_name` | Updated customer name | — |
| `update_address` | Updated customer address | — |
| `update_city` | Updated customer city | — |
| `update_zip` | Updated customer postal code | — |
| `update_doy` | Updated customer DOY | — |
| `update_email` | Updated customer email | — |
| `update_phone1` | Updated customer phone 1 | — |
| `update_phone2` | Updated customer phone 2 | — |
| `update_job_description` | Updated customer profession/activity | — |
| `create_personal_customer` | Create personal customer without AFM | `0` |
| `cust_name` | Personal customer name (required) | — |
| `cust_city` | Personal customer city (required) | — |
| `cust_zip` | Personal customer zip code (required) | — |
| `cust_address` | Personal customer address | — |
| `cust_doy` | Personal customer DOY | `ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ` |
| `cust_country` | Personal customer country code | `GR` |
| `cust_job_description` | Personal customer occupation/activity | `ΙΔΙΩΤΗΣ` |
| `cust_email` | Personal customer email | — |
| `cust_phone1` | Personal customer primary phone | — |
| `cust_phone2` | Personal customer secondary phone | — |
| `cust_language` | Personal customer language (e.g. `el-GR`) | `el-GR` |
| `cust_is_b2g` | Mark personal customer as B2G (`1`/`0`) | `0` |
| `cust_code` | Personal customer internal code | — |
| `cust_vat` | Personal customer VAT (optional) | — |
| `cust_old_vat` | Previous VAT value (optional) | — |
| `list_series` | Return invoice series table | `0` |
| `new_series` | Create invoice series (invoice category) | `0` |
| `update_series_id` | Update invoice series by id | — |
| `series_invoice_type` | Invoice type code for series create/update | — |
| `series_code` | Series code (max 10) | — |
| `series_start_aa` | Starting AA for series | `1` |
| `series_description` | Series description | — |
| `series_trans_failure` | Transmission failure flag (`1`/`0`) | `0` |
| `delete_series_id` | Delete invoice series by id | — |
| `list_deductions` | Return deductions table | `0` |
| `new_deduction` | Create deduction | `0` |
| `update_deduction_code` | Update deduction by code | — |
| `deduction_description` | Deduction description | — |
| `deduction_amount_type` | Deduction type: `1`=percentage, `2`=amount | — |
| `deduction_amount` | Deduction amount/value | — |
| `deduction_decrease_total_paid` | Decrease total paid: `1`=yes, `2`=no | — |
| `delete_deduction_code` | Delete deduction by code | — |
| `list_products` | Return product/service catalogue | `0` |
| `delete_product_code` | Delete product by code | — |
| `new_product` | Create new product/service | `0` |
| `product_type` | Product type: `1` = good, `2` = service | — |
| `product_code` | Unique product code (alphanumeric, max 20 chars) | — |
| `product_description` | Product/service description | — |
| `product_category` | Product category ID (from `list_product_categories`) | — |
| `taric_code` | Taric code for goods classification | — |
| `unit_price` | Unit price in EUR | `0` |
| `vat_category` | VAT category: `1`=24%, `2`=13%, `3`=6%, `4`=17%, `5`=9%, `6`=4%, `7`=0%, `8`=Exempt | `1` |
| `unit` | Unit of measure: `1`=piece, `2`=kg, `3`=liter, `4`=meter, `5`=sq.m, `6`=cu.m, `7`=other | — |
| `special_type` | Special product type/fee category | — |
| `fees_with_vat` | Category for fees with VAT | — |
| `other_taxes_with_vat` | Category for other taxes with VAT | — |
| `update_product_code` | Update existing product by code | — |
| `list_product_categories` | Return product categories table | `0` |
| `new_product_category` | Create new product category | `0` |
| `category_name` | Category name for creation/update | — |
| `update_category_id` | Update product category by ID | — |
| `delete_product_category_id` | Delete product category by id | — |
| `company_profile` | Return current company profile values | `0` |
| `company_from_taxis` | Fetch company profile snapshot from Taxisnet | `0` |

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
