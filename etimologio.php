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

// Validate AFM — 9 digits required for GR clients only
if ($afm !== '' && $country === 'GR' && !preg_match('/^\d{9}$/', $afm)) {
    jsonError('Invalid AFM - must be 9 digits for Greek clients');
}

$ch = login();

// PDF retrieval by MARK — takes priority over all other parameters
if ($mark !== '') {
    getInvoicePdf($ch, $mark);
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
