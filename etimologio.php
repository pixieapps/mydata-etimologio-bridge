<?php
// Clear OPcache on every request so file changes take effect immediately.
// Safe to leave permanently — has negligible performance impact on low-traffic endpoints.
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// ============================================================================
// e-Timologio PHP Library + HTTP API — ΑΑΔΕ myDATA
// ============================================================================
//
// DUAL-MODE: This file works as both a PHP library (include/require) and as a
// standalone HTTP API endpoint. When included by another script, only the
// functions and config are loaded — nothing executes. When called directly
// via HTTP, the API entry point at the bottom handles the request.
//
// ============================================================================
// SECTION 1 — CONFIGURATION
// ============================================================================
//
// Edit the values below to match your e-timologio account credentials.
// These are the only values you need to change when deploying.
//
// Cookie file and caches are stored in the files/ subdirectory alongside this script:
//   Cookie:        files/etimologio_{company_vat}.txt
//   Products cache: files/etimologio_products_{company_vat}.json
//   Series cache:   files/etimologio_series_{company_vat}.json
//
// To populate the product cache (auto-built on first use; can also trigger manually):
//   GET ?products=1
// To populate the series cache (auto-built on first use; can also trigger manually):
//   GET ?categories=1
// After that, both are served from cache — no API call needed.
//
// ============================================================================

// Default config — only applied when NOT already set by config.php loaded before this file.
// When used as a library (require_once 'e-timologio.php' after require_once 'config.php'),
// the values from config.php are preserved and these defaults are ignored.
if (empty($ETIMOLOGIO_CONFIG)) {
    $ETIMOLOGIO_CONFIG = [
        'base_url'         => 'https://mydata.aade.gr/timologio',
        'company_vat'      => 'CHANGE_ME',
        'username'         => 'CHANGE_ME',
        'subscription_key' => 'CHANGE_ME',
        'company_name'     => 'CHANGE_ME',

        // Invoice types with zero VAT — fallback when product has no VAT info
        'zero_vat_types'   => ['22'],
    ];
}

