<?php
// ============================================================================
// e-Timologio API — ΑΑΔΕ myDATA
// ============================================================================
//
// CUSTOMER LOOKUP (find or auto-create in e-timologio):
//   ?afm=801725430
//
// DRAFT INVOICE (saved to Προσωρινά Αποθηκευμένα, NOT submitted to AADE):
//   ?amount=500&type=58
//
// DRAFT INVOICE + CUSTOMER (auto find/create customer first):
//   ?afm=801725430&amount=500&type=58
//
// FULL EXAMPLE:
//   ?afm=801725430&amount=500&type=58&payment=6&name=ACME SA&city=Athens&zip=10432
//
// ----------------------------------------------------------------------------
// PARAMETERS
// ----------------------------------------------------------------------------
//
// afm         Greek tax number — 9 digits for GR clients (optional for invoices,
//             required for customer lookup). For type 22 (non-EU), pass the
//             foreign VAT string (e.g. afm=FOREIGN) or leave empty.
//             If provided for GR clients, customer is auto found/created.
//
// amount      Net amount in EUR, excluding VAT (required for invoice).
//             VAT 24% is calculated automatically except for type 22 (0% VAT).
//             e.g. amount=500 → net 500€ + VAT 120€ = total 620€
//
// type        Invoice type code (required for invoice):
//               20  → 2.1  Τιμολόγιο Παροχής Υπηρεσιών (B2B, GR)
//               21  → 2.2  Τιμολόγιο Παροχής / Ενδοκοινοτική (B2B, EU)
//               22  → 2.3  Τιμολόγιο Παροχής Υπηρεσιών - Τρίτες Χώρες (0% ΦΠΑ)
//               57  → 11.1 ΑΛΠ (Απόδειξη Λιανικής Πώλησης)
//               58  → 11.2 ΑΠΥ (Απόδειξη Παροχής Υπηρεσιών)
//
// payment     Payment method code (optional, default 3):
//               1   → Επαγγελματικός Λογαριασμός Πληρωμών Ημεδαπής
//               2   → Επαγγελματικός Λογαριασμός Πληρωμών Αλλοδαπής
//               3   → Μετρητά
//               4   → Επιταγή
//               5   → Επί πιστώσει
//               6   → Web Banking
//               7   → POS / e-POS
//               8   → Άμεσες Πληρωμές IRIS
//
// name        Customer name (optional — auto-populated from Taxisnet if GR afm given,
//             or from e-timologio database if foreign afm given)
// address     Customer street address (optional — auto-populated as above)
// city        Customer city (optional — auto-populated as above)
// zip         Customer postal code (optional — auto-populated as above)
// country     Customer country ISO code (optional, default GR)
//             Auto-populated from e-timologio database for foreign clients
// branch      Customer branch number (optional, default 0)
// description Product/service code from your e-timologio catalogue
//             (optional, default ΥΠ001)
//
// withholding_category  Withholding tax category (optional, B2B invoices only):
//               1   → Περ. β' - Τόκοι 15%
//               2   → Περ. γ' - Δικαιώματα 20%
//               3   → Περ. δ' - Αμοιβές Συμβούλων Διοίκησης 20%
//               4   → Περ. δ' - Τεχνικά Έργα 3%
//               7   → Παροχή Υπηρεσιών 8%
//
// withholding_amount    Withheld tax amount in EUR (required if withholding_category set)
//
// mark        MARK number of an already-issued invoice (optional).
//             If provided, all other parameters are ignored and the PDF
//             of that invoice is returned directly in the browser.
//
// live        Set to 1 to actually submit the invoice to AADE and get a MARK.
//             Without this parameter, invoice is saved as draft only (safe for testing).
//             e.g. &live=1
//
// ----------------------------------------------------------------------------
// EXAMPLES
// ----------------------------------------------------------------------------
//
// Anonymous cash receipt:
//   ?amount=500&type=58&payment=3
//
// Web banking receipt with customer name and address:
//   ?amount=500&type=58&payment=6&name=PAPADOPOULOS GEORGIOS&address=ΣΤΑΔΙΟΥ 10&city=ΑΘΗΝΑ&zip=10564
//
// Full receipt with AFM (auto-creates customer, auto-populates name/address):
//   ?afm=801725430&amount=500&type=58&payment=6
//
// Service invoice (τιμολόγιο) to business client:
//   ?afm=801725430&amount=1000&type=20&payment=5&description=ΥΠ002
//
// Service invoice with withholding tax (20% on fees):
//   ?afm=801725430&amount=1000&type=20&payment=5&withholding_category=3&withholding_amount=200
//
// Invoice to non-EU client (0% VAT, auto-populated from e-timologio database):
//   ?amount=1000&type=22&payment=6&afm=FOREIGN
//
// Retrieve PDF of issued invoice by MARK:
//   ?mark=400000000000001
//
// Retrieve PDF as raw binary (browser-friendly):
//   ?mark=400000000000001&pdf_raw=1
//
// LIVE invoice (actually submitted to AADE, MARK assigned):
//   ?afm=801725430&amount=500&type=58&payment=6&live=1
//
// ============================================================================

require __DIR__ . '/config.php';

// --- RESPONSE HELPERS --------------------------------------------------------

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $status = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// --- CURL HELPERS ------------------------------------------------------------

function curlGet(\CurlHandle $ch, string $url): string {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_HTTPGET,        true);
    curl_setopt($ch, CURLOPT_POST,           false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    return curl_exec($ch);
}

function curlPost(\CurlHandle $ch, string $url, array $fields): string {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($fields));
    return curl_exec($ch);
}