// ============================================================================
// SECTION 2 — LIBRARY USAGE GUIDE
// ============================================================================
//
// ── AS A PHP LIBRARY ────────────────────────────────────────────────────────
//
//   require_once 'e_timologio.php';
//
//   // Always start with login — reuse $ch across multiple calls
//   $ch = etimologio_login();
//
//   // Taxisnet lookup (read-only, nothing saved)
//   $info = etimologio_taxisnet($ch, '800764388');
//   // Returns: ['name'=>..., 'address'=>..., 'city'=>..., 'zip'=>..., 'doy'=>...]
//   // Returns: null if AFM not found
//
//   // Find or auto-create a Greek customer
//   $customer = etimologio_customer($ch, '800764388');
//   // Returns: ['success'=>true, 'status'=>'found'|'created', 'code'=>..., 'vat'=>..., 'info'=>[...]]
//
//   // Full customer lookup — DB first, Taxisnet auto-create for Greek, null for unknown foreign
//   // Returns all fields including email, zip, phone (fetched from EditCustomer page)
//   $full = etimologio_customer_full($ch, '800764388');
//   // Returns: ['vat','name','address','city','zip','email','phone','country','code'] or null
//
//   // List all saved customers
//   $customers = etimologio_customers($ch);
//   // Returns: ['success'=>true, 'count'=>N, 'customers'=>[['no','code','type','afm','name','address','city'],...]]
//
//   // List all products/services (plain — from cache if available)
//   $products = etimologio_products($ch);
//   // Returns: ['success'=>true, 'count'=>N, 'products'=>[['no','type','category','code','description','unit_price','vat_pct','unit'],...]]
//
//   // Fetch single product with VAT rate and classifications (queries AADE live)
//   $product = etimologio_product($ch, 'ΥΠ001', '58');
//   // Returns: ['success'=>true, 'code','description','vat_category','vat_rate','classifications'=>[...],'raw'=>...]
//   // $invoiceType: '58'=ΑΠΥ, '20'=ΤΠΥ, '22'=non-EU
//
//   // List invoice categories configured in your account (also writes series cache)
//   $categories = etimologio_categories($ch);
//   // Returns: ['success'=>true, 'count'=>N, 'categories'=>[['no','type','id','series','aa_start','description'],...]]
//
//   // Get all series for a given invoice type (reads from cache — no network call)
//   $series = etimologio_series_for_type('58');
//   // Returns: [['no','type','id','series','aa_start','description'], ...]  or [] if cache missing
//   // Accepts: '1','20','21','22','57','58','61'
//
//   // List issued invoices by date range
//   $invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026');
//   $invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026', '58');          // filter by type
//   $invoices = etimologio_invoices($ch, '01/01/2026', '30/04/2026', '', '800764388'); // filter by AFM
//   // Returns: ['success'=>true, 'count'=>N, 'date_from'=>..., 'date_to'=>..., 'invoices'=>[...]]
//   // Each invoice: ['no','mark','type','issue_date','series','aa','counterpart','net','vat','total']
//
//   // Fetch a single issued invoice by MARK
//   $inv = etimologio_invoice($ch, '400012848306927');
//   // Returns: ['success'=>true, 'mark','type','issue_date','series','aa','counterpart','net','vat','total']
//
//   // Get PDF of issued invoice as base64
//   $pdf = etimologio_pdf($ch, '400012848306927');
//   // Returns: ['success'=>true, 'mark','filename','mime','size','pdf_base64']
//
//   // Create a DRAFT invoice (safe — nothing sent to AADE)
//   $result = etimologio_create($ch, [
//       'amount'      => 500.00,   // net amount, VAT calculated automatically
//       'type'        => '58',     // see invoice type codes below
//       'payment'     => 6,        // see payment codes below
//       'description' => 'ΥΠ001', // product code from your catalogue
//       'language'    => 'el',     // 'el' or 'en'
//   ]);
//   // Returns: ['success'=>true, 'live'=>false, 'temp_id'=>..., 'type','series','amount_net','amount_vat','amount_total']
//
//   // Draft invoice with Greek customer (auto-lookup Taxisnet, creates if not saved)
//   $result = etimologio_create($ch, [
//       'amount'      => 1000.00,
//       'type'        => '20',
//       'payment'     => 5,
//       'afm'         => '800764388',
//       'description' => 'ΥΠ001Τ',
//       'withholding_category' => 3,
//       'withholding_amount'   => 200.00,
//   ]);
//
//   // Draft invoice with manual customer data (no AFM lookup)
//   $result = etimologio_create($ch, [
//       'amount'  => 500.00,
//       'type'    => '58',
//       'payment' => 6,
//       'name'    => 'JOHN SMITH',
//       'address' => '123 MAIN ST',
//       'city'    => 'NEW YORK',
//       'zip'     => '10001',
//       'country' => 'US',
//       'language'=> 'en',
//   ]);
//
//   // Non-EU invoice (0% VAT)
//   $result = etimologio_create($ch, [
//       'amount'      => 1000.00,
//       'type'        => '22',
//       'payment'     => 6,
//       'name'        => 'ACME CORP',
//       'country'     => 'US',
//       'description' => 'ΥΠ000',
//       'language'    => 'en',
//   ]);
//
//   // LIVE invoice (submitted to AADE, MARK assigned)
//   $result = etimologio_create($ch, [
//       'amount'  => 500.00,
//       'type'    => '58',
//       'payment' => 6,
//       'afm'     => '800764388',
//       'live'    => true,
//   ]);
//   // Returns: ['success'=>true, 'live'=>true, 'mark'=>'400012848306927', 'aa','qrUrl','type','series','amount_net','amount_vat','amount_total']
//
//   // Credit note — auto-fetches amount and customer from original invoice
//   $result = etimologio_create($ch, [
//       'type'            => '61',
//       'payment'         => 3,
//       'correlated_mark' => '400013026753577',
//   ]);
//
//   // Optional params available on all etimologio_create() calls:
//   //   'series'  => 'B'  — override series letter (validated against cache; omit to auto-resolve)
//   //   'notes'   => '...' — free-text note printed on the invoice (omit or '' for none)
//   //   'vat_exemption_category' => 4  — vatExemptionCategory code (required when isZeroVat).
//   //     Auto-resolved per invoice type — only pass to override:
//   //     type '22' (non-EU services) → 4 (Άρθρο 14, τόπος παροχής υπηρεσιών) ← default
//   //     type '21' (EU services)     → 4 (Άρθρο 14)
//   //     type '1'  (goods, non-EU)   → 3 (Άρθρο 13, τόπος παράδοσης αγαθών); pass 14 for EU intra-community goods
//   //     type '57','58' (ΑΛΠ/ΑΠΥ 0%)→ 4 (Άρθρο 14, fallback)
//
//   // Override config at runtime (e.g. different credentials)
//   $ch = etimologio_login([
//       'company_vat'      => '999999999',
//       'username'         => 'otheruser',
//       'subscription_key' => 'abc123...',
//   ]);
//
//   // Always close the session when done
//   etimologio_close($ch);
//
// ── INVOICE TYPE CODES ───────────────────────────────────────────────────────
//
//   These are e-timologio's internal type codes (not the myDATA dot-notation).
//   e-timologio translates them to the corresponding myDATA types internally.
//   Only the subtypes below are currently exposed by e-timologio's UI/API.
//   Additional subtypes (e.g. 1.2 non-EU goods, 1.3 EU goods, 2.4 supplementary)
//   would require separate e-timologio category configuration if needed.
//
//   '1'  → 1.1  Τιμολόγιο Πώλησης (B2B, domestic goods/αγαθά)
//   '20' → 2.1  Τιμολόγιο Παροχής Υπηρεσιών (B2B, GR services)
//   '21' → 2.2  Τιμολόγιο Παροχής / Ενδοκοινοτική (B2B, EU services)
//   '22' → 2.3  Τιμολόγιο Παροχής Τρίτων Χωρών (non-EU services, 0% VAT)
//   '57' → 11.1 ΑΛΠ (Απόδειξη Λιανικής Πώλησης)
//   '58' → 11.2 ΑΠΥ (Απόδειξη Παροχής Υπηρεσιών)
//   '61' → 11.4 Πιστωτικό Στοιχείο Λιανικής Συσχετιζόμενο (requires correlated_mark)
//
// ── PAYMENT METHOD CODES ─────────────────────────────────────────────────────
//
//   1 → Επαγγελματικός Λογαριασμός Πληρωμών Ημεδαπής
//   2 → Επαγγελματικός Λογαριασμός Πληρωμών Αλλοδαπής
//   3 → Μετρητά
//   4 → Επιταγή
//   5 → Επί Πιστώσει
//   6 → Web Banking
//   7 → POS / e-POS
//   8 → Άμεσες Πληρωμές IRIS
//
// ── WITHHOLDING TAX CATEGORIES ───────────────────────────────────────────────
//
//   1 → Περ. β' - Τόκοι 15%
//   2 → Περ. γ' - Δικαιώματα 20%
//   3 → Περ. δ' - Αμοιβές Συμβούλων Διοίκησης 20%
//   4 → Περ. δ' - Τεχνικά Έργα 3%
//   7 → Παροχή Υπηρεσιών 8%
//
// ── AS AN HTTP API ───────────────────────────────────────────────────────────
//
//   Call this file directly via HTTP — all parameters via GET or POST.
//
//   Taxisnet lookup (read-only, no customer saved):
//     ?taxisnet=800764388
//
//   Customer find/create (Greek 9-digit AFM only — Taxisnet lookup):
//     ?afm=800764388
//
//   List products (served from cache; auto-builds full cache on first run):
//     ?products=1
//
//   Force full cache rebuild:
//     ?products=1&refresh=1
//
//   Single product with classifications:
//     ?product_lookup=ΥΠ001&inv_type=58
//
//   List invoice categories (fetches live, writes series cache):
//     ?categories=1
//
//   Get series for a specific invoice type (from cache — no network call):
//     ?series_for_type=58
//
//   List customers:
//     ?customers=1
//
//   List invoices (date_from defaults to 1st of month, date_to defaults to today):
//     ?invoices=1&date_from=01/01/2026&date_to=30/04/2026
//     ?invoices=1&date_from=01/01/2026&invoice_type=58
//     ?invoices=1&date_from=01/01/2026&afm=800764388
//
//   Invoice lookup by MARK:
//     ?mark_lookup=400012848306927
//
//   PDF by MARK (JSON with base64):
//     ?mark=400012848306927
//
//   PDF by MARK (raw binary, opens in browser):
//     ?mark=400012848306927&pdf_raw=1
//
//   Draft invoice:
//     ?amount=500&type=58&payment=3
//     ?amount=500&type=58&payment=6&name=JOHN+SMITH&country=US&language=en
//     ?amount=1000&type=20&payment=5&afm=800764388&description=ΥΠ001Τ
//     ?amount=1000&type=20&payment=5&afm=800764388&withholding_category=3&withholding_amount=200
//     ?amount=500&type=58&payment=3&series=B           (override series)
//     ?amount=500&type=58&payment=3&notes=Ref+12345    (add invoice note)
//     ?amount=500&type=22&payment=6&vat_exemption_category=4  (non-EU services, Άρθρο 14)
//
//   Live invoice (submitted to AADE):
//     ?amount=500&type=58&payment=6&afm=800764388&live=1
//
//   Credit note (auto-fills from original invoice):
//     ?type=61&payment=3&correlated_mark=400013026753577
//     ?type=61&payment=3&correlated_mark=400013026753577&language=en
//
// ============================================================================
// SECTION 3 — INTERNAL HELPERS (prefix: _etim_)
// ============================================================================

function _etim_config(): array {
    global $ETIMOLOGIO_CONFIG;
    return $ETIMOLOGIO_CONFIG;
}

function _etim_baseUrl(): string {
    return _etim_config()['base_url'];
}

function _etim_companyVat(): string {
    return _etim_config()['company_vat'];
}

function _etim_isZeroVat(string $invoiceType): bool {
    return in_array($invoiceType, _etim_config()['zero_vat_types']);
}

function _etim_files_dir(): string {
    return __DIR__ . '/files';
}

function _etim_cache_path(): string {
    return _etim_files_dir() . '/etimologio_products_' . _etim_companyVat() . '.json';
}