function curlPostInvoice(\CurlHandle $ch, string $url, array $data): string {
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

function getToken(\CurlHandle $ch, string $url): string {
    $html = curlGet($ch, $url);
    preg_match('/name="__RequestVerificationToken".*?value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

// --- LOGIN -------------------------------------------------------------------

function login(): \CurlHandle {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR,      COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE,     COOKIE_FILE);
    curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');

    $token = getToken($ch, BASE_URL . '/Account/Login');
    if (!$token) jsonError('Could not reach e-timologio', 503);

    curlPost($ch, BASE_URL . '/Account/Login', [
        'UserName'                   => USERNAME,
        'VatNumber'                  => COMPANY_VAT,
        'SubscriptionKey'            => SUBSCRIPTION_KEY,
        'ReturnUrl'                  => '/timologio',
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (strpos($finalUrl, 'Login') !== false) jsonError('Login failed', 401);

    return $ch;
}

// --- 1. SEARCH CUSTOMER ------------------------------------------------------

function searchCustomer(\CurlHandle $ch, string $afm): ?array {
    $token = getToken($ch, BASE_URL . '/customer/ListCustomers');

    $html = curlPost($ch, BASE_URL . '/customer/SearchCustomers', [
        'Language'                            => 'el-GR',
        'CompanyVat'                          => COMPANY_VAT,
        'CustomerVat'                         => $afm,
        'CustomerCode'                        => '',
        'CustomerName'                        => '',
        'NextPartitionKey'                    => '',
        'NextRowKey'                          => '',
        'continuationToken.continuationToken' => '',
        'totalFechedRows'                     => '10',
        'PrevCustomerCode'                    => '',
        'PrevCustomerVat'                     => '',
        'PrevCustomerName'                    => '',
        'btnSearch'                           => 'btnSearch',
        '__RequestVerificationToken'          => $token,
    ]);

    if (preg_match('/<td[^>]*>\s*' . preg_quote($afm, '/') . '\s*<\/td>/', $html)) {
        preg_match('/<tr>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?<td[^>]*>\s*(\d+)\s*<\/td>.*?' . preg_quote($afm, '/') . '/s', $html, $row);
        return ['code' => $row[2] ?? null, 'vat' => $afm];
    }
    return null;
}

// --- 2. GET FROM TAXISNET ----------------------------------------------------

function getFromTaxisnet(\CurlHandle $ch, string $afm): ?array {
    $response = curlGet($ch, BASE_URL . '/Customer/GetCustomerByTaxis?' . http_build_query([
        'companyVat'  => COMPANY_VAT,
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

// --- 2b. GET CUSTOMER FROM E-TIMOLOGIO DATABASE (for foreign clients) --------

function getCustomerFromDatabase(\CurlHandle $ch, string $term, string $invoiceType): ?array {
    $url = BASE_URL . '/Customer/GetProposedCustomersByName/?' . http_build_query([
        'companyVat' => COMPANY_VAT,
        'invType'    => $invoiceType,
        'term'       => $term,
    ]);
    $response = curlGet($ch, $url);
    $data = json_decode($response, true);
    if (empty($data[0])) return null;

    $c = $data[0];
    return [
        'name'    => $c['n']   ?? '',
        'address' => $c['a']   ?? '',
        'city'    => $c['ct']  ?? '',
        'zip'     => $c['z']   ?? '',
        'country' => $c['cod'] ?? '',
        'vat'     => $c['v']   ?? '',
    ];
}

// --- 3. CREATE CUSTOMER ------------------------------------------------------

function createCustomer(\CurlHandle $ch, string $afm, array $info): bool {
    $token = getToken($ch, BASE_URL . '/customer/NewCustomer');
    if (!$token) return false;

    curlPost($ch, BASE_URL . '/customer/NewCustomer', [
        'CompanyVAT'                 => COMPANY_VAT,
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
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return strpos($finalUrl, 'NewCustomer') === false;
}

// --- 3.5 CREATE PERSONAL CUSTOMER (without AFM) --------------------------------

function createPersonalCustomer(
    \CurlHandle $ch,
    string $name,
    string $address,
    string $city,
    string $zip,
    string $doy = 'ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ',
    string $country = 'GR',
    string $jobDescription = 'ΙΔΙΩΤΗΣ',
    string $email = '',
    string $phone1 = '',
    string $phone2 = '',
    string $language = 'el-GR',
    bool $isB2GCustomer = false,
    string $customerCode = '',
    string $customerVat = '',
    string $oldCustomerVat = ''
): array {
    if ($name === '' || $city === '' || $zip === '') {
        return ['success' => false, 'error' => 'Name, city, and zip are required'];
    }

    $jobDescription = trim($jobDescription);
    if ($jobDescription === '') {
        $jobDescription = 'ΙΔΙΩΤΗΣ';
    }

    $token = getToken($ch, BASE_URL . '/customer/NewCustomer');
    if (!$token) {
        return ['success' => false, 'error' => 'Could not load customer form'];
    }

    // Mirror browser payload for personal customer creation (no VAT required)
    $formData = [
        'CompanyVAT'                 => COMPANY_VAT,
        'Language'                   => $language,
        'OldCustomerVat'             => $oldCustomerVat,
        'CustomerType'               => '1',
        'Country'                    => $country,
        'isB2GCustomer'              => $isB2GCustomer ? 'true' : 'false',
        'CustomerCode'               => $customerCode,
        'CustomerVat'                => $customerVat,
        'CustomerName'               => $name,
        'JobDescription'             => $jobDescription,
        'CustomerAddress'            => $address,
        'CustomerCity'               => $city,
        'CustomerZipCode'            => $zip,
        'Doy'                        => $doy,
        'CustomerEmail'              => $email,
        'CustomerPhone1'             => $phone1,
        'CustomerPhone2'             => $phone2,
        '__RequestVerificationToken' => $token,
    ];

    $response = curlPost($ch, BASE_URL . '/customer/NewCustomer', $formData);
    
    // Check if response is JSON success response
    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded['success'])) {
        if ($decoded['success'] === true || $decoded['success'] === 'true') {
            return ['success' => true, 'message' => 'Personal customer created successfully', 'data' => $decoded];
        }
    }

    // Browser behavior: successful save returns 302 -> /Customer/ViewCustomer?... 
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (strpos($finalUrl, 'ViewCustomer') !== false) {
        return [
            'success'   => true,
            'message'   => 'Personal customer created successfully',
            'final_url' => $finalUrl,
        ];
    }

    if (strpos($finalUrl, 'NewCustomer') === false) {
        return [
            'success'   => true,
            'message'   => 'Personal customer created successfully',
            'final_url' => $finalUrl,
        ];
    }

    return [
        'success' => false,
        'error' => $decoded['error'] ?? 'Failed to create personal customer',
        'raw' => substr((string)$response, 0, 200),
    ];
}

// --- 4. FIND OR CREATE CUSTOMER ----------------------------------------------

function findOrCreateCustomer(\CurlHandle $ch, string $afm): array {
    $existing = searchCustomer($ch, $afm);
    if ($existing) {
        return ['success' => true, 'status' => 'found', 'code' => $existing['code'], 'vat' => $afm];
    }

    $info = getFromTaxisnet($ch, $afm);
    if (!$info) {
        return ['success' => false, 'status' => 'error', 'error' => 'AFM not found in Taxisnet'];
    }

    $created = createCustomer($ch, $afm, $info);
    if (!$created) {
        return ['success' => false, 'status' => 'error', 'error' => 'Failed to create customer'];
    }

    $new = searchCustomer($ch, $afm);
    return ['success' => true, 'status' => 'created', 'code' => $new['code'] ?? null, 'vat' => $afm, 'info' => $info];
}

// --- 4b. CUSTOMER/INVOICE LIST HELPERS --------------------------------------

function htmlText(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text ?? '');
    return trim((string)$text);
}

function htmlInputValue(string $html, string $name): string {
    $qName = preg_quote($name, '/');

    if (preg_match('/<input[^>]*name="' . $qName . '"[^>]*value="([^"]*)"[^>]*>/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<input[^>]*value="([^"]*)"[^>]*name="' . $qName . '"[^>]*>/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
}

function extractTableRows(string $html, string $tableId): array {
    $qId = preg_quote($tableId, '/');
    if (!preg_match('/<table[^>]*id="' . $qId . '"[^>]*>(.*?)<\/table>/is', $html, $tableMatch)) {
        return [];
    }

    $tableHtml = $tableMatch[1];
    
    // First try to extract from tbody (standard case)
    if (preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $tableHtml, $tbodyMatch)) {
        $bodyContent = $tbodyMatch[1];
    } else {
        // Fallback: extract all tr elements directly from table
        $bodyContent = $tableHtml;
    }

    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $bodyContent, $rowMatches);
    $rows = [];
    foreach ($rowMatches[1] as $rowHtml) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches);
        if (!empty($cellMatches[1])) {
            // Skip header rows (those with only 1-2 cells or containing <th> instead of <td>)
            if (count($cellMatches[1]) > 2 && !preg_match('/<th/i', $rowHtml)) {
                $rows[] = [
                    'html'  => $rowHtml,
                    'cells' => $cellMatches[1],
                ];
            }
        }
    }
    return $rows;
}

function toSearchDate(string $value, string $fallback): string {
    $value = trim($value);
    if ($value === '') return $fallback;

    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
        return $value;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
    return $fallback;
}

function listCustomers(
    \CurlHandle $ch,
    string $customerVat = '',
    string $customerCode = '',
    string $customerName = '',
    bool $all = false,
    int $pageSize = 1000,
    int $maxPages = 20
): array {
    $pageSize = max(1, min(1000, $pageSize));
    $maxPages = max(1, min(200, $maxPages));

    $state = [
        'NextPartitionKey'                    => '',
        'NextRowKey'                          => '',
        'continuationToken.continuationToken' => '',
        'PrevCustomerCode'                    => '',
        'PrevCustomerVat'                     => '',
        'PrevCustomerName'                    => '',
    ];

    $token = getToken($ch, BASE_URL . '/customer/ListCustomers');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load customer search form'];
    }

    $seen = [];
    $customers = [];
    $pages = 0;

    while ($pages < $maxPages) {
        $pages++;

        $html = curlPost($ch, BASE_URL . '/customer/SearchCustomers', [
            'Language'                            => 'el-GR',
            'CompanyVat'                          => COMPANY_VAT,
            'CustomerVat'                         => $customerVat,
            'CustomerCode'                        => $customerCode,
            'CustomerName'                        => $customerName,
            'NextPartitionKey'                    => $state['NextPartitionKey'],
            'NextRowKey'                          => $state['NextRowKey'],
            'continuationToken.continuationToken' => $state['continuationToken.continuationToken'],
            'totalFechedRows'                     => (string)$pageSize,
            'PrevCustomerCode'                    => $state['PrevCustomerCode'],
            'PrevCustomerVat'                     => $state['PrevCustomerVat'],
            'PrevCustomerName'                    => $state['PrevCustomerName'],
            'btnSearch'                           => 'btnSearch',
            '__RequestVerificationToken'          => $token,
        ]);

        $rows = extractTableRows($html, 'tblCustomers');
        foreach ($rows as $row) {
            $cols = array_map('htmlText', $row['cells']);
            if (count($cols) < 7) continue;

            $customer = [
                'row_no'  => $cols[0],
                'code'    => $cols[1],
                'type'    => $cols[2],
                'vat'     => $cols[3],
                'name'    => $cols[4],
                'address' => $cols[5],
                'city'    => $cols[6],
            ];

            if (preg_match('/deleteCustomer\(\'([^\']+)\',\s*\'([^\']+)\'\)/', $row['html'], $m)) {
                $customer['company_vat'] = $m[1];
                $customer['delete_code'] = $m[2];
            }

            $key = $customer['code'] . '|' . $customer['vat'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $customers[] = $customer;
            }
        }

        $nextState = [
            'NextPartitionKey'                    => htmlInputValue($html, 'NextPartitionKey'),
            'NextRowKey'                          => htmlInputValue($html, 'NextRowKey'),
            'continuationToken.continuationToken' => htmlInputValue($html, 'continuationToken.continuationToken'),
            'PrevCustomerCode'                    => htmlInputValue($html, 'PrevCustomerCode'),
            'PrevCustomerVat'                     => htmlInputValue($html, 'PrevCustomerVat'),
            'PrevCustomerName'                    => htmlInputValue($html, 'PrevCustomerName'),
        ];

        $hasNext = $nextState['NextPartitionKey'] !== ''
            || $nextState['NextRowKey'] !== ''
            || $nextState['continuationToken.continuationToken'] !== '';

        if (!$all || !$hasNext || $nextState === $state) {
            $state = $nextState;
            break;
        }

        $state = $nextState;
    }

    return [
        'success'       => true,
        'count'         => count($customers),
        'pages_fetched' => $pages,
        'has_next_page' => ($state['NextPartitionKey'] !== ''
            || $state['NextRowKey'] !== ''
            || $state['continuationToken.continuationToken'] !== ''),
        'customers'     => $customers,
    ];
}

function htmlSelectedValue(string $html, string $selectName): string {
    $qName = preg_quote($selectName, '/');
    if (!preg_match('/<select[^>]*name="' . $qName . '"[^>]*>(.*?)<\/select>/is', $html, $m)) {
        return '';
    }
    $block = $m[1];
    if (preg_match('/<option[^>]*selected[^>]*value="([^"]*)"/i', $block, $o)) {
        return html_entity_decode($o[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
}

function findCustomerViewUrl(
    \CurlHandle $ch,
    string $customerVat = '',
    string $customerCode = ''
): array {
    if ($customerVat === '' && $customerCode === '') {
        return ['success' => false, 'error' => 'Customer VAT or customer code is required'];
    }

    $token = getToken($ch, BASE_URL . '/customer/ListCustomers');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load customer search form'];
    }

    $html = curlPost($ch, BASE_URL . '/customer/SearchCustomers', [
        'Language'                            => 'el-GR',
        'CompanyVat'                          => COMPANY_VAT,
        'CustomerVat'                         => $customerVat,
        'CustomerCode'                        => $customerCode,
        'CustomerName'                        => '',
        'NextPartitionKey'                    => '',
        'NextRowKey'                          => '',
        'continuationToken.continuationToken' => '',
        'totalFechedRows'                     => '1000',
        'PrevCustomerCode'                    => '',
        'PrevCustomerVat'                     => '',
        'PrevCustomerName'                    => '',
        'btnSearch'                           => 'btnSearch',
        '__RequestVerificationToken'          => $token,
    ]);

    $rows = extractTableRows($html, 'tblCustomers');
    $matches = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 4) continue;

        $code = trim($cols[1] ?? '');
        $vat  = trim($cols[3] ?? '');
        if ($customerCode !== '' && $code !== $customerCode) continue;
        if ($customerVat !== '' && $vat !== $customerVat) continue;

        if (!preg_match('/href="([^\"]*\/Customer\/viewcustomer\?[^\"]+)"/i', $row['html'], $m)) {
            continue;
        }

        $viewUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($viewUrl, 'http') !== 0) {
            $viewUrl = 'https://mydata.aade.gr' . $viewUrl;
        }

        $matches[] = [
            'customer_code' => $code,
            'customer_vat'  => $vat,
            'view_url'      => $viewUrl,
        ];
    }

    if (count($matches) === 0) {
        return [
            'success' => false,
            'error' => 'Customer not found in search results',
            'target_vat' => $customerVat,
            'target_code' => $customerCode,
        ];
    }

    if (count($matches) > 1) {
        return [
            'success' => false,
            'error' => 'Ambiguous customer selection: multiple exact matches found',
            'target_vat' => $customerVat,
            'target_code' => $customerCode,
            'matches' => array_slice($matches, 0, 10),
            'match_count' => count($matches),
        ];
    }

    return ['success' => true] + $matches[0];
}

function deleteCustomerBySelector(
    \CurlHandle $ch,
    string $customerVat = '',
    string $customerCode = ''
): array {
    $located = findCustomerViewUrl($ch, $customerVat, $customerCode);
    if (empty($located['success'])) {
        return $located;
    }

    $resolvedCode = (string)($located['customer_code'] ?? '');
    $resolvedVat  = (string)($located['customer_vat'] ?? '');
    $viewUrl      = (string)($located['view_url'] ?? '');

    if ($resolvedCode === '' || $viewUrl === '') {
        return ['success' => false, 'error' => 'Could not resolve customer identity for deletion'];
    }

    $viewHtml = curlGet($ch, $viewUrl);
    $viewCode = htmlInputValue($viewHtml, 'customer.CustomerCode');
    $viewVat  = htmlInputValue($viewHtml, 'customer.CustomerVat');

    if ($customerCode !== '' && $viewCode !== $customerCode) {
        return [
            'success' => false,
            'error' => 'Guard check failed: mismatched customer code before delete',
            'expected_code' => $customerCode,
            'found_code' => $viewCode,
        ];
    }
    if ($customerVat !== '' && $viewVat !== $customerVat) {
        return [
            'success' => false,
            'error' => 'Guard check failed: mismatched customer VAT before delete',
            'expected_vat' => $customerVat,
            'found_vat' => $viewVat,
        ];
    }

    $deleted = deleteCustomerByCode($ch, $resolvedCode);
    $deleted['customer_code'] = $resolvedCode;
    $deleted['customer_vat'] = $resolvedVat;
    $deleted['view_code'] = $viewCode;
    $deleted['view_vat'] = $viewVat;
    return $deleted;
}

function updateCustomer(
    \CurlHandle $ch,
    string $customerVat = '',
    string $customerCode = '',
    string $phone1 = '',
    string $phone2 = '',
    string $email = '',
    string $jobDescription = '',
    string $address = '',
    string $city = '',
    string $zip = '',
    string $doy = '',
    string $name = ''
): array {
    $hasChanges = $phone1 !== '' || $phone2 !== '' || $email !== '' || $jobDescription !== ''
        || $address !== '' || $city !== '' || $zip !== '' || $doy !== '' || $name !== '';
    if (!$hasChanges) {
        return ['success' => false, 'error' => 'At least one update field is required'];
    }

    $located = findCustomerViewUrl($ch, $customerVat, $customerCode);
    if (empty($located['success'])) {
        return $located;
    }

    $viewUrl = $located['view_url'];
    $viewBefore = curlGet($ch, $viewUrl);
    $beforeVat = htmlInputValue($viewBefore, 'customer.CustomerVat');
    $beforeCode = htmlInputValue($viewBefore, 'customer.CustomerCode');
    if ($customerVat !== '' && $beforeVat !== $customerVat) {
        return [
            'success' => false,
            'error'   => 'Guard check failed: mismatched customer VAT',
            'expected_vat' => $customerVat,
            'found_vat'    => $beforeVat,
        ];
    }
    if ($customerCode !== '' && $beforeCode !== $customerCode) {
        return [
            'success' => false,
            'error'   => 'Guard check failed: mismatched customer code',
            'expected_code' => $customerCode,
            'found_code'    => $beforeCode,
        ];
    }

    if (!preg_match('/href="([^\"]*\/customer\/editcustomer\?[^\"]+)"/i', $viewBefore, $m)) {
        return ['success' => false, 'error' => 'Could not find customer edit link'];
    }
    $editUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (strpos($editUrl, 'http') !== 0) {
        $editUrl = 'https://mydata.aade.gr' . $editUrl;
    }

    $editHtml = curlGet($ch, $editUrl);
    if (!preg_match('/<form[^>]*id="myform"[^>]*action="([^"]+)"/i', $editHtml, $f)) {
        return ['success' => false, 'error' => 'Could not find customer update form action'];
    }

    $action = html_entity_decode($f[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (strpos($action, 'http') !== 0) {
        $action = 'https://mydata.aade.gr' . $action;
    }

    $payload = [
        'customer.CompanyVAT'       => htmlInputValue($editHtml, 'customer.CompanyVAT'),
        'customer.EncrCustomerCode' => htmlInputValue($editHtml, 'customer.EncrCustomerCode'),
        'customer.Language'         => htmlInputValue($editHtml, 'customer.Language') !== '' ? htmlInputValue($editHtml, 'customer.Language') : 'el-GR',
        'customer.CustomerType'     => htmlSelectedValue($editHtml, 'customer.CustomerType') !== '' ? htmlSelectedValue($editHtml, 'customer.CustomerType') : htmlInputValue($editHtml, 'customer.CustomerType'),
        'customer.Country'          => htmlSelectedValue($editHtml, 'customer.Country') !== '' ? htmlSelectedValue($editHtml, 'customer.Country') : htmlInputValue($editHtml, 'customer.Country'),
        'customer.isB2GCustomer'    => htmlInputValue($editHtml, 'customer.isB2GCustomer') !== '' ? htmlInputValue($editHtml, 'customer.isB2GCustomer') : 'false',
        'customer.CustomerCode'     => htmlInputValue($editHtml, 'customer.CustomerCode'),
        'customer.CustomerVat'      => htmlInputValue($editHtml, 'customer.CustomerVat'),
        'customer.CustomerName'     => htmlInputValue($editHtml, 'customer.CustomerName'),
        'customer.JobDescription'   => htmlInputValue($editHtml, 'customer.JobDescription'),
        'customer.CustomerAddress'  => htmlInputValue($editHtml, 'customer.CustomerAddress'),
        'customer.CustomerCity'     => htmlInputValue($editHtml, 'customer.CustomerCity'),
        'customer.CustomerZipCode'  => htmlInputValue($editHtml, 'customer.CustomerZipCode'),
        'customer.Doy'              => htmlInputValue($editHtml, 'customer.Doy'),
        'customer.CustomerEmail'    => htmlInputValue($editHtml, 'customer.CustomerEmail'),
        'customer.CustomerPhone1'   => htmlInputValue($editHtml, 'customer.CustomerPhone1'),
        'customer.CustomerPhone2'   => htmlInputValue($editHtml, 'customer.CustomerPhone2'),
        '__RequestVerificationToken'=> getToken($ch, $editUrl),
    ];

    if ($phone1 !== '') $payload['customer.CustomerPhone1'] = $phone1;
    if ($phone2 !== '') $payload['customer.CustomerPhone2'] = $phone2;
    if ($email !== '') $payload['customer.CustomerEmail'] = $email;
    if ($jobDescription !== '') $payload['customer.JobDescription'] = $jobDescription;
    if ($address !== '') $payload['customer.CustomerAddress'] = $address;
    if ($city !== '') $payload['customer.CustomerCity'] = $city;
    if ($zip !== '') $payload['customer.CustomerZipCode'] = $zip;
    if ($doy !== '') $payload['customer.Doy'] = $doy;
    if ($name !== '') $payload['customer.CustomerName'] = $name;

    $response = curlPost($ch, $action, $payload);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    $viewAfter = curlGet($ch, $viewUrl);

    $after = [
        'customer_code' => htmlInputValue($viewAfter, 'customer.CustomerCode'),
        'customer_vat'  => htmlInputValue($viewAfter, 'customer.CustomerVat'),
        'name'          => htmlInputValue($viewAfter, 'customer.CustomerName'),
        'phone1'        => htmlInputValue($viewAfter, 'customer.CustomerPhone1'),
        'phone2'        => htmlInputValue($viewAfter, 'customer.CustomerPhone2'),
        'email'         => htmlInputValue($viewAfter, 'customer.CustomerEmail'),
        'address'       => htmlInputValue($viewAfter, 'customer.CustomerAddress'),
        'city'          => htmlInputValue($viewAfter, 'customer.CustomerCity'),
        'zip'           => htmlInputValue($viewAfter, 'customer.CustomerZipCode'),
        'doy'           => htmlInputValue($viewAfter, 'customer.Doy'),
    ];

    $ok = stripos($finalUrl, '/Customer/ViewCustomer') !== false;
    if ($customerVat !== '' && $after['customer_vat'] !== $customerVat) {
        $ok = false;
    }
    if ($customerCode !== '' && $after['customer_code'] !== $customerCode) {
        $ok = false;
    }

    return [
        'success'      => $ok,
        'message'      => $ok ? 'Customer updated successfully' : 'Customer update could not be verified',
        'view_url'     => $viewUrl,
        'edit_url'     => $editUrl,
        'action'       => $action,
        'final_url'    => $finalUrl,
        'target_vat'   => $customerVat,
        'target_code'  => $customerCode,
        'after'        => $after,
        'raw'          => $ok ? null : substr((string)$response, 0, 500),
    ];
}

function deleteCustomerByVat(\CurlHandle $ch, string $customerVat): array {
    if ($customerVat === '') {
        return ['success' => false, 'error' => 'Missing customer VAT'];
    }

    return deleteCustomerBySelector($ch, $customerVat, '');
}

function searchInvoices(
    \CurlHandle $ch,
    string $issueDateFrom = '',
    string $issueDateTo = '',
    string $invoiceType = '',
    string $mark = '',
    string $series = '',
    string $buyerVat = '',
    string $invoiceStatus = '0',
    bool $searchCounterpart = false,
    bool $searchB2G = false
): array {
    $today = date('d/m/Y');
    $fromDefault = date('01/m/Y');
    $issueDateFrom = toSearchDate($issueDateFrom, $fromDefault);
    $issueDateTo   = toSearchDate($issueDateTo, $today);

    $token = getToken($ch, BASE_URL . '/invoice/ListInvoices');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load invoice search form'];
    }

    $html = curlPost($ch, BASE_URL . '/invoice/SearchInvoices', [
        'invoiveFormat'              => '1',
        'Mark'                       => $mark,
        'IssueDateFrom'              => $issueDateFrom,
        'IssueDateTo'                => $issueDateTo,
        'InvoiceType'                => $invoiceType,
        'Series'                     => $series,
        'BuyerVatNumber'             => $buyerVat,
        'searchCancelledInvoices'    => in_array($invoiceStatus, ['0', '1', '2'], true) ? $invoiceStatus : '0',
        'searchB2GInvoices'          => $searchB2G ? 'true' : 'false',
        'searchCounterpart'          => $searchCounterpart ? 'true' : 'false',
        'btnSearch'                  => 'btnSearch',
        '__RequestVerificationToken' => $token,
    ]);

    $rows = extractTableRows($html, 'tblInvoices');
    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 11) continue;

        $markValue = $cols[1] ?? '';
        if (preg_match('/PrintInvoice2PdfNew\?mark=([0-9]+)/', $row['html'], $m)) {
            $markValue = $m[1];
        }

        $items[] = [
            'row_no'      => $cols[0] ?? '',
            'mark'        => $markValue,
            'type'        => $cols[2] ?? '',
            'issue_date'  => ($cols[4] ?? '') !== '' ? ($cols[4] ?? '') : ($cols[3] ?? ''),
            'series'      => $cols[5] ?? '',
            'aa'          => $cols[6] ?? '',
            'buyer_vat'   => $cols[7] ?? '',
            'net_value'   => $cols[8] ?? '',
            'vat_value'   => $cols[9] ?? '',
            'total'       => $cols[10] ?? '',
            'status'      => $invoiceStatus,
            'columns'    => $cols,
        ];
    }

    return [
        'success'         => true,
        'count'           => count($items),
        'issue_date_from' => $issueDateFrom,
        'issue_date_to'   => $issueDateTo,
        'invoice_type'    => $invoiceType,
        'invoice_status'  => $invoiceStatus,
        'invoices'        => $items,
    ];
}

function searchTempInvoices(
    \CurlHandle $ch,
    string $saveDateFrom = '',
    string $saveDateTo = '',
    string $invoiceType = '',
    string $buyerVat = '',
    string $tempInvoiceId = ''
): array {
    $today = date('d/m/Y');
    $fromDefault = date('01/m/Y');
    $saveDateFrom = toSearchDate($saveDateFrom, $fromDefault);
    $saveDateTo   = toSearchDate($saveDateTo, $today);

    $token = getToken($ch, BASE_URL . '/tempinvoice/TempInvoices');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load temp invoice search form'];
    }

    $html = curlPost($ch, BASE_URL . '/tempinvoice/SearchTempInvoices', [
        'InvoiceType'                => $invoiceType,
        'BuyerVatNumber'             => $buyerVat,
        'TempInvoiceId'              => $tempInvoiceId,
        'SaveDateFrom'               => $saveDateFrom,
        'SaveDateTo'                 => $saveDateTo,
        'btnSearch'                  => 'btnSearch',
        '__RequestVerificationToken' => $token,
    ]);

    $rows = extractTableRows($html, 'tblTempInvoices');
    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 6) continue;

        $item = [
            'row_no'    => $cols[0] ?? '',
            'temp_id'   => $cols[1] ?? '',
            'save_date' => $cols[2] ?? '',
            'buyer_vat' => $cols[3] ?? '',
            'type'      => $cols[4] ?? '',
            'series'    => $cols[5] ?? '',
            'columns'   => $cols,
        ];

        if (preg_match('/deleteTempInvoice\(\'([^\']+)\',\s*\'([^\']+)\'\)/', $row['html'], $m)) {
            $item['temp_id'] = $m[1];
            $item['seller_vat'] = $m[2];
        }

        $items[] = $item;
    }

    return [
        'success'        => true,
        'count'          => count($items),
        'save_date_from' => $saveDateFrom,
        'save_date_to'   => $saveDateTo,
        'temp_invoices'  => $items,
    ];
}