function _etim_cache_read(): ?array {
    $path = _etim_cache_path();
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function _etim_cache_write(array $data): void {
    file_put_contents(_etim_cache_path(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function _etim_series_cache_path(): string {
    return _etim_files_dir() . '/etimologio_series_' . _etim_companyVat() . '.json';
}

function _etim_series_cache_read(): ?array {
    $path = _etim_series_cache_path();
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function _etim_series_cache_write(array $data): void {
    file_put_contents(_etim_series_cache_path(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function _etim_get(\CurlHandle $ch, string $url): string {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_HTTPGET,        true);
    curl_setopt($ch, CURLOPT_POST,           false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    return curl_exec($ch);
}

function _etim_post(\CurlHandle $ch, string $url, array $fields): string {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($fields));
    return curl_exec($ch);
}

function _etim_postInvoice(\CurlHandle $ch, string $url, array $data): string {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query(['inv' => $data]));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ]);
    return curl_exec($ch);
}

function _etim_token(\CurlHandle $ch, string $url): string {
    $html = _etim_get($ch, $url);
    preg_match('/name="__RequestVerificationToken".*?value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

function _etim_parseCells(string $rowHtml): array {
    if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rowHtml, $m)) return [];
    return array_map(function($c) {
        return html_entity_decode(trim(strip_tags($c)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $m[1]);
}

function _etim_parseRows(string $html): array {
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $m);
    return $m[1] ?? [];
}

function _etim_parseInvoiceRow(array $cells): array {
    return [
        'no'          => (int) trim($cells[0]),
        'mark'        => trim($cells[1]),
        'type'        => trim($cells[2]),
        'issue_date'  => substr(trim($cells[3]), -10), // strip hidden AADE date prefix
        'series'      => trim($cells[5]),
        'aa'          => trim($cells[6]),
        'counterpart' => trim($cells[7]),
        'net'         => trim($cells[8]),
        'vat'         => trim($cells[9]),
        'total'       => trim($cells[10]),
    ];
}

function _etim_searchInvoices(\CurlHandle $ch, array $params): string {
    $base     = _etim_baseUrl();
    $listHtml = _etim_get($ch, $base . '/invoice/listinvoices');
    preg_match('/name="__RequestVerificationToken".*?value="([^"]+)"/', $listHtml, $m);
    $token = $m[1] ?? '';
    if (!$token) return '';
    curl_setopt($ch, CURLOPT_REFERER, $base . '/invoice/listinvoices');
    return _etim_post($ch, $base . '/invoice/SearchInvoices', array_merge([
        '__RequestVerificationToken' => $token,
        'invoiveFormat'              => '1',
        'Mark'                       => '',
        'IssueDateFrom'              => date('01/m/Y'),
        'IssueDateTo'                => date('d/m/Y'),
        'InvoiceType'                => '',
        'Series'                     => '',
        'BuyerVatNumber'             => '',
        'searchCancelledInvoices'    => '0',
        'searchB2GInvoices'          => 'false',
        'btnSearch'                  => '',
    ], $params));
}

function _etim_search_customers_html(\CurlHandle $ch, string $vatFilter = '', int $maxRows = 10): string {
    $base  = _etim_baseUrl();
    $token = _etim_token($ch, $base . '/customer/ListCustomers');
    return _etim_post($ch, $base . '/customer/SearchCustomers', [
        'Language'                            => 'el-GR',
        'CompanyVat'                          => _etim_companyVat(),
        'CustomerVat'                         => $vatFilter,
        'CustomerCode'                        => '',
        'CustomerName'                        => '',
        'NextPartitionKey'                    => '',
        'NextRowKey'                          => '',
        'continuationToken.continuationToken' => '',
        'totalFechedRows'                     => (string) $maxRows,
        'PrevCustomerCode'                    => '',
        'PrevCustomerVat'                     => '',
        'PrevCustomerName'                    => '',
        'btnSearch'                           => 'btnSearch',
        '__RequestVerificationToken'          => $token,
    ]);
}

function _etim_enrich_products(\CurlHandle $ch, array &$products): void {
    foreach ($products as &$p) {
        $allClassifications = [];
        $vatCategory        = null;
        $vatRate            = null;
        foreach (['58', '57', '1', '20', '22'] as $invType) {
            $detail = etimologio_product($ch, $p['code'], $invType);
            if (!$detail['success']) continue;
            if ($vatCategory === null) {
                $vatCategory = $detail['vat_category'];
                $vatRate     = $detail['vat_rate'];
            }
            foreach ($detail['classifications'] as $cl) {
                if (!empty($cl['category'])) {
                    $allClassifications[$invType][] = $cl;
                }
            }
        }
        $p['vat_category']    = $vatCategory;
        $p['vat_rate']        = $vatRate;
        $p['classifications'] = $allClassifications;
    }
    unset($p);
}

// HTTP-only response helpers — only used in standalone mode
function _etim_jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function _etim_jsonError(string $message, int $status = 400): void {
    _etim_jsonResponse(['success' => false, 'error' => $message], $status);
}

// ============================================================================
// SECTION 4 — PUBLIC API FUNCTIONS (prefix: etimologio_)
// ============================================================================

/**
 * Login to e-timologio and return a cURL session handle.
 * Reuse $ch across multiple calls; close with etimologio_close() when done.
 *
 * @param  array|null  $config  Override any config keys (optional)
 * @return \CurlHandle
 * @throws \RuntimeException on login failure
 */
function etimologio_login(?array $config = null): \CurlHandle {
    global $ETIMOLOGIO_CONFIG;
    if ($config !== null) $ETIMOLOGIO_CONFIG = array_merge($ETIMOLOGIO_CONFIG, $config);

    $cfg  = _etim_config();
    $base = $cfg['base_url'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $cookiePath = _etim_files_dir() . '/etimologio_' . $cfg['company_vat'] . '.txt';
    curl_setopt($ch, CURLOPT_COOKIEJAR,      $cookiePath);
    curl_setopt($ch, CURLOPT_COOKIEFILE,     $cookiePath);
    curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');

    $token = _etim_token($ch, $base . '/Account/Login');
    if (!$token) throw new \RuntimeException('Could not reach e-timologio');

    _etim_post($ch, $base . '/Account/Login', [
        'UserName'                   => $cfg['username'],
        'VatNumber'                  => $cfg['company_vat'],
        'SubscriptionKey'            => $cfg['subscription_key'],
        'ReturnUrl'                  => '/timologio',
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (strpos($finalUrl, 'Login') !== false) throw new \RuntimeException('Login failed — check credentials');

    return $ch;
}

/**
 * Close and release a cURL session.
 */
function etimologio_close(\CurlHandle $ch): void {
    curl_close($ch);
}

/**
 * Look up a Greek AFM on Taxisnet via e-timologio.
 * Read-only — does NOT save the customer to your database.
 *
 * @return array|null  ['name', 'address', 'city', 'zip', 'doy'] or null if not found
 */
function etimologio_taxisnet(\CurlHandle $ch, string $afm): ?array {
    $response = _etim_get($ch, _etim_baseUrl() . '/Customer/GetCustomerByTaxis?' . http_build_query([
        'companyVat'  => _etim_companyVat(),
        'customerVat' => $afm,
    ]));
    $data = json_decode($response, true);
    if (!$data || !empty($data['errorDescr'])) return null;
    return [
        'name'    => $data['n']  ?? '',
        'address' => $data['a']  ?? '',
        'city'    => $data['ct'] ?? '',
        'zip'     => $data['z']  ?? '',
        'doy'     => $data['do'] ?? '',
    ];
}

/**
 * Find an existing customer by AFM, or auto-create from Taxisnet (Greek AFMs only).
 *
 * @return array  ['success', 'status' ('found'|'created'|'error'), 'code', 'vat', 'info']
 */
function etimologio_customer(\CurlHandle $ch, string $afm): array {
    $base  = _etim_baseUrl();
    $cvat  = _etim_companyVat();

    $html = _etim_search_customers_html($ch, $afm, 10);

    $existingCode = null;
    if (preg_match('/<td[^>]*>\s*' . preg_quote($afm, '/') . '\s*<\/td>/', $html)) {
        preg_match('/<tr>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?' . preg_quote($afm, '/') . '/s', $html, $row);
        $existingCode = $row[2] ?? null;
    }

    // Always fetch full info from Taxisnet
    $info = etimologio_taxisnet($ch, $afm) ?? [];

    if ($existingCode !== null) {
        return ['success' => true, 'status' => 'found', 'code' => $existingCode, 'vat' => $afm, 'info' => $info];
    }

    if (empty($info)) {
        return ['success' => false, 'status' => 'error', 'error' => 'AFM not found in Taxisnet'];
    }

    // Create the customer
    $createToken = _etim_token($ch, $base . '/customer/NewCustomer');
    if (!$createToken) return ['success' => false, 'status' => 'error', 'error' => 'Could not get creation token'];

    _etim_post($ch, $base . '/customer/NewCustomer', [
        'CompanyVAT'                 => $cvat,
        'Language'                   => 'el-GR',
        'OldCustomerVat'             => $afm,
        'CustomerType'               => '2',
        'Country'                    => 'GR',
        'isB2GCustomer'              => 'false',
        'CustomerCode'               => '',
        'CustomerVat'                => $afm,
        'CustomerName'               => strtoupper($info['name']),
        'JobDescription'             => '',
        'CustomerAddress'            => $info['address'],
        'CustomerCity'               => $info['city'],
        'CustomerZipCode'            => $info['zip'],
        'Doy'                        => $info['doy'],
        'CustomerEmail'              => '',
        'CustomerPhone1'             => '',
        'CustomerPhone2'             => '',
        '__RequestVerificationToken' => $createToken,
    ]);

    if (strpos(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'NewCustomer') !== false) {
        return ['success' => false, 'status' => 'error', 'error' => 'Failed to create customer'];
    }

    // Re-search to get the assigned code
    $html2 = _etim_search_customers_html($ch, $afm, 10);
    $newCode = null;
    if (preg_match('/<tr>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?' . preg_quote($afm, '/') . '/s', $html2, $row2)) {
        $newCode = $row2[2] ?? null;
    }

    return ['success' => true, 'status' => 'created', 'code' => $newCode, 'vat' => $afm, 'info' => $info];
}

/**
 * Find a customer by VAT and return full details (name, address, city, zip, email, phone, country).
 * For Greek 9-digit AFMs: auto-creates in DB from Taxisnet if not found.
 * For foreign AFMs: returns null if not in DB.
 *
 * Fetches the EditCustomer page to get fields not exposed by the search list (zip, email, phone).
 *
 * @return array|null  ['vat','name','address','city','zip','email','phone','country','code'] or null
 */
function etimologio_customer_full(\CurlHandle $ch, string $afm): ?array {
    $base = _etim_baseUrl();

    // Helper: search DB and return [code, encrCode] for the matching AFM row, or null
    $searchForAfm = function() use ($ch, $afm): ?array {
        $html = _etim_search_customers_html($ch, $afm, 10);

        // Find the row containing this AFM
        foreach (_etim_parseRows($html) as $rowHtml) {
            $cells = _etim_parseCells($rowHtml);
            if (count($cells) < 7 || trim($cells[3]) !== $afm) continue;
            $code = $cells[1] ?? '';

            // Extract EncrCustomerCode from the view/edit href in this row
            $encrCode = null;
            if (preg_match('/href="[^"]*(?:viewcustomer|EditCustomer)\?cd=([^"&]+)/i', $rowHtml, $m)) {
                $encrCode = $m[1];
            }

            return ['code' => $code, 'encrCode' => $encrCode];
        }
        return null;
    };

    // Step 1 — search DB
    $match = $searchForAfm();

    // Step 2 — not in DB: auto-create for Greek AFMs via Taxisnet, give up for foreign
    if ($match === null) {
        if (!preg_match('/^\d{9}$/', $afm)) return null;
        $result = etimologio_customer($ch, $afm);
        if (!$result['success']) return null;
        $match = $searchForAfm(); // re-search to get EncrCustomerCode of the new record
        if ($match === null) return null;
    }

    // Step 3 — fetch edit page for full field values
    if (empty($match['encrCode'])) {
        // No encrypted code — return what we have from search cells (no zip/email)
        return [
            'vat'     => $afm,
            'name'    => '',
            'address' => '',
            'city'    => '',
            'zip'     => '',
            'email'   => '',
            'phone'   => '',
            'country' => '',
            'code'    => $match['code'],
        ];
    }

    $editHtml = _etim_get($ch, $base . '/customer/EditCustomer?cd=' . urlencode($match['encrCode']));

    // Parse input field values: <input id="FieldId" ... value="...">
    $field = function(string $id) use ($editHtml): string {
        if (preg_match('/<input[^>]*id="' . preg_quote($id, '/') . '"[^>]*value="([^"]*)"/i', $editHtml, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    };

    // Parse selected option from Country dropdown
    $country = '';
    if (preg_match('/<select[^>]*id="Country"[^>]*>(.*?)<\/select>/s', $editHtml, $sel)) {
        if (preg_match('/<option[^>]*selected[^>]*value="([^"]+)"/i', $sel[1], $co)) {
            $country = $co[1];
        }
    }

    return [
        'vat'     => $afm,
        'name'    => $field('CustomerName'),
        'address' => $field('CustomerAddress'),
        'city'    => $field('CustomerCity'),
        'zip'     => $field('CustomerZipCode'),
        'email'   => $field('CustomerEmail'),
        'phone'   => $field('CustomerPhone1'),
        'country' => $country,
        'code'    => $match['code'],
    ];
}

/**
 * List all customers saved in your e-timologio account (up to 500).
 *
 * @return array  ['success', 'count', 'customers' => [...]]
 */
function etimologio_customers(\CurlHandle $ch): array {
    $html = _etim_search_customers_html($ch, '', 500);

    $customers = [];
    foreach (_etim_parseRows($html) as $rowHtml) {
        $cells = _etim_parseCells($rowHtml);
        if (count($cells) < 7 || !ctype_digit($cells[0])) continue;
        $customers[] = [
            'no'      => (int) $cells[0],
            'code'    => $cells[1] ?? '',
            'type'    => $cells[2] ?? '',
            'afm'     => $cells[3] ?? '',
            'name'    => $cells[4] ?? '',
            'address' => $cells[5] ?? '',
            'city'    => $cells[6] ?? '',
        ];
    }

    if (empty($customers)) {
        return ['success' => false, 'error' => 'Could not parse customers — page structure may have changed'];
    }
    return ['success' => true, 'count' => count($customers), 'customers' => $customers];
}

/**
 * List all products/services in your e-timologio catalogue.
 * Returns full data (vat_category, classifications) when served from cache.
 * Cache is built automatically on first use; force rebuild with ?products=1&refresh=1.
 *
 * @return array  ['success', 'count', 'products' => [...]]
 */
function etimologio_products(\CurlHandle $ch, bool $forceRefresh = false): array {
    // Return from cache if available — includes vat_category and classifications
    if (!$forceRefresh) {
        $cached = _etim_cache_read();
        if ($cached !== null && !empty($cached['products'])) {
            return $cached;
        }
    }

    // Cache not built yet or forced refresh — fetch plain list from AADE
    $html = _etim_get($ch, _etim_baseUrl() . '/product/products');

    $products = [];
    foreach (_etim_parseRows($html) as $rowHtml) {
        $cells = _etim_parseCells($rowHtml);
        // Columns (verified live 2026-04-26):
        // 0=#, 1=empty, 2=companyVat, 3=Τύπος, 4=cat_id, 5=empty, 6=Κωδ.είδους, 7=empty, 8=Περιγραφή, 9=qty, 10=ΦΠΑ%
        if (count($cells) < 11 || !ctype_digit($cells[0])) continue;
        if (trim($cells[6]) === '') continue; // skip rows without a product code
        $products[] = [
            'no'          => (int) $cells[0],
            'type'        => $cells[3] ?? '',
            'category'    => $cells[4] ?? '',
            'code'        => $cells[6] ?? '',
            'description' => $cells[8] ?? '',
            'unit_price'  => '',
            'vat_pct'     => $cells[10] ?? '',
            'unit'        => '',
        ];
    }

    if (empty($products)) {
        return ['success' => false, 'error' => 'Could not parse products — page structure may have changed'];
    }
    return ['success' => true, 'count' => count($products), 'products' => $products];
}

/**
 * List invoice categories (Κατηγορίες Παραστατικών) configured in your account.
 * A category must exist before an invoice of that type can be issued.
 *
 * @return array  ['success', 'count', 'categories' => [...]]
 */
function etimologio_categories(\CurlHandle $ch): array {
    $html = _etim_get($ch, _etim_baseUrl() . '/series/ListSeries');

    $categories = [];
    foreach (_etim_parseRows($html) as $rowHtml) {
        $cells = _etim_parseCells($rowHtml);
        if (count($cells) < 6 || !ctype_digit($cells[0])) continue;
        $categories[] = [
            'no'          => (int) $cells[0],
            'type'        => trim($cells[1]),
            'id'          => trim($cells[2]),
            'series'      => trim($cells[3]),
            'aa_start'    => trim($cells[4]),
            'description' => trim($cells[5]),
        ];
    }

    if (empty($categories)) {
        return ['success' => false, 'error' => 'Could not parse invoice categories — page structure may have changed'];
    }
    $result = ['success' => true, 'count' => count($categories), 'categories' => $categories];
    _etim_series_cache_write($result);
    return $result;
}

/**
 * Get all series configured for a given invoice type (reads from cache — no network call).
 * Returns [] if the series cache has not been populated yet.
 *
 * @param  string  $invoiceType  Invoice type code, e.g. '58'
 * @return array   Array of matching category rows, each: ['no','type','id','series','aa_start','description']
 */
function etimologio_series_for_type(string $invoiceType): array {
    $cached = _etim_series_cache_read();
    if ($cached === null || empty($cached['categories'])) return [];
    return array_values(array_filter($cached['categories'], fn($c) => $c['type'] === $invoiceType));
}

/**
 * List issued invoices within a date range.
 *
 * @param  string  $dateFrom     dd/mm/yyyy (default: 1st of current month)
 * @param  string  $dateTo       dd/mm/yyyy (default: today)
 * @param  string  $invoiceType  Filter by type code e.g. '58' (optional)
 * @param  string  $buyerVat     Filter by counterpart AFM (optional)
 * @return array
 */
function etimologio_invoices(\CurlHandle $ch, string $dateFrom = '', string $dateTo = '', string $invoiceType = '', string $buyerVat = ''): array {
    if ($dateFrom === '') $dateFrom = date('01/m/Y');
    if ($dateTo   === '') $dateTo   = date('d/m/Y');

    $html = _etim_searchInvoices($ch, [
        'IssueDateFrom'  => $dateFrom,
        'IssueDateTo'    => $dateTo,
        'InvoiceType'    => $invoiceType,
        'BuyerVatNumber' => $buyerVat,
    ]);

    $invoices = [];
    foreach (_etim_parseRows($html) as $rowHtml) {
        $cells = _etim_parseCells($rowHtml);
        if (count($cells) < 11 || !ctype_digit(trim($cells[0]))) continue;
        $invoices[] = _etim_parseInvoiceRow($cells);
    }

    return [
        'success'   => true,
        'count'     => count($invoices),
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'invoices'  => $invoices,
        'note'      => empty($invoices) ? 'No invoices found in this date range' : null,
    ];
}

/**
 * Fetch a single issued invoice's data by its MARK number.
 *
 * @return array  ['success', 'mark', 'type', 'issue_date', 'series', 'aa', 'counterpart', 'net', 'vat', 'total']
 */
function etimologio_invoice(\CurlHandle $ch, string $mark): array {
    $html = _etim_searchInvoices($ch, [
        'Mark'          => $mark,
        'IssueDateFrom' => '01/01/2020',
        'IssueDateTo'   => date('d/m/Y'),
    ]);

    foreach (_etim_parseRows($html) as $rowHtml) {
        $cells = _etim_parseCells($rowHtml);
        if (count($cells) < 11 || !ctype_digit(trim($cells[0]))) continue;
        if (trim($cells[1]) !== $mark) continue;
        return array_merge(['success' => true], _etim_parseInvoiceRow($cells));
    }

    return ['success' => false, 'error' => 'Invoice with MARK ' . $mark . ' not found'];
}

/**
 * Get the PDF of an issued invoice by MARK.
 * Returns base64-encoded PDF data — decode to get raw bytes.
 *
 * @return array  ['success', 'mark', 'filename', 'mime', 'size', 'pdf_base64']
 */
function etimologio_pdf(\CurlHandle $ch, string $mark): array {
    $url      = _etim_baseUrl() . '/Invoice/PrintInvoice2PdfNew?' . http_build_query(['mark' => $mark]);
    $response = _etim_get($ch, $url);

    if (!$response || substr($response, 0, 4) !== '%PDF') {
        return ['success' => false, 'error' => 'PDF not found or invalid MARK'];
    }

    return [
        'success'    => true,
        'mark'       => $mark,
        'filename'   => 'invoice-' . $mark . '.pdf',
        'mime'       => 'application/pdf',
        'size'       => strlen($response),
        'pdf_base64' => base64_encode($response),
    ];
}

/**
 * Fetch a single product's VAT rate and AADE classifications for a given invoice type.
 * Queries AADE live — use the products cache for bulk lookups.
 *
 * @param  string  $code         Product code from your catalogue (e.g. 'ΥΠ001')
 * @param  string  $invoiceType  Invoice type code (default '58' = ΑΠΥ)
 * @return array   ['success', 'code', 'description', 'vat_category', 'vat_rate', 'classifications', 'raw']
 */
function etimologio_product(\CurlHandle $ch, string $code, string $invoiceType = '58'): array {
    $base = _etim_baseUrl();
    $cvat = _etim_companyVat();
    $raw  = _etim_get($ch, $base . '/Product/GetProduct?' . http_build_query([
        'sCompanyVat' => $cvat,
        'productCode' => $code,
        'invoiceType' => $invoiceType,
        'selfPrice'   => 'false',
    ]));
    $data = json_decode($raw, true);
    if (empty($data)) {
        return ['success' => false, 'error' => 'Product not found'];
    }
    $vatMap = [1=>0.24, 2=>0.13, 3=>0.06, 4=>0.17, 5=>0.09, 6=>0.04, 7=>0.00, 8=>0.00];
    return [
        'success'         => true,
        'code'            => $code,
        'description'     => $data['d']               ?? '',
        'vat_category'    => $data['v']                ?? null,
        'vat_rate'        => $vatMap[$data['v'] ?? 1]  ?? 0.24,
        'classifications' => array_map(fn($cl) => [
            'invoice_type' => $cl['i']  ?? '',
            'category'     => $cl['cc'] ?? '',
            'category_name'=> $cl['ct'] ?? '',
            'code'         => $cl['tc'] ?? '',
            'code_name'    => $cl['tt'] ?? '',
        ], $data['cl'] ?? []),
        'raw'             => $data,
    ];
}

/**
 * Create a draft or live invoice.
 * Auto-builds product and series caches on first use.
 * Enriches counterpart from customer DB / Taxisnet when 'afm' is supplied.
 *
 * @param  array  $params  See SECTION 2 library guide for full param reference.
 * @return array  ['success', 'live', 'mark'|'temp_id', 'type', 'series', 'amount_net', 'amount_vat', 'amount_total']
 */
function etimologio_create(\CurlHandle $ch, array $params): array {
    $base = _etim_baseUrl();
    $cvat = _etim_companyVat();

    // Extract params with defaults
    $amount              = (float)  ($params['amount']               ?? 0);
    $invoiceType         = (string) ($params['type']                 ?? '58');
    $paymentType         = (int)    ($params['payment']              ?? 3);
    $description         = (string) ($params['description']          ?? 'ΥΠ001');
    $language            = (string) ($params['language']             ?? 'el');
    $live                = (bool)   ($params['live']                 ?? false);
    $afm                 = (string) ($params['afm']                  ?? '');
    $name                = (string) ($params['name']                 ?? '');
    $address             = (string) ($params['address']              ?? '');
    $city                = (string) ($params['city']                 ?? '');
    $zip                 = (string) ($params['zip']                  ?? '');
    $country             = (string) ($params['country']              ?? 'GR');
    $branch              = (string) ($params['branch']               ?? '0');
    $withholdingCategory = (int)    ($params['withholding_category'] ?? 0);
    $withholdingAmount   = (float)  ($params['withholding_amount']   ?? 0.0);
    $correlatedMark      = (string) ($params['correlated_mark']      ?? '');
    $issueDate           = (string) ($params['issue_date']           ?? '');
    $callerSeries        = (string) ($params['series']               ?? '');
    $notes               = (string) ($params['notes']                ?? '');
    $vatExemptionCat     = (int)    ($params['vat_exemption_category'] ?? 0);

    if ($issueDate === '') {
        // Draft and live endpoints use different date formats —
        // savetempinvoice expects d-m-Y, /Invoice/create expects Y-m-d
        $issueDate = $live ? date('Y-m-d') : date('d-m-Y');
    }

    // Fetch product from catalogue — use cache if available, auto-build cache if missing
    $product = null;
    $cached  = _etim_cache_read();
    if ($cached === null || empty($cached['products'])) {
        // Cache doesn't exist yet — build it now (same logic as ?products=1)
        $plain = etimologio_products($ch, false);
        if ($plain['success'] && !empty($plain['products'])) {
            _etim_enrich_products($ch, $plain['products']);
            _etim_cache_write($plain);
            $cached = $plain;
        }
    }
    if ($cached !== null && !empty($cached['products'])) {
        foreach ($cached['products'] as $cp) {
            if ($cp['code'] === $description) {
                $vc      = $cp['vat_category'] ?? 1;
                $product = [
                    'd'  => $cp['description'] ?? '',
                    'v'  => $vc,
                    'cl' => array_map(fn($cl) => [
                        'cc' => $cl['category'] ?? '',
                        'tc' => $cl['code']     ?? '',
                    ], $cp['classifications'][$invoiceType] ?? []),
                ];
                break;
            }
        }
    }
    if ($product === null) {
        // Product not in cache (unknown code) — fall back to live API
        $product = json_decode(_etim_get($ch, $base . '/Product/GetProduct?' . http_build_query([
            'sCompanyVat' => $cvat,
            'productCode' => $description,
            'invoiceType' => $invoiceType,
            'selfPrice'   => 'false',
        ])), true) ?: null;
    }

    // VAT rate — read from product catalogue field 'v' (VAT category code from AADE)
    // v: 1=24%, 2=13%, 3=6%, 4=17%, 5=9%, 6=4%, 7=0%, 8=Άνευ ΦΠΑ
    // Fallback to invoice type zero_vat_types config when product not found
    $vatCategoryMap = [1=>0.24, 2=>0.13, 3=>0.06, 4=>0.17, 5=>0.09, 6=>0.04, 7=>0.00, 8=>0.00];
    $isZeroVat      = _etim_isZeroVat($invoiceType); // initial fallback from config
    if ($product !== null && isset($product['v'])) {
        $vatRate   = $vatCategoryMap[$product['v']] ?? 0.24;
        $isZeroVat = ($vatRate === 0.0);
    } else {
        $vatRate   = $isZeroVat ? 0.0 : 0.24;
    }
    // Ensure $isZeroVat is always consistent with the resolved $vatRate,
    // regardless of which path above set it.
    $isZeroVat = ($vatRate === 0.0);
    $netValue  = round($amount, 2);
    $vatAmount = round($netValue * $vatRate, 2);
    $total     = round($netValue + $vatAmount, 2);

    // Enrich counterpart — DB first, Taxisnet fallback for Greek AFMs (auto-creates if missing)
    if ($afm !== '') {
        $full = etimologio_customer_full($ch, $afm);
        if ($full !== null) {
            if ($name    === '') $name    = $full['name'];
            if ($address === '') $address = $full['address'];
            if ($city    === '') $city    = $full['city'];
            if ($zip     === '') $zip     = $full['zip'];
            if ($country === 'GR' && $full['country'] !== '') $country = $full['country'];
        }
    }

    // Build classifications from product catalogue
    $itemDescr       = isset($product['d']) ? $description . ' - ' . $product['d'] : $description;
    $classifications = [];
    if (!empty($product['cl'])) {
        foreach ($product['cl'] as $cl) {
            $classifications[] = ['clsCategory' => $cl['cc'], 'clsCode' => $cl['tc']];
        }
    } else {
        // Fallback when product has no classifications.
        // category1_3 = Έσοδα από Παροχή Υπηρεσιών (correct for all service invoice types)
        // E3_561_003 = Πωλήσεις αγαθών και υπηρεσιών Λιανικές / E3_561_006 = Εξωτερικού (0% VAT)
        $classifications[] = [
            'clsCategory' => 'category1_3',
            'clsCode'     => $isZeroVat ? 'E3_561_006' : 'E3_561_003',
        ];
    }

    // Withholding tax
    $invoiceTaxes = [];
    if ($withholdingCategory > 0 && $withholdingAmount > 0) {
        $invoiceTaxes[] = [
            'id'              => 1,
            'taxType'         => 1,
            'taxCategory'     => $withholdingCategory,
            'underlyingValue' => $netValue,
            'taxAmount'       => (string) round($withholdingAmount, 2),
            'taxNotes'        => '',
        ];
    }

    // Resolve series letter — auto-populate series cache if missing
    $seriesMatches = etimologio_series_for_type($invoiceType);
    if (empty($seriesMatches)) {
        // Cache missing — fetch live and retry
        etimologio_categories($ch);
        $seriesMatches = etimologio_series_for_type($invoiceType);
    }
    if ($callerSeries !== '') {
        // Caller supplied a series — validate it exists in the cache (if cache is available)
        if (!empty($seriesMatches)) {
            $validSeries = array_column($seriesMatches, 'series');
            if (!in_array($callerSeries, $validSeries, true)) {
                return ['success' => false, 'error' => 'Series "' . $callerSeries . '" is not configured for invoice type ' . $invoiceType . '. Valid: ' . implode(', ', $validSeries)];
            }
        }
        $series = $callerSeries;
    } else {
        $series = !empty($seriesMatches) ? $seriesMatches[0]['series'] : 'A';
        if (empty($seriesMatches)) {
            error_log('etimologio: no series configured for invoice type ' . $invoiceType . ' — defaulting to A. Check e-timologio categories or run ?categories=1');
        }
    }

    // Resolve vatExemptionCategory — per invoice type default, overridable by caller.
    // Required by myDATA whenever vatCategory = 7 (0% VAT).
    // Numeric codes map to current Greek VAT Code (Κ.ΦΠΑ ν.2859/2000) articles:
    //   3  = Άρθρο 13 (πρώην 17) — τόπος παράδοσης αγαθών εκτός Ελλάδας (goods exported outside Greece)
    //   4  = Άρθρο 14 (πρώην 18) — τόπος παροχής υπηρεσιών εκτός Ελλάδας (B2B services, EU or non-EU)
    //   14 = Άρθρο 28 (πρώην 33) — ενδοκοινοτική παράδοση αγαθών (EU B2B goods, intra-community)
    $vatExemptionDefaults = [
        '1'  => 3,  // 1.1  Τιμολόγιο Πώλησης — Άρθρο 13 (non-EU goods); pass 14 for EU intra-community goods
        '20' => 4,  // 2.1  Τιμολόγιο Παροχής Υπηρεσιών — Άρθρο 14 (rare to be 0% for domestic B2B)
        '21' => 4,  // 2.2  Ενδοκοινοτική παροχή υπηρεσιών — Άρθρο 14
        '22' => 4,  // 2.3  Παροχή υπηρεσιών τρίτων χωρών — Άρθρο 14
        '57' => 4,  // 11.1 ΑΛΠ — Άρθρο 14 fallback
        '58' => 4,  // 11.2 ΑΠΥ — Άρθρο 14 fallback
        '61' => 4,  // 11.4 Πιστωτικό — inherits exemption from original invoice
    ];
    $resolvedVatExemption = $vatExemptionCat > 0
        ? $vatExemptionCat
        : ($vatExemptionDefaults[$invoiceType] ?? 4);

    // Build invoice payload
    $invoice = [
        '_invoiceType'              => $invoiceType,
        'CorrelatedInvoice'         => $correlatedMark,
        'selfPricing'               => 'false',
        'paymentType'               => (string) $paymentType,
        'invoiceFormat'             => 1,
        'timologioIssueLanguage'    => $language,
        'DispatchTime'              => '',
        'isDeliveryNote'            => 'false',
        'trans'                     => 'false',
        'isB2G'                     => 'false',
        'tempInvoiceId'             => '',
        'invoiceNotes'              => $notes,
        'transmissionFailure'       => '',
        'ccr_totalNetValueWithDisc' => '',
        'ccr_grossValue'            => '',

        'invoiceHeader' => [
            'series'                     => $series,
            'aa'                         => '',
            'issueDate'                  => $issueDate,
            'vehicleNumber'              => '',
            'movePurpose'                => '',
            'vatPaymentSuspension'       => 'false',
            'currency'                   => '0',
            'exchangeRate'               => '',
            'specialInvoiceCategoryType' => '',
            'otherCorrelatedEntities'    => [],
        ],

        'issuer' => [
            'vatNumber' => '',
            'branch'    => '0',
            'country'   => 'GR',
        ],

        'counterpart' => [
            'vatNumber'         => $afm,
            'branch'            => $branch,
            'country'           => $country,
            'name'              => $name,
            'documentIdNo'      => '',
            'countryDocumentId' => '',
            'customerCode'      => '',
            'emailAddress'      => '',
            'address'           => ($zip !== '' || $address !== '' || $city !== '') ? [
                'street'     => $address,
                'postalCode' => $zip !== '' ? $zip : '00000',
                'city'       => $city,
                'number'     => '0',
            ] : [],
        ],

        'invoiceTaxes' => $invoiceTaxes,

        'invoiceLines' => [[
            'lineNumber'                   => 1,
            'itemId'                       => 1,
            'itemCode'                     => $description,
            'itemDescr'                    => $itemDescr,
            'unitPrice'                    => $netValue,
            'vatCategory'                  => ($product !== null && isset($product['v'])) ? (int) $product['v'] : ($isZeroVat ? 7 : 1),
            'vatExemptionCategory'         => $isZeroVat ? $resolvedVatExemption : '',
            'netValueWithoutDiscount'      => $netValue,
            'discountValue'                => 0,
            'netValueWithDiscount'         => $netValue,
            'vatAmount'                    => $vatAmount,
            'totalValue'                   => $total,
            'discountAmount'               => 0,
            'discountType'                 => 1,
            'isGiftVoucher'                => false,
            'otherMeasurementUnitTitle'    => '',
            'otherMeasurementUnitQuantity' => '',
            'classifications'              => [[
                'classificationKind'     => 1,
                'classificationCategory' => $classifications[0]['clsCategory'],
                'classificationType'     => $classifications[0]['clsCode'],
                'amount'                 => $netValue,
            ]],
        ]],
    ];

    _etim_get($ch, $base . '/invoice/newinvoice');

    if ($live) {
        $response = _etim_postInvoice($ch, $base . '/Invoice/create', $invoice);
        $data     = json_decode($response, true);
        if (!$data) return ['success' => false, 'error' => 'Invalid JSON response', 'raw' => substr($response, 0, 500)];
        if (isset($data['mark'])) {
            return [
                'success'      => true,
                'live'         => true,
                'mark'         => $data['mark'],
                'aa'           => $data['aa']    ?? '',
                'qrUrl'        => $data['qrUrl'] ?? '',
                'type'         => $invoiceType,
                'series'       => $series,
                'amount_net'   => $netValue,
                'amount_vat'   => $vatAmount,
                'amount_total' => $total,
            ];
        }
        return ['success' => false, 'error' => $data['genericMsg'] ?? $data['message'] ?? 'Unknown error', 'raw' => $data];

    } else {
        $response = _etim_postInvoice($ch, $base . '/TempInvoice/savetempinvoice', $invoice);
        $data     = json_decode($response, true);
        if (!$data) return ['success' => false, 'error' => 'Invalid JSON response', 'raw' => substr($response, 0, 500)];
        if (isset($data['resultData'][0])) {
            return [
                'success'      => true,
                'live'         => false,
                'temp_id'      => $data['resultData'][0],
                'type'         => $invoiceType,
                'series'       => $series,
                'amount_net'   => $netValue,
                'amount_vat'   => $vatAmount,
                'amount_total' => $total,
                'note'         => 'DRAFT only — not submitted to AADE, no MARK assigned',
            ];
        }
        return ['success' => false, 'error' => $data['message'] ?? 'Unknown error', 'raw' => $data];
    }
}

// ============================================================================
// SECTION 5 — HTTP ENTRY POINT
// Runs only when this file is called directly via HTTP.
// When included by another PHP script, this block is skipped entirely.
// ============================================================================

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {

    // Auto-load config.php if present alongside this file and credentials not yet set
    if (_etim_config()['company_vat'] === 'CHANGE_ME') {
        $configPath = __DIR__ . '/config.php';
        if (file_exists($configPath)) {
            require_once $configPath;
        }
    }

    if (_etim_config()['company_vat'] === 'CHANGE_ME') {
        _etim_jsonError('Credentials not configured — copy config.example.php to config.php and fill in your credentials', 403);
    }

    try {
        $ch = etimologio_login();
    } catch (\RuntimeException $e) {
        _etim_jsonError($e->getMessage(), 503);
        exit; // _etim_jsonError already exits, but be explicit for static analysis
    }

    // List products — ?products=1 serves from cache if available (auto-builds on first run)
    // ?products=1&refresh=1 forces full cache rebuild
    if (!empty($_REQUEST['products'])) {
        $forceRefresh = !empty($_REQUEST['refresh']);

        // Serve from cache if available and not forcing refresh
        if (!$forceRefresh && ($cached = _etim_cache_read()) !== null) {
            etimologio_close($ch);
            _etim_jsonResponse($cached);
        }

        // Cache missing or refresh forced — fetch plain list then enrich with VAT + classifications
        $r = etimologio_products($ch, $forceRefresh);

        if ($r['success'] && !empty($r['products'])) {
            // NOTE: makes one API call per product × invoice type — only paid on cache miss/refresh
            _etim_enrich_products($ch, $r['products']);
            _etim_cache_write($r);
        }

        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // Single product with classifications
    if (isset($_REQUEST['product_lookup'])) {
        $r = etimologio_product($ch, trim($_REQUEST['product_lookup']), trim($_REQUEST['inv_type'] ?? '58'));
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // List invoice categories
    if (!empty($_REQUEST['categories'])) {
        $r = etimologio_categories($ch);
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // List customers
    if (!empty($_REQUEST['customers'])) {
        $r = etimologio_customers($ch);
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // List invoices by date range
    if (!empty($_REQUEST['invoices'])) {
        $r = etimologio_invoices(
            $ch,
            trim($_REQUEST['date_from'] ?? ''),
            trim($_REQUEST['date_to']   ?? ''),
            trim($_REQUEST['invoice_type'] ?? ''),  // use distinct param to avoid collision
            trim($_REQUEST['afm']       ?? '')
        );
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // Taxisnet lookup (read-only, no customer saved)
    if (!empty($_REQUEST['taxisnet'])) {
        $taxisAfm = trim($_REQUEST['taxisnet']);
        if (!preg_match('/^\d{9}$/', $taxisAfm)) {
            etimologio_close($ch);
            _etim_jsonError('Invalid AFM — must be 9 digits');
        }
        $info = etimologio_taxisnet($ch, $taxisAfm);
        etimologio_close($ch);
        _etim_jsonResponse($info
            ? ['success' => true, 'afm' => $taxisAfm, 'info' => $info]
            : ['success' => false, 'error' => 'AFM not found in Taxisnet']
        );
    }

    // Invoice lookup by MARK
    if (!empty($_REQUEST['mark_lookup'])) {
        $r = etimologio_invoice($ch, trim($_REQUEST['mark_lookup']));
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // PDF by MARK
    $mark = trim($_REQUEST['mark'] ?? '');
    if ($mark !== '') {
        $result = etimologio_pdf($ch, $mark);
        if (!$result['success']) {
            etimologio_close($ch);
            _etim_jsonError($result['error']);
        }
        if (!empty($_REQUEST['pdf_raw'])) {
            $pdf = base64_decode($result['pdf_base64']);
            etimologio_close($ch);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="invoice-' . $mark . '.pdf"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
        etimologio_close($ch);
        _etim_jsonResponse($result);
    }

    // Shared params for invoice operations
    $afm            = trim($_REQUEST['afm']             ?? '');
    $amount         = (float) ($_REQUEST['amount']      ?? 0);
    $type           = trim($_REQUEST['type']            ?? '58');
    $correlatedMark = trim($_REQUEST['correlated_mark'] ?? '');
    $country        = trim($_REQUEST['country']         ?? 'GR');

    // Get series for a specific invoice type (from cache — no network call)
    if (isset($_REQUEST['series_for_type'])) {
        $invType = trim($_REQUEST['series_for_type']);
        etimologio_close($ch);
        $matches = etimologio_series_for_type($invType);
        _etim_jsonResponse(['success' => true, 'invoice_type' => $invType, 'count' => count($matches), 'series' => $matches]);
    }

    // Customer find/create (no invoice — AFM only, no amount)
    if ($amount === 0.0 && $type !== '61' && $afm !== '') {
        if (!preg_match('/^\d{9}$/', $afm)) {
            etimologio_close($ch);
            _etim_jsonError('Invalid AFM — must be 9 digits for Greek clients');
        }
        $r = etimologio_customer($ch, $afm);
        etimologio_close($ch);
        _etim_jsonResponse($r);
    }

    // Validate AFM format for Greek clients
    if ($afm !== '' && $country === 'GR' && !preg_match('/^\d{9}$/', $afm)) {
        etimologio_close($ch);
        _etim_jsonError('Invalid AFM — must be 9 digits for Greek clients');
    }

    // Credit note — auto-fetch amount and customer from original invoice
    if ($type === '61' && $correlatedMark !== '' && $amount === 0.0) {
        $original = etimologio_invoice($ch, $correlatedMark);
        if (!$original['success']) {
            etimologio_close($ch);
            _etim_jsonResponse($original);
        }
        $amount = (float) str_replace(',', '.', str_replace('.', '', $original['net']));
        if ($afm === '') $afm = $original['counterpart'];
        $result = etimologio_create($ch, [
            'amount'          => $amount,
            'type'            => $type,
            'payment'         => (int)   ($_REQUEST['payment']    ?? 3),
            'description'     => trim(   $_REQUEST['description'] ?? 'ΥΠ001'),
            'language'        => trim(   $_REQUEST['language']    ?? 'el'),
            'live'            => !empty( $_REQUEST['live']),
            'afm'             => $afm,
            'correlated_mark' => $correlatedMark,
            'series'          => trim(   $_REQUEST['series']                ?? ''),
            'notes'           => trim(   $_REQUEST['notes']                 ?? ''),
            'vat_exemption_category' => (int) ($_REQUEST['vat_exemption_category'] ?? 0),
        ]);
        $result['original_invoice'] = $original;
        etimologio_close($ch);
        _etim_jsonResponse($result);
    }

    // Standard invoice creation
    if ($amount > 0) {
        $result = etimologio_create($ch, [
            'amount'               => $amount,
            'type'                 => $type,
            'payment'              => (int)   ($_REQUEST['payment']              ?? 3),
            'description'          => trim(   $_REQUEST['description']           ?? 'ΥΠ001'),
            'language'             => trim(   $_REQUEST['language']              ?? 'el'),
            'live'                 => !empty( $_REQUEST['live']),
            'afm'                  => $afm,
            'name'                 => trim(   $_REQUEST['name']                  ?? ''),
            'address'              => trim(   $_REQUEST['address']               ?? ''),
            'city'                 => trim(   $_REQUEST['city']                  ?? ''),
            'zip'                  => trim(   $_REQUEST['zip']                   ?? ''),
            'country'              => $country,
            'branch'               => trim(   $_REQUEST['branch']                ?? '0'),
            'withholding_category' => (int)   ($_REQUEST['withholding_category'] ?? 0),
            'withholding_amount'   => (float) ($_REQUEST['withholding_amount']   ?? 0.0),
            'correlated_mark'      => $correlatedMark,
            'series'               => trim(   $_REQUEST['series']                ?? ''),
            'notes'                => trim(   $_REQUEST['notes']                 ?? ''),
            'vat_exemption_category' => (int) ($_REQUEST['vat_exemption_category'] ?? 0),
        ]);
        etimologio_close($ch);
        _etim_jsonResponse($result);
    }

    etimologio_close($ch);
    _etim_jsonError('No valid action — provide: amount, mark, afm, taxisnet, products, customers, categories, series_for_type, invoices, or mark_lookup');
}