function deleteTempInvoiceById(\CurlHandle $ch, string $tempInvoiceId, string $sellerVat = ''): array {
    if ($tempInvoiceId === '') {
        return ['success' => false, 'error' => 'Missing temp invoice id'];
    }

    $response = curlPost($ch, BASE_URL . '/TempInvoice/DeleteTempInvoice', [
        'TempInvoiceId' => $tempInvoiceId,
        'SellerVAT'     => $sellerVat !== '' ? $sellerVat : COMPANY_VAT,
    ]);

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function deleteCustomerByCode(\CurlHandle $ch, string $customerCode): array {
    if ($customerCode === '') {
        return ['success' => false, 'error' => 'Missing customer code'];
    }

    $response = curlPost($ch, BASE_URL . '/Customer/DeleteCustomer', [
        'CustomerCode' => $customerCode,
        'companyVat'   => COMPANY_VAT,
    ]);

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function listSeries(\CurlHandle $ch): array {
    $html = curlGet($ch, BASE_URL . '/series/ListSeries');
    $rows = extractTableRows($html, 'tblSeries');

    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 6) continue;

        $item = [
            'row_no'       => $cols[0] ?? '',
            'invoice_type' => $cols[1] ?? '',
            'series_id'    => $cols[2] ?? '',
            'series_code'  => $cols[3] ?? '',
            'start_aa'     => $cols[4] ?? '',
            'description'  => $cols[5] ?? '',
        ];

        if (preg_match('/data-bound-id="([^"]+)"/', $row['html'], $m)) {
            $item['invoice_type_code'] = $m[1];
        }

        if (preg_match('/deleteSeries\(\'([^\']+)\',\s*\'([^\']+)\'\)/', $row['html'], $m)) {
            $item['company_vat'] = $m[1];
            $item['delete_id']   = $m[2];
        }

        $items[] = $item;
    }

    return [
        'success' => true,
        'count'   => count($items),
        'series'  => $items,
    ];
}

function createSeries(
    \CurlHandle $ch,
    string $invoiceType,
    string $seriesCode,
    string $startAa = '1',
    string $description = '',
    bool $isTransFailure = false,
    string $language = 'el-GR'
): array {
    if ($invoiceType === '' || $seriesCode === '') {
        return ['success' => false, 'error' => 'Invoice type and series code are required'];
    }

    $token = getToken($ch, BASE_URL . '/series/NewSeries');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load series form'];
    }

    $response = curlPost($ch, BASE_URL . '/series/NewSeries', [
        'companyVAT'                 => COMPANY_VAT,
        'Language'                   => $language,
        '_invoiceType'               => $invoiceType,
        'code'                       => $seriesCode,
        'aa'                         => $startAa,
        'description'                => $description,
        'isTransFailure'             => $isTransFailure ? 'true' : 'false',
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (stripos($finalUrl, '/series/listseries') !== false) {
        return [
            'success'      => true,
            'message'      => 'Series created successfully',
            'series_code'  => $seriesCode,
            'invoice_type' => $invoiceType,
        ];
    }

    return [
        'success' => false,
        'error'   => 'Failed to create series',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function updateSeries(
    \CurlHandle $ch,
    string $seriesId,
    string $invoiceType = '',
    string $seriesCode = '',
    string $startAa = '',
    string $description = '',
    string $language = 'el-GR'
): array {
    if ($seriesId === '') {
        return ['success' => false, 'error' => 'Missing series id'];
    }

    $seriesData = listSeries($ch);
    if (empty($seriesData['series']) || !is_array($seriesData['series'])) {
        return ['success' => false, 'error' => 'Could not load series list'];
    }

    $current = null;
    foreach ($seriesData['series'] as $row) {
        if (($row['series_id'] ?? '') === $seriesId) {
            $current = $row;
            break;
        }
    }

    if (!$current) {
        return ['success' => false, 'error' => 'Series id not found'];
    }

    $invoiceType = $invoiceType !== '' ? $invoiceType : (string)($current['invoice_type_code'] ?? '');
    $seriesCode  = $seriesCode  !== '' ? $seriesCode  : (string)($current['series_code'] ?? '');
    $startAa     = $startAa     !== '' ? $startAa     : (string)($current['start_aa'] ?? '1');
    $description = $description !== '' ? $description : (string)($current['description'] ?? '');

    if ($invoiceType === '' || $seriesCode === '') {
        return ['success' => false, 'error' => 'Could not resolve required series fields for update'];
    }

    $token = getToken($ch, BASE_URL . '/series/ListSeries');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load series update token'];
    }

    $response = curlPost($ch, BASE_URL . '/series/updateseries', [
        'series.companyVAT'          => COMPANY_VAT,
        'series.id'                  => $seriesId,
        'series.Language'            => $language,
        'series._invoiceType'        => $invoiceType,
        'series.code'                => $seriesCode,
        'series.aa'                  => $startAa,
        'series.description'         => $description,
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (stripos($finalUrl, '/series/listseries') !== false) {
        return [
            'success'      => true,
            'message'      => 'Series updated successfully',
            'series_id'    => $seriesId,
            'series_code'  => $seriesCode,
            'invoice_type' => $invoiceType,
        ];
    }

    return [
        'success' => false,
        'error'   => 'Failed to update series',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function deleteSeriesById(\CurlHandle $ch, string $id): array {
    if ($id === '') {
        return ['success' => false, 'error' => 'Missing series id'];
    }

    $response = curlPost($ch, BASE_URL . '/Series/DeleteSeries', ['id' => $id]);
    $decoded = json_decode($response, true);

    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function listDeductions(\CurlHandle $ch): array {
    $html = curlGet($ch, BASE_URL . '/deduction/ListDeductions');
    $rows = extractTableRows($html, 'tblDeductions');

    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 5) continue;

        $item = [
            'row_no'               => $cols[0] ?? '',
            'description'          => $cols[1] ?? '',
            'percentage_or_value'  => $cols[2] ?? '',
            'value'                => $cols[3] ?? '',
            'decrease_total_paid'  => $cols[4] ?? '',
        ];

        if (preg_match('/deleteDeduction\(\'([^\']+)\'\)/', $row['html'], $m)) {
            $item['deduction_code'] = $m[1];
        }

        $items[] = $item;
    }

    return [
        'success'    => true,
        'count'      => count($items),
        'deductions' => $items,
    ];
}

function deleteDeductionByCode(\CurlHandle $ch, string $deductionCode): array {
    if ($deductionCode === '') {
        return ['success' => false, 'error' => 'Missing deduction code'];
    }

    $response = curlPost($ch, BASE_URL . '/Deduction/DeleteDeduction', [
        'DeductionCode' => $deductionCode,
    ]);
    $decoded = json_decode($response, true);

    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function createDeduction(
    \CurlHandle $ch,
    string $description,
    string $amountType,
    string $amount,
    string $decreaseTotalPaid,
    string $language = 'el-GR'
): array {
    if ($description === '' || $amountType === '' || $amount === '' || $decreaseTotalPaid === '') {
        return ['success' => false, 'error' => 'Description, amount type, amount, and decrease_total_paid are required'];
    }

    $token = getToken($ch, BASE_URL . '/deduction/NewDeduction');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load deduction form'];
    }

    $response = curlPost($ch, BASE_URL . '/deduction/NewDeduction', [
        'CompanyVAT'                 => COMPANY_VAT,
        'Language'                   => $language,
        'DeductionCode'              => '',
        'DeductionDescription'       => $description,
        'DeductionAmountType'        => $amountType,
        'DeductionAmount'            => $amount,
        'DecreaseTotalPaid'          => $decreaseTotalPaid,
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (stripos($finalUrl, '/deduction/listdeductions') !== false) {
        return [
            'success'     => true,
            'message'     => 'Deduction created successfully',
            'description' => $description,
        ];
    }

    return [
        'success' => false,
        'error'   => 'Failed to create deduction',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function updateDeduction(
    \CurlHandle $ch,
    string $deductionCode,
    string $description,
    string $amountType,
    string $amount,
    string $decreaseTotalPaid,
    string $language = 'el-GR'
): array {
    if ($deductionCode === '' || $description === '' || $amountType === '' || $amount === '' || $decreaseTotalPaid === '') {
        return ['success' => false, 'error' => 'Deduction code, description, amount type, amount, and decrease_total_paid are required'];
    }

    $token = getToken($ch, BASE_URL . '/deduction/NewDeduction');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load deduction form'];
    }

    $response = curlPost($ch, BASE_URL . '/deduction/NewDeduction', [
        'CompanyVAT'                 => COMPANY_VAT,
        'Language'                   => $language,
        'DeductionCode'              => $deductionCode,
        'DeductionDescription'       => $description,
        'DeductionAmountType'        => $amountType,
        'DeductionAmount'            => $amount,
        'DecreaseTotalPaid'          => $decreaseTotalPaid,
        '__RequestVerificationToken' => $token,
    ]);

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (stripos($finalUrl, '/deduction/listdeductions') !== false) {
        return [
            'success'        => true,
            'message'        => 'Deduction updated successfully',
            'deduction_code' => $deductionCode,
        ];
    }

    return [
        'success' => false,
        'error'   => 'Failed to update deduction',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function listProducts(\CurlHandle $ch): array {
    $html = curlGet($ch, BASE_URL . '/product/products');
    $rows = extractTableRows($html, 'tblProducts');

    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 10) continue;

        $item = [
            'row_no'           => $cols[0] ?? '',
            'type'             => $cols[3] ?? '',
            'category_id'      => $cols[4] ?? '',
            'category'         => $cols[5] ?? '',
            'product_code'     => $cols[6] ?? '',
            'description'      => $cols[8] ?? '',
            'unit_price'       => $cols[9] ?? '',
            'vat'              => $cols[10] ?? '',
            'measurement_unit' => $cols[11] ?? '',
        ];

        if (preg_match('/btnDeleteProduct[^>]*data-id="([^"]+)"/', $row['html'], $m)) {
            $item['delete_code'] = $m[1];
        }

        $items[] = $item;
    }

    return [
        'success'  => true,
        'count'    => count($items),
        'products' => $items,
    ];
}

function deleteProductByCode(\CurlHandle $ch, string $productCode): array {
    if ($productCode === '') {
        return ['success' => false, 'error' => 'Missing product code'];
    }

    $response = curlPost($ch, BASE_URL . '/Product/Delete', [
        'PrdCode' => $productCode,
    ]);
    $decoded = json_decode($response, true);

    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function listProductCategories(\CurlHandle $ch): array {
    $html = curlGet($ch, BASE_URL . '/product/productCategories');
    $rows = extractTableRows($html, 'tblPrdCategories');

    $items = [];
    foreach ($rows as $row) {
        $cols = array_map('htmlText', $row['cells']);
        if (count($cols) < 4) continue;

        $item = [
            'row_no'      => $cols[0] ?? '',
            'category_id' => $cols[1] ?? '',
            'company_vat' => $cols[2] ?? '',
            'name'        => $cols[3] ?? '',
        ];

        if (preg_match('/btnDeletePrdCategory[^>]*data-id="([^"]+)"/', $row['html'], $m)) {
            $item['delete_id'] = $m[1];
        }

        $items[] = $item;
    }

    return [
        'success'            => true,
        'count'              => count($items),
        'product_categories' => $items,
    ];
}

function deleteProductCategoryById(\CurlHandle $ch, string $id): array {
    if ($id === '') {
        return ['success' => false, 'error' => 'Missing product category id'];
    }

    $response = curlPost($ch, BASE_URL . '/Product/DeleteCategory', ['id' => $id]);
    $decoded = json_decode($response, true);

    if (is_array($decoded)) {
        return ['success' => true, 'result' => $decoded];
    }

    return [
        'success' => true,
        'note'    => 'Delete request sent',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

function getCompanyProfile(\CurlHandle $ch): array {
    $html = curlGet($ch, BASE_URL . '/company/company');

    $profile = [
        'name'                    => htmlInputValue($html, 'company.Name'),
        'job_description'         => htmlInputValue($html, 'company.JobDescription'),
        'address'                 => htmlInputValue($html, 'company.Address'),
        'phone'                   => htmlInputValue($html, 'company.Phone'),
        'doy'                     => htmlInputValue($html, 'company.Doy'),
        'language'                => htmlInputValue($html, 'company.Language'),
        'logo_name'               => htmlInputValue($html, 'company.LogoName'),
        'send_email_on_issuing'   => htmlInputValue($html, 'company.SendEmailOnIssuing') === 'true',
        'digital_client'          => htmlInputValue($html, 'company.DigitalClient') === 'true',
        'websrv_taxis_username'   => htmlInputValue($html, 'company.WebSrvTaxisUserName'),
        'websrv_taxis_password'   => htmlInputValue($html, 'company.WebSrvTaxisPassoword'),
        'has_accepted_terms'      => htmlInputValue($html, 'company.HasAcceptedTerms') === 'true',
    ];

    return [
        'success' => true,
        'company' => $profile,
    ];
}

function getCompanyFromTaxis(\CurlHandle $ch): array {
    $response = curlGet($ch, BASE_URL . '/Company/GetCompanyByTaxis?' . http_build_query([
        'companyVat' => COMPANY_VAT,
    ]));

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        return [
            'success' => true,
            'company' => $decoded,
        ];
    }

    return [
        'success' => false,
        'error'   => 'Could not decode company response',
        'raw'     => substr((string)$response, 0, 500),
    ];
}

// --- PRODUCT CRUD -------------------------------------------------------

function createProduct(
    \CurlHandle $ch,
    string $productType,
    string $productCode,
    string $productDescription,
    string $productCategory = '',
    string $taricCode = '',
    string $unitPrice = '0',
    string $vatCategory = '1',
    string $unit = '',
    string $specialType = '',
    string $feesWithVAT = '',
    string $otherTaxesWithVAT = ''
): array {
    if ($productCode === '' || $productType === '' || $productDescription === '') {
        return ['success' => false, 'error' => 'Product code, type, and description are required'];
    }

    $token = getToken($ch, BASE_URL . '/product/products');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load product form'];
    }

    $formData = [
        'productType'           => $productType,
        'productCode'           => $productCode,
        'productCategory'       => $productCategory,
        'taricCode'             => $taricCode,
        'productDescription'    => $productDescription,
        'unitPrice'             => $unitPrice,
        'vatCategory'           => $vatCategory,
        'unit'                  => $unit,
        'specialType'           => $specialType,
        'feesWithVAT'           => $feesWithVAT,
        'otherTaxesWithVAT'     => $otherTaxesWithVAT,
        '__RequestVerificationToken' => $token,
    ];

    $response = curlPost($ch, BASE_URL . '/product/create', $formData);
    $decoded = json_decode($response, true);
    
    // Server returns JSON with success=true when product is created
    if (is_array($decoded) && ($decoded['success'] === true || $decoded['success'] === 'true')) {
        return ['success' => true, 'message' => 'Product created successfully', 'code' => $productCode];
    }

    return [
        'success' => false,
        'error'   => $decoded['message'] ?? 'Failed to create product',
        'raw'     => $decoded,
    ];
}

function updateProduct(
    \CurlHandle $ch,
    string $productCode,
    string $productType,
    string $productDescription,
    string $productCategory = '',
    string $taricCode = '',
    string $unitPrice = '0',
    string $vatCategory = '1',
    string $unit = '',
    string $specialType = '',
    string $feesWithVAT = '',
    string $otherTaxesWithVAT = ''
): array {
    if ($productCode === '' || $productType === '' || $productDescription === '') {
        return ['success' => false, 'error' => 'Product code, type, and description are required'];
    }

    $token = getToken($ch, BASE_URL . '/product/products');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load product form'];
    }

    $formData = [
        'productCode'           => $productCode,
        'productType'           => $productType,
        'productCategory'       => $productCategory,
        'taricCode'             => $taricCode,
        'productDescription'    => $productDescription,
        'unitPrice'             => $unitPrice,
        'vatCategory'           => $vatCategory,
        'unit'                  => $unit,
        'specialType'           => $specialType,
        'feesWithVAT'           => $feesWithVAT,
        'otherTaxesWithVAT'     => $otherTaxesWithVAT,
        '__RequestVerificationToken' => $token,
    ];

    $response = curlPost($ch, BASE_URL . '/product/create', $formData);
    $decoded = json_decode($response, true);
    
    // Server returns JSON with success=true when product is updated
    if (is_array($decoded) && ($decoded['success'] === true || $decoded['success'] === 'true')) {
        return ['success' => true, 'message' => 'Product updated successfully', 'code' => $productCode];
    }

    return [
        'success' => false,
        'error'   => $decoded['message'] ?? 'Failed to update product',
        'raw'     => $decoded,
    ];
}

// --- PRODUCT CATEGORY CRUD -------------------------------------------------------

function createProductCategory(
    \CurlHandle $ch,
    string $categoryName
): array {
    if ($categoryName === '') {
        return ['success' => false, 'error' => 'Category name is required'];
    }

    $token = getToken($ch, BASE_URL . '/product/productCategories');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load category form'];
    }

    $formData = [
        'prdCategoryName'       => $categoryName,
        '__RequestVerificationToken' => $token,
    ];

    $response = curlPost($ch, BASE_URL . '/product/createCategory', $formData);
    $decoded = json_decode($response, true);
    
    // Server returns JSON with success=true when category is created
    if (is_array($decoded) && ($decoded['success'] === true || $decoded['success'] === 'true')) {
        return ['success' => true, 'message' => 'Category created successfully', 'name' => $categoryName];
    }

    return [
        'success' => false,
        'error'   => $decoded['message'] ?? 'Failed to create category',
        'raw'     => $decoded,
    ];
}

function updateProductCategory(
    \CurlHandle $ch,
    string $categoryId,
    string $categoryName
): array {
    if ($categoryId === '' || $categoryName === '') {
        return ['success' => false, 'error' => 'Category id and name are required'];
    }

    $token = getToken($ch, BASE_URL . '/product/productCategories');
    if ($token === '') {
        return ['success' => false, 'error' => 'Could not load category form'];
    }

    $formData = [
        'prdCategoryId'         => $categoryId,
        'prdCategoryName'       => $categoryName,
        '__RequestVerificationToken' => $token,
    ];

    $response = curlPost($ch, BASE_URL . '/product/createCategory', $formData);
    $decoded = json_decode($response, true);
    
    // Server returns JSON with success=true when category is updated
    if (is_array($decoded) && ($decoded['success'] === true || $decoded['success'] === 'true')) {
        return ['success' => true, 'message' => 'Category updated successfully', 'id' => $categoryId];
    }

    return [
        'success' => false,
        'error'   => $decoded['message'] ?? 'Failed to update category',
        'raw'     => $decoded,
    ];
}

// --- 5. GET PRODUCT DATA (classifications, description) ----------------------

function getProductData(\CurlHandle $ch, string $productCode, string $invoiceType): ?array {
    $url = BASE_URL . '/Product/GetProduct?' . http_build_query([
        'sCompanyVat' => COMPANY_VAT,
        'productCode' => $productCode,
        'invoiceType' => $invoiceType,
        'selfPrice'   => 'false',
    ]);
    $response = curlGet($ch, $url);
    return json_decode($response, true) ?: null;
}

// --- 6. CREATE INVOICE (draft by default, live if $live=true) ----------------

function createInvoice(
    \CurlHandle $ch,
    float $amount,
    string $invoiceType = '58',
    int $paymentType = 3,
    string $description = 'ΥΠ001',
    string $issueDate = '',
    string $afm = '',
    string $name = '',
    string $address = '',
    string $city = '',
    string $zip = '',
    string $country = 'GR',
    string $branch = '0',
    int $withholdingCategory = 0,
    float $withholdingAmount = 0.0,
    bool $live = false
): array {

    if ($issueDate === '') {
        $issueDate = $live ? date('Y-m-d') : date('d-m-Y');
    }

    // Zero VAT for non-EU invoice types
    $isZeroVat = in_array($invoiceType, ZERO_VAT_TYPES);
    $vatRate   = $isZeroVat ? 0.0 : 0.24;
    $netValue  = round($amount, 2);
    $vatAmount = round($netValue * $vatRate, 2);
    $total     = round($netValue + $vatAmount, 2);

    // Enrich counterpart — Taxisnet for GR clients, e-timologio database for foreign
    if ($afm !== '') {
        if (preg_match('/^\d{9}$/', $afm)) {
            // Greek client — fetch from Taxisnet
            $taxisData = getFromTaxisnet($ch, $afm);
            if ($taxisData) {
                if ($name    === '') $name    = $taxisData['name'];
                if ($address === '') $address = $taxisData['address'];
                if ($city    === '') $city    = $taxisData['city'];
                if ($zip     === '') $zip     = $taxisData['zip'];
            }
        } else {
            // Foreign client — fetch from e-timologio customer database
            $dbData = getCustomerFromDatabase($ch, $afm, $invoiceType);
            if ($dbData) {
                if ($name    === '') $name    = $dbData['name'];
                if ($address === '') $address = $dbData['address'];
                if ($city    === '') $city    = $dbData['city'];
                if ($zip     === '') $zip     = $dbData['zip'];
                if ($country === 'GR') $country = $dbData['country'];
            }
        }
    }

    // Fetch product data to get correct description and classifications per invoice type
    $product   = getProductData($ch, $description, $invoiceType);
    $itemDescr = isset($product['d']) ? $description . ' - ' . $product['d'] : $description;

    // Build classifications from product definition (mirrors what JS does)
    $classifications = [];
    if (!empty($product['cl'])) {
        foreach ($product['cl'] as $cl) {
            $classifications[] = [
                'clsCategory' => $cl['cc'],
                'clsCode'     => $cl['tc'],
            ];
        }
    } else {
        $classifications[] = [
            'clsCategory' => 'category1_3',
            'clsCode'     => $isZeroVat ? 'E3_561_006' : 'E3_561_003',
        ];
    }

    // Build withholding tax array if applicable
    $invoiceTaxes = [];
    if ($withholdingCategory > 0 && $withholdingAmount > 0) {
        $invoiceTaxes[] = [
            'id'              => 1,
            'taxType'         => 1,
            'taxCategory'     => $withholdingCategory,
            'underlyingValue' => $netValue,
            'taxAmount'       => (string)round($withholdingAmount, 2),
            'taxNotes'        => '',
        ];
    }

    $invoice = [
        '_invoiceType'              => $invoiceType,
        'CorrelatedInvoice'         => '',
        'selfPricing'               => 'false',
        'paymentType'               => (string)$paymentType,
        'invoiceFormat'             => 1,
        'DispatchTime'              => '',
        'isDeliveryNote'            => 'false',
        'trans'                     => 'false',
        'isB2G'                     => 'false',
        'tempInvoiceId'             => '',
        'invoiceNotes'              => '',
        'transmissionFailure'       => '',
        'ccr_totalNetValueWithDisc' => '',
        'ccr_grossValue'            => '',

        'invoiceHeader' => [
            'series'                     => 'A',  // Series A assumed — change if your setup differs
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
            'address'           => [
                'street'     => $address,
                'postalCode' => $zip,
                'city'       => $city,
                'number'     => '0',
            ],
        ],

        'invoiceTaxes' => $invoiceTaxes,

        'invoiceLines' => [
            [
                'lineNumber'                   => 1,
                'itemId'                       => 1,
                'itemCode'                     => $description,
                'itemDescr'                    => $itemDescr,
                'unitPrice'                    => $netValue,
                'vatCategory'                  => $isZeroVat ? 7 : 1,
                'vatExemptionCategory'         => $isZeroVat ? 4 : '',
                'netValueWithoutDiscount'      => $netValue,
                'discountValue'                => 0,
                'netValueWithDiscount'         => $netValue,
                'vatAmount'                    => $vatAmount,
                'totalValue'                   => $total,
                'discountAmount'               => 0,
                'discountType'                 => 1,
                'isGiftVoucher'                => 'false',
                'otherMeasurementUnitTitle'    => '',
                'otherMeasurementUnitQuantity' => '',
                'classifications'              => [
                    [
                        'classificationKind'     => 1,
                        'classificationCategory' => $classifications[0]['clsCategory'],
                        'classificationType'     => $classifications[0]['clsCode'],
                        'amount'                 => $netValue,
                    ],
                ],
            ],
        ],
    ];

    curlGet($ch, BASE_URL . '/invoice/newinvoice');

    if ($live) {
        // LIVE — submit to AADE, get MARK
        $response = curlPostInvoice($ch, BASE_URL . '/Invoice/create', $invoice);
        $data     = json_decode($response, true);

        if (!$data) {
            return [
                'success' => false,
                'error'   => 'Invalid JSON response',
                'raw'     => substr($response, 0, 500),
            ];
        }

        if (isset($data['mark'])) {
            return [
                'success'      => true,
                'live'         => true,
                'mark'         => $data['mark'],
                'aa'           => $data['aa']    ?? '',
                'qrUrl'        => $data['qrUrl'] ?? '',
                'type'         => $invoiceType,
                'amount_net'   => $netValue,
                'amount_vat'   => $vatAmount,
                'amount_total' => $total,
            ];
        }

        return [
            'success' => false,
            'error'   => $data['genericMsg'] ?? $data['message'] ?? 'Unknown error',
            'raw'     => $data,
        ];

    } else {
        // DRAFT — safe for testing, nothing submitted to AADE
        $response = curlPostInvoice($ch, BASE_URL . '/TempInvoice/savetempinvoice', $invoice);
        $data     = json_decode($response, true);

        if (!$data) {
            return [
                'success' => false,
                'error'   => 'Invalid JSON response',
                'raw'     => substr($response, 0, 500),
            ];
        }

        if (isset($data['resultData'][0])) {
            return [
                'success'      => true,
                'live'         => false,
                'temp_id'      => $data['resultData'][0],
                'type'         => $invoiceType,
                'amount_net'   => $netValue,
                'amount_vat'   => $vatAmount,
                'amount_total' => $total,
                'note'         => 'DRAFT only - not submitted to AADE, no MARK assigned',
            ];
        }

        return [
            'success' => false,
            'error'   => $data['message'] ?? 'Unknown error',
            'raw'     => $data,
        ];
    }
}

// --- 7. GET INVOICE PDF BY MARK ----------------------------------------------

function getInvoicePdf(\CurlHandle $ch, string $mark): void {
    $url      = BASE_URL . '/Invoice/PrintInvoice2PdfNew?' . http_build_query(['mark' => $mark]);
    $response = curlGet($ch, $url);

    if (!$response || substr($response, 0, 4) !== '%PDF') {
        jsonError('PDF not found or invalid MARK');
    }

    // ?pdf_raw=1 → stream binary directly (browser/download use)
    // default    → return base64 JSON (API use)
    if (isset($_GET['pdf_raw'])) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="invoice-' . $mark . '.pdf"');
        header('Content-Length: ' . strlen($response));
        echo $response;
        exit;
    }

    jsonResponse([
        'success'    => true,
        'mark'       => $mark,
        'filename'   => 'invoice-' . $mark . '.pdf',
        'mime'       => 'application/pdf',
        'size'       => strlen($response),
        'pdf_base64' => base64_encode($response),
    ]);
}

// --- API ENTRY POINT ---------------------------------------------------------

$mark                = trim($_GET['mark']                  ?? $_POST['mark']                  ?? '');
$afm                 = trim($_GET['afm']                   ?? $_POST['afm']                   ?? '');
$amount              = (float)($_GET['amount']             ?? $_POST['amount']                ?? 0);
$type                = trim($_GET['type']                  ?? $_POST['type']                  ?? '58');
$payment             = (int)($_GET['payment']              ?? $_POST['payment']               ?? 3);
$descr               = trim($_GET['description']           ?? $_POST['description']           ?? 'ΥΠ001');
$name                = trim($_GET['name']                  ?? $_POST['name']                  ?? '');
$address             = trim($_GET['address']               ?? $_POST['address']               ?? '');
$city                = trim($_GET['city']                  ?? $_POST['city']                  ?? '');
$zip                 = trim($_GET['zip']                   ?? $_POST['zip']                   ?? '');
$country             = trim($_GET['country']               ?? $_POST['country']               ?? 'GR');
$branch              = trim($_GET['branch']                ?? $_POST['branch']                ?? '0');
$withholdingCategory = (int)($_GET['withholding_category'] ?? $_POST['withholding_category']  ?? 0);
$withholdingAmount   = (float)($_GET['withholding_amount'] ?? $_POST['withholding_amount']    ?? 0);
$live                = !empty(($_GET['live']               ?? $_POST['live']                  ?? ''));

$listCustomers       = !empty(($_GET['list_customers']     ?? $_POST['list_customers']        ?? ''));
$allCustomers        = !empty(($_GET['all_customers']      ?? $_POST['all_customers']         ?? ''));
$customerCodeFilter  = trim($_GET['customer_code']         ?? $_POST['customer_code']         ?? '');
$customerNameFilter  = trim($_GET['customer_name']         ?? $_POST['customer_name']         ?? '');
$customersPageSize   = (int)($_GET['customers_page_size']  ?? $_POST['customers_page_size']   ?? 1000);
$customersMaxPages   = (int)($_GET['customers_max_pages']  ?? $_POST['customers_max_pages']   ?? 20);

$createPersonalCust  = !empty(($_GET['create_personal_customer'] ?? $_POST['create_personal_customer'] ?? ''));
$custName            = trim($_GET['cust_name']             ?? $_POST['cust_name']             ?? '');
$custAddress         = trim($_GET['cust_address']          ?? $_POST['cust_address']          ?? '');
$custCity            = trim($_GET['cust_city']             ?? $_POST['cust_city']             ?? '');
$custZip             = trim($_GET['cust_zip']              ?? $_POST['cust_zip']              ?? '');
$custDoy             = trim($_GET['cust_doy']              ?? $_POST['cust_doy']              ?? 'ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ');
$custCountry         = trim($_GET['cust_country']          ?? $_POST['cust_country']          ?? 'GR');
$custJobDescription  = trim($_GET['cust_job_description']  ?? $_POST['cust_job_description']  ?? 'ΙΔΙΩΤΗΣ');
$custEmail           = trim($_GET['cust_email']            ?? $_POST['cust_email']            ?? '');
$custPhone1          = trim($_GET['cust_phone1']           ?? $_POST['cust_phone1']           ?? '');
$custPhone2          = trim($_GET['cust_phone2']           ?? $_POST['cust_phone2']           ?? '');
$custLanguage        = trim($_GET['cust_language']         ?? $_POST['cust_language']         ?? 'el-GR');
$custIsB2G           = !empty(($_GET['cust_is_b2g']        ?? $_POST['cust_is_b2g']           ?? ''));
$custCode            = trim($_GET['cust_code']             ?? $_POST['cust_code']             ?? '');
$custVat             = trim($_GET['cust_vat']              ?? $_POST['cust_vat']              ?? '');
$custOldVat          = trim($_GET['cust_old_vat']          ?? $_POST['cust_old_vat']          ?? '');

$searchInvoicesFlag  = !empty(($_GET['search_invoices']    ?? $_POST['search_invoices']       ?? ''));
$issueDateFrom       = trim($_GET['issue_date_from']       ?? $_POST['issue_date_from']       ?? '');
$issueDateTo         = trim($_GET['issue_date_to']         ?? $_POST['issue_date_to']         ?? '');
$searchInvoiceType   = trim($_GET['search_invoice_type']   ?? $_POST['search_invoice_type']   ?? $_GET['invoice_type'] ?? $_POST['invoice_type'] ?? $_GET['type'] ?? $_POST['type'] ?? '');
$seriesFilter        = trim($_GET['series']                ?? $_POST['series']                ?? '');
$buyerVatFilter      = trim($_GET['buyer_vat']             ?? $_POST['buyer_vat']             ?? '');
$includeCancelled    = !empty(($_GET['include_cancelled']  ?? $_POST['include_cancelled']     ?? ''));
$invoiceStatusFilter = trim($_GET['invoice_status']        ?? $_POST['invoice_status']        ?? '');
$searchCounterpart   = !empty(($_GET['search_counterpart'] ?? $_POST['search_counterpart']    ?? ''));
$searchB2G           = !empty(($_GET['search_b2g']         ?? $_POST['search_b2g']            ?? ''));

if ($invoiceStatusFilter === '') {
    $invoiceStatusFilter = $includeCancelled ? '1' : '0';
}

$searchTempFlag      = !empty(($_GET['search_temp']        ?? $_POST['search_temp']           ?? ''));
$saveDateFrom        = trim($_GET['save_date_from']        ?? $_POST['save_date_from']        ?? '');
$saveDateTo          = trim($_GET['save_date_to']          ?? $_POST['save_date_to']          ?? '');
$tempInvoiceIdFilter = trim($_GET['temp_id']               ?? $_POST['temp_id']               ?? '');

$deleteTempId        = trim($_GET['delete_temp_id']        ?? $_POST['delete_temp_id']        ?? '');
$sellerVat           = trim($_GET['seller_vat']            ?? $_POST['seller_vat']            ?? '');
$deleteCustomerCode  = trim($_GET['delete_customer_code']  ?? $_POST['delete_customer_code']  ?? '');
$deleteCustomerVat   = trim($_GET['delete_customer_vat']   ?? $_POST['delete_customer_vat']   ?? '');

$updateCustomerFlag  = !empty(($_GET['update_customer']    ?? $_POST['update_customer']       ?? ''));
$updateCustomerVat   = trim($_GET['update_customer_vat']   ?? $_POST['update_customer_vat']   ?? '');
$updateCustomerCode  = trim($_GET['update_customer_code']  ?? $_POST['update_customer_code']  ?? '');
$updateName          = trim($_GET['update_name']           ?? $_POST['update_name']           ?? '');
$updateAddress       = trim($_GET['update_address']        ?? $_POST['update_address']        ?? '');
$updateCity          = trim($_GET['update_city']           ?? $_POST['update_city']           ?? '');
$updateZip           = trim($_GET['update_zip']            ?? $_POST['update_zip']            ?? '');
$updateDoy           = trim($_GET['update_doy']            ?? $_POST['update_doy']            ?? '');
$updateEmail         = trim($_GET['update_email']          ?? $_POST['update_email']          ?? '');
$updatePhone1        = trim($_GET['update_phone1']         ?? $_POST['update_phone1']         ?? '');
$updatePhone2        = trim($_GET['update_phone2']         ?? $_POST['update_phone2']         ?? '');
$updateJobDesc       = trim($_GET['update_job_description'] ?? $_POST['update_job_description'] ?? '');

$listSeriesFlag      = !empty(($_GET['list_series']               ?? $_POST['list_series']               ?? ''));
$deleteSeriesId      = trim($_GET['delete_series_id']             ?? $_POST['delete_series_id']          ?? '');
$newSeriesFlag       = !empty(($_GET['new_series']                ?? $_POST['new_series']                ?? ''));
$updateSeriesId      = trim($_GET['update_series_id']             ?? $_POST['update_series_id']          ?? '');
$seriesInvoiceType   = trim($_GET['series_invoice_type']          ?? $_POST['series_invoice_type']       ?? '');
$seriesCode          = trim($_GET['series_code']                  ?? $_POST['series_code']               ?? '');
$seriesStartAa       = trim($_GET['series_start_aa']              ?? $_POST['series_start_aa']           ?? '1');
$seriesDescription   = trim($_GET['series_description']           ?? $_POST['series_description']        ?? '');
$seriesIsTransFail   = !empty(($_GET['series_trans_failure']      ?? $_POST['series_trans_failure']      ?? ''));

$newDeductionFlag    = !empty(($_GET['new_deduction']             ?? $_POST['new_deduction']             ?? ''));
$updateDeductionCode = trim($_GET['update_deduction_code']        ?? $_POST['update_deduction_code']     ?? '');
$deductionDesc       = trim($_GET['deduction_description']        ?? $_POST['deduction_description']     ?? '');
$deductionAmtType    = trim($_GET['deduction_amount_type']        ?? $_POST['deduction_amount_type']     ?? '');
$deductionAmt        = trim($_GET['deduction_amount']             ?? $_POST['deduction_amount']          ?? '');
$deductionDecPaid    = trim($_GET['deduction_decrease_total_paid'] ?? $_POST['deduction_decrease_total_paid'] ?? '');

$listDeductionsFlag  = !empty(($_GET['list_deductions']           ?? $_POST['list_deductions']           ?? ''));
$deleteDeductionCode = trim($_GET['delete_deduction_code']        ?? $_POST['delete_deduction_code']     ?? '');

$listProductsFlag    = !empty(($_GET['list_products']             ?? $_POST['list_products']             ?? ''));
$deleteProductCode   = trim($_GET['delete_product_code']          ?? $_POST['delete_product_code']       ?? '');

$listCategoriesFlag  = !empty(($_GET['list_product_categories']   ?? $_POST['list_product_categories']   ?? ''));
$deleteCategoryId    = trim($_GET['delete_product_category_id']   ?? $_POST['delete_product_category_id'] ?? '');

$newProductFlag      = !empty(($_GET['new_product']               ?? $_POST['new_product']               ?? ''));
$updateProductCode   = trim($_GET['update_product_code']         ?? $_POST['update_product_code']       ?? '');
$productType         = trim($_GET['product_type']                ?? $_POST['product_type']              ?? '');
$productCode         = trim($_GET['product_code']               ?? $_POST['product_code']              ?? '');
$productDescription  = trim($_GET['product_description']         ?? $_POST['product_description']       ?? '');
$productCategory     = trim($_GET['product_category']            ?? $_POST['product_category']          ?? '');
$taricCode          = trim($_GET['taric_code']                  ?? $_POST['taric_code']                ?? '');
$unitPrice          = trim($_GET['unit_price']                  ?? $_POST['unit_price']                ?? '0');
$vatCategory        = trim($_GET['vat_category']                ?? $_POST['vat_category']              ?? '1');
$unit               = trim($_GET['unit']                        ?? $_POST['unit']                      ?? '');
$specialType        = trim($_GET['special_type']                ?? $_POST['special_type']              ?? '');
$feesWithVAT        = trim($_GET['fees_with_vat']               ?? $_POST['fees_with_vat']             ?? '');
$otherTaxesWithVAT  = trim($_GET['other_taxes_with_vat']        ?? $_POST['other_taxes_with_vat']      ?? '');

$newCategoryFlag     = !empty(($_GET['new_product_category']     ?? $_POST['new_product_category']     ?? ''));
$updateCategoryId    = trim($_GET['update_category_id']         ?? $_POST['update_category_id']       ?? '');
$categoryName        = trim($_GET['category_name']              ?? $_POST['category_name']            ?? '');

$companyProfileFlag  = !empty(($_GET['company_profile']           ?? $_POST['company_profile']           ?? ''));
$companyFromTaxis    = !empty(($_GET['company_from_taxis']        ?? $_POST['company_from_taxis']        ?? ''));

$ch = login();

// PDF retrieval by MARK — takes priority over all other parameters
if ($mark !== '') {
    getInvoicePdf($ch, $mark);
}

if ($deleteCustomerCode !== '' || $deleteCustomerVat !== '') {
    $result = deleteCustomerBySelector($ch, $deleteCustomerVat, $deleteCustomerCode);
    curl_close($ch);
    jsonResponse($result);
}

if ($deleteTempId !== '') {
    $result = deleteTempInvoiceById($ch, $deleteTempId, $sellerVat);
    curl_close($ch);
    jsonResponse($result);
}

if ($deleteSeriesId !== '') {
    $result = deleteSeriesById($ch, $deleteSeriesId);
    curl_close($ch);
    jsonResponse($result);
}

if ($deleteDeductionCode !== '') {
    $result = deleteDeductionByCode($ch, $deleteDeductionCode);
    curl_close($ch);
    jsonResponse($result);
}

if ($deleteProductCode !== '') {
    $result = deleteProductByCode($ch, $deleteProductCode);
    curl_close($ch);
    jsonResponse($result);
}

if ($deleteCategoryId !== '') {
    $result = deleteProductCategoryById($ch, $deleteCategoryId);
    curl_close($ch);
    jsonResponse($result);
}

if ($newSeriesFlag) {
    $result = createSeries(
        $ch,
        $seriesInvoiceType,
        $seriesCode,
        $seriesStartAa,
        $seriesDescription,
        $seriesIsTransFail
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($updateSeriesId !== '') {
    $result = updateSeries(
        $ch,
        $updateSeriesId,
        $seriesInvoiceType,
        $seriesCode,
        $seriesStartAa,
        $seriesDescription
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($newDeductionFlag) {
    $result = createDeduction(
        $ch,
        $deductionDesc,
        $deductionAmtType,
        $deductionAmt,
        $deductionDecPaid
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($updateDeductionCode !== '') {
    $result = updateDeduction(
        $ch,
        $updateDeductionCode,
        $deductionDesc,
        $deductionAmtType,
        $deductionAmt,
        $deductionDecPaid
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($createPersonalCust) {
    $result = createPersonalCustomer(
        $ch,
        $custName,
        $custAddress,
        $custCity,
        $custZip,
        $custDoy,
        $custCountry,
        $custJobDescription,
        $custEmail,
        $custPhone1,
        $custPhone2,
        $custLanguage,
        $custIsB2G,
        $custCode,
        $custVat,
        $custOldVat
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($updateCustomerFlag) {
    $result = updateCustomer(
        $ch,
        $updateCustomerVat,
        $updateCustomerCode,
        $updatePhone1,
        $updatePhone2,
        $updateEmail,
        $updateJobDesc,
        $updateAddress,
        $updateCity,
        $updateZip,
        $updateDoy,
        $updateName
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($listCustomers || $allCustomers) {
    $result = listCustomers(
        $ch,
        $afm,
        $customerCodeFilter,
        $customerNameFilter,
        $allCustomers,
        $customersPageSize,
        $customersMaxPages
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($searchInvoicesFlag) {
    $result = searchInvoices(
        $ch,
        $issueDateFrom,
        $issueDateTo,
        $searchInvoiceType,
        $mark,
        $seriesFilter,
        $buyerVatFilter,
        $invoiceStatusFilter,
        $searchCounterpart,
        $searchB2G
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($searchTempFlag) {
    $result = searchTempInvoices(
        $ch,
        $saveDateFrom,
        $saveDateTo,
        $type,
        $buyerVatFilter,
        $tempInvoiceIdFilter
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($listSeriesFlag) {
    $result = listSeries($ch);
    curl_close($ch);
    jsonResponse($result);
}

if ($listDeductionsFlag) {
    $result = listDeductions($ch);
    curl_close($ch);
    jsonResponse($result);
}

if ($listProductsFlag) {
    $result = listProducts($ch);
    curl_close($ch);
    jsonResponse($result);
}

if ($listCategoriesFlag) {
    $result = listProductCategories($ch);
    curl_close($ch);
    jsonResponse($result);
}

if ($newProductFlag) {
    $result = createProduct(
        $ch, $productType, $productCode, $productDescription,
        $productCategory, $taricCode, $unitPrice, $vatCategory,
        $unit, $specialType, $feesWithVAT, $otherTaxesWithVAT
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($updateProductCode !== '') {
    $result = updateProduct(
        $ch, $updateProductCode, $productType, $productDescription,
        $productCategory, $taricCode, $unitPrice, $vatCategory,
        $unit, $specialType, $feesWithVAT, $otherTaxesWithVAT
    );
    curl_close($ch);
    jsonResponse($result);
}

if ($newCategoryFlag) {
    $result = createProductCategory($ch, $categoryName);
    curl_close($ch);
    jsonResponse($result);
}

if ($updateCategoryId !== '') {
    $result = updateProductCategory($ch, $updateCategoryId, $categoryName);
    curl_close($ch);
    jsonResponse($result);
}

if ($companyProfileFlag) {
    $result = getCompanyProfile($ch);
    curl_close($ch);
    jsonResponse($result);
}

if ($companyFromTaxis) {
    $result = getCompanyFromTaxis($ch);
    curl_close($ch);
    jsonResponse($result);
}

// Validate AFM — 9 digits required for GR clients only
if ($afm !== '' && $country === 'GR' && !preg_match('/^\d{9}$/', $afm)) {
    jsonError('Invalid AFM - must be 9 digits for Greek clients');
}

if ($amount > 0) {
    // Invoice flow — find/create customer for GR clients only
    if ($afm !== '' && preg_match('/^\d{9}$/', $afm)) {
        $customer = findOrCreateCustomer($ch, $afm);
        if (!$customer['success']) {
            curl_close($ch);
            jsonResponse($customer);
        }
    }
    $result = createInvoice(
        $ch, $amount, $type, $payment, $descr, '',
        $afm, $name, $address, $city, $zip, $country, $branch,
        $withholdingCategory, $withholdingAmount, $live
    );
} else {
    // Customer lookup flow
    if ($afm === '') jsonError('Missing AFM parameter');
    $result = findOrCreateCustomer($ch, $afm);
}

curl_close($ch);
jsonResponse($result);
