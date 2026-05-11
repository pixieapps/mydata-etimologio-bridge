<?php
// Clear OPcache on every request so file changes take effect immediately.
// Safe to leave permanently — has negligible performance impact on low-traffic endpoints.
if (function_exists('opcache_reset')) {
    opcache_reset();
}

/**
 * Greek Invoice Generator
 * Wraps e_timologio library to provide a clean HTML form interface.
 */

// Load configuration and e-timologio library
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/e-timologio.php';

// ============================================================================
// GENERATOR CONFIGURATION
// ============================================================================
$GEN_CONFIG = [
    // Default product code per document type (must exist in your catalogue)
    'default_apy'       => 'ΥΠ001',
    'default_tpy'       => 'ΥΠ001',

    // Default payment method per document type
    // 1=Τρ.Λογ.Ημεδαπής 2=Τρ.Λογ.Αλλοδαπής 3=Μετρητά 4=Επιταγή
    // 5=Επί Πιστώσει 6=Web Banking 7=POS 8=IRIS
    'default_payment_apy' => 7,
    'default_payment_tpy' => 7,

    // Amount input mode: true = input με ΦΠΑ (gross), false = χωρίς ΦΠΑ (net)
    'amount_with_vat_apy' => true,
    'amount_with_vat_tpy' => true,
];


// ── PDF proxy — ?pdf=MARK (streams PDF from AADE) ────────────────────────────
if (!empty($_GET['pdf'])) {
    $mark = trim($_GET['pdf']);
    try {
        $ch     = etimologio_login();
        $result = etimologio_pdf($ch, $mark);
        etimologio_close($ch);
        if ($result['success']) {
            $pdf = base64_decode($result['pdf_base64']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="invoice-' . $mark . '.pdf"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
        } else {
            http_response_code(404);
            echo 'PDF not found: ' . htmlspecialchars($result['error'] ?? '');
        }
    } catch (\Exception $e) {
        if (isset($ch)) etimologio_close($ch);
        http_response_code(500);
        echo 'Error: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

// ── AJAX: Customer lookup — delegates to etimologio_customer_full() ──────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['lookup_vat'])) {
    header('Content-Type: application/json');
    $term = trim($_GET['lookup_vat']);
    if (strlen($term) < 3) {
        echo json_encode(['success' => false, 'error' => 'Τουλάχιστον 3 χαρακτήρες']);
        exit;
    }
    try {
        $ch   = etimologio_login();
        $full = etimologio_customer_full($ch, $term);
        etimologio_close($ch);
        if ($full !== null) {
            echo json_encode(['success' => true, 'info' => $full]);
        } else {
            $suffix = preg_match('/^\d{9}$/', $term) ? ' ή στο Taxisnet' : '';
            echo json_encode(['success' => false, 'error' => 'Δεν βρέθηκε στη βάση πελατών' . $suffix]);
        }
    } catch (\Exception $e) {
        if (isset($ch)) etimologio_close($ch);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch products (needed for both form display and POST typeCode derivation) ──
$products      = [];
$productsError = '';
$forceRefresh  = ($_GET['rebuild'] ?? '') === 'true';
try {
    $chProducts     = etimologio_login();
    $productsResult = etimologio_products($chProducts, $forceRefresh);

    // If forced rebuild — enrich with classifications and write cache
    if ($forceRefresh && $productsResult['success']) {
        _etim_enrich_products($chProducts, $productsResult['products']);
        _etim_cache_write($productsResult);
    }
    etimologio_close($chProducts);
    if ($productsResult['success']) {
        foreach ($productsResult['products'] as $p) {
            $code = $p['code'];
            $cls  = $p['classifications'] ?? [];
            $products[$code] = [
                'description'     => $p['description'],
                'vat_pct'         => $p['vat_pct'],
                'vat_category'    => $p['vat_category'] ?? 1,
                'classifications' => $cls,
                'is_apy'          => isset($cls['58']) || isset($cls['57']),
                'is_tpy'          => isset($cls['1']) || isset($cls['20']) || isset($cls['22']),
            ];
        }
        // If no product has classifications, cache hasn't been built yet
        $hasClassifications = !empty(array_filter($products, fn($p) => !empty($p['classifications'])));
        if (!$hasClassifications) {
            $productsError = 'Το προϊοντολόγιο φορτώθηκε χωρίς χαρακτηρισμούς. Παρακαλώ χτίστε την cache: ?rebuild=true';
        }
    } else {
        $productsError = $productsResult['error'] ?? 'Αδυναμία φόρτωσης προϊόντων';
    }
} catch (\Exception $e) {
    if (isset($chProducts)) etimologio_close($chProducts);
    $productsError = $e->getMessage();
}

// ── Handle form submission ────────────────────────────────────────────────────
$result = null;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $docType     = $_POST['doc_type']  ?? 'apy';
    $productCode = $_POST['product']   ?? ($docType === 'tpy' ? $GEN_CONFIG['default_tpy'] : $GEN_CONFIG['default_apy']);
    // Use pre-calculated net if JS back-calculated from gross; fallback to raw amount
    $amount      = (float) ($_POST['net_amount'] ?: ($_POST['amount'] ?? 0));
    $payment     = (int)   ($_POST['payment'] ?? ($docType === 'tpy' ? $GEN_CONFIG['default_payment_tpy'] : $GEN_CONFIG['default_payment_apy']));
    $language    = $_POST['language']  ?? 'el';
    $live        = !empty($_POST['live']);

    $afm     = trim($_POST['customer_vat']     ?? '');
    $name    = trim($_POST['customer_name']    ?? '');
    $address = trim($_POST['customer_address'] ?? '');
    $city    = trim($_POST['customer_city']    ?? '');
    $zip     = trim($_POST['customer_zip']     ?? '');
    $country      = trim($_POST['customer_country'] ?? 'GR');
    $notes        = trim($_POST['invoice_notes']    ?? '');

    $withholding         = !empty($_POST['withholding']);
    $withholdingCategory = (int) ($_POST['withholding_category'] ?? 3);
    $withholdingAmount   = $withholding ? round($amount * 0.20, 2) : 0;

    // Derive typeCode early from cache so validation can use it
    $typeCode = match(true) {
        $docType === 'tpy' && isset($products[$productCode]['classifications']['22']) => '22',
        $docType === 'tpy' && isset($products[$productCode]['classifications']['20']) => '20',
        $docType === 'tpy' && isset($products[$productCode]['classifications']['1'])  => '1',
        $docType === 'apy' && isset($products[$productCode]['classifications']['57']) => '57',
        default => '58',
    };

    if ($amount <= 0) $errors[] = 'Απαιτείται ποσό μεγαλύτερο του μηδέν.';
    if ($typeCode === '20' && !preg_match('/^\d{9}$/', $afm)) {
        $errors[] = 'Απαιτείται έγκυρο ΑΦΜ 9 ψηφίων για Τιμολόγιο.';
    }

    if (empty($errors)) {
        try {
            $ch = etimologio_login();

            $result = etimologio_create($ch, [
                'amount'               => $amount,
                'type'                 => $typeCode,
                'payment'              => $payment,
                'description'          => $productCode,
                'language'             => $language,
                'live'                 => $live,
                'afm'                  => $afm,
                'name'                 => $name,
                'address'              => $address,
                'city'                 => $city,
                'zip'                  => $zip,
                'country'              => $country,
                'withholding_category' => $withholding ? $withholdingCategory : 0,
                'withholding_amount'   => $withholdingAmount,
                'notes'                => $notes,
            ]);

            etimologio_close($ch);

        } catch (\Exception $e) {
            if (isset($ch)) etimologio_close($ch);
            $errors[] = 'Σφάλμα συστήματος: ' . $e->getMessage();
        }
    }
}

$apyProducts = array_filter($products, fn($p) =>  $p['is_apy']);
$tpyProducts = array_filter($products, fn($p) =>  $p['is_tpy']);

// ── Issuer info from config ───────────────────────────────────────────────────
$cfg        = $ETIMOLOGIO_CONFIG ?? [];
$issuerVat  = $cfg['company_vat'] ?? '';
$issuerName = $cfg['company_name'] ?? '';

// ── Helper: restore POST value after draft reload ─────────────────────────────
// Only active when we stayed on the form (draft success or validation errors)
$old = static function(string $key, string $default = ''): string {
    $stayOnForm = !empty($_POST) && (
        empty($GLOBALS['result']['live'] ?? true) ||  // draft success
        !empty($GLOBALS['errors'])                     // validation error
    );
    return htmlspecialchars($stayOnForm ? ($_POST[$key] ?? $default) : $default);
};
$oldChecked = static function(string $key): string {
    return !empty($_POST[$key]) ? 'checked' : '';
};
$oldSelected = static function(string $key, string $value): string {
    return (($_POST[$key] ?? '') === $value) ? 'selected' : '';
};

// ── Render ────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Έκδοση Παραστατικού</title>
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #333;
        line-height: 1.6;
        min-height: 100vh;
        padding: 20px;
    }

    .container {
        max-width: 900px;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .app-header {
        background: linear-gradient(135deg, #2E5BBA, #1e3a8a);
        color: white;
        padding: 30px;
        text-align: center;
    }

    .app-header h1 { font-size: 28px; margin-bottom: 10px; font-weight: 600; }
    .subtitle { font-size: 16px; opacity: 0.9; }

    .invoice-form { padding: 30px; }

    .form-section {
        margin-bottom: 30px;
        padding: 20px;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        background: #f8f9fa;
    }

    .form-section h3 {
        color: #2E5BBA;
        margin-bottom: 15px;
        font-size: 18px;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 8px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .form-group { margin-bottom: 15px; }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #495057;
    }

    .form-group input,
    .form-group select {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #2E5BBA;
        box-shadow: 0 0 0 3px rgba(46, 91, 186, 0.1);
    }

    .help-text {
        display: block;
        margin-top: 5px;
        color: #6c757d;
        font-size: 12px;
        line-height: 1.4;
    }

    /* Doc type pills */
    .doc-type-pills { display: flex; gap: 12px; margin-bottom: 4px; }
    .doc-pill {
        flex: 1;
        padding: 14px 10px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        cursor: pointer;
        text-align: center;
        font-size: 15px;
        font-weight: 600;
        background: #fff;
        transition: all 0.2s ease;
        user-select: none;
    }
    .doc-pill:hover { border-color: #2E5BBA; }
    .doc-pill.active { background: linear-gradient(135deg, #2E5BBA, #1e3a8a); border-color: #1e3a8a; color: #fff; }
    .doc-pill small { display: block; font-size: 11px; font-weight: 400; margin-top: 3px; opacity: 0.85; }
    input[name="doc_type"] { display: none; }

    /* VAT badge */
    .vat-badge {
        display: inline-block;
        padding: 8px 18px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        background: #dbeafe;
        color: #1e3a8a;
        border: 2px solid #bfdbfe;
        margin-top: 2px;
    }

    /* Calculation display */
    .calculation-display {
        background: #e8f5e8;
        border: 2px solid #28a745;
        border-radius: 8px;
        padding: 15px;
        margin-top: 15px;
        font-size: 14px;
    }
    .calc-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
    .calc-total { border-top: 2px solid #28a745; padding-top: 8px; font-weight: bold; font-size: 16px; }
    .calc-withholding { color: #dc3545; }
    .calc-payable { color: #28a745; font-weight: bold; font-size: 16px; border-top: 2px solid #28a745; padding-top: 8px; margin-top: 4px; }

    /* Lookup status */
    .lookup-status { font-size: 12px; margin-top: 4px; min-height: 16px; }
    .lookup-status.ok      { color: #28a745; }
    .lookup-status.err     { color: #dc3545; }
    .lookup-status.loading { color: #6c757d; }

    /* Withholding */
    .withholding-info {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 12px;
        margin-bottom: 15px;
        font-size: 13px;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        cursor: pointer;
        font-weight: 600;
        padding: 10px;
        background: #ffffff;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    .checkbox-label:hover { border-color: #2E5BBA; background: #f8f9fa; }
    .checkbox-label input[type="checkbox"] {
        margin-right: 10px;
        transform: scale(1.2);
        flex-shrink: 0;
        width: auto;
    }

    /* Language buttons */
    .lang-group { display: flex; gap: 10px; }
    .lang-btn {
        flex: 1;
        padding: 10px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        background: #fff;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }
    .lang-btn:hover { border-color: #2E5BBA; }
    .lang-btn.active { background: linear-gradient(135deg, #2E5BBA, #1e3a8a); border-color: #1e3a8a; color: #fff; }
    input[name="language"] { display: none; }

    /* Draft/Live mode */
    .mode-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px;
        background: #fff3cd;
        border: 2px solid #ffc107;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    .mode-toggle input[type=checkbox] { width: 18px; height: 18px; cursor: pointer; flex-shrink: 0; }
    .mode-toggle .mode-text strong { display: block; font-size: 14px; color: #856404; }
    .mode-toggle .mode-text small  { font-size: 12px; color: #6c757d; }

    /* Submit button */
    .btn-primary {
        background: linear-gradient(135deg, #2E5BBA, #1e3a8a);
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(46, 91, 186, 0.3); }
    .btn-primary.live-mode { background: linear-gradient(135deg, #28a745, #1a7a4a); }
    .btn-primary.live-mode:hover { box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3); }
    .btn-secondary {
        background: #6c757d; color: white; padding: 12px 24px; border: none;
        border-radius: 6px; text-decoration: none; font-weight: 500; transition: all 0.3s ease;
        display: inline-block;
    }
    .btn-secondary:hover { background: #545b62; }

    .draft-notice {
        background: #e8f5e8;
        border: 2px solid #28a745;
        border-radius: 8px;
        padding: 16px 20px;
        margin-bottom: 20px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }
    .draft-notice .icon { font-size: 22px; flex-shrink: 0; }
    .draft-notice strong { display: block; color: #155724; font-size: 15px; margin-bottom: 3px; }
    .draft-notice small  { color: #6c757d; font-size: 12px; }

    /* Result pages */
    .success-header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px; text-align: center; }
    .draft-header   { background: linear-gradient(135deg, #2E5BBA, #1e3a8a); color: white; padding: 30px; text-align: center; }
    .error-header   { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; padding: 30px; text-align: center; }

    .result-section { padding: 30px; border-bottom: 1px solid #e9ecef; }
    .info-grid { display: grid; gap: 12px; margin-top: 15px; }
    .info-item {
        padding: 15px; background: #f8f9fa; border-radius: 6px;
        border-left: 4px solid #28a745;
    }
    .info-item code {
        background: #e9ecef; padding: 4px 8px; border-radius: 4px;
        font-family: monospace; font-size: 14px; margin-left: 10px;
    }
    .qr-link { color: #2E5BBA; text-decoration: none; margin-left: 10px; }
    .qr-link:hover { text-decoration: underline; }

    .actions { padding: 30px; display: flex; gap: 15px; flex-wrap: wrap; }

    .error-section { padding: 30px; border-bottom: 1px solid #e9ecef; }
    .error-list { list-style: none; margin-top: 15px; }
    .error-list li {
        padding: 10px 15px; background: #f8d7da; border: 1px solid #f5c6cb;
        border-radius: 4px; margin-bottom: 10px; color: #721c24;
    }
    .error-list li::before { content: "⚠️ "; }

    .app-footer {
        background: #f8f9fa; padding: 20px 30px; border-top: 1px solid #e9ecef;
        text-align: center; color: #6c757d; font-size: 14px;
    }

    @media (max-width: 600px) {
        .form-row { grid-template-columns: 1fr; }
        .doc-type-pills { flex-direction: column; }
        .container { margin: 10px; border-radius: 8px; }
    }
    </style>
</head>
<body>
<div class="container">

    <header class="app-header">
        <h1>🇬🇷 Έκδοση Παραστατικού</h1>
        <p class="subtitle">ΑΑΔΕ myDATA — Ηλεκτρονικό Τιμολόγιο</p>
        <p class="subtitle" style="margin-top:8px;font-size:13px;opacity:0.85;">
            <?= htmlspecialchars($issuerName) ?> &nbsp;·&nbsp; ΑΦΜ: <?= htmlspecialchars($issuerVat) ?>
        </p>
    </header>

<?php if (!empty($errors)): ?>

    <div class="error-header">
        <h1>❌ Σφάλματα στη φόρμα</h1>
    </div>
    <div class="error-section">
        <h3>🚨 Παρακαλώ διορθώστε:</h3>
        <ul class="error-list">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="actions">
        <a href="?" class="btn-primary">← Επιστροφή στη φόρμα</a>
    </div>

<?php elseif ($result !== null && $result['success'] && !empty($result['live'])): ?>

    <!-- LIVE success → full result page -->
    <div class="success-header">
        <h1>✅ Παραστατικό Εκδόθηκε!</h1>
        <p>Υποβλήθηκε στην ΑΑΔΕ και έλαβε MARK.</p>
    </div>
    <div class="result-section">
        <h3>📊 Στοιχεία ΑΑΔΕ</h3>
        <div class="info-grid">
            <div class="info-item"><strong>MARK:</strong><code><?= htmlspecialchars($result['mark']) ?></code></div>
            <div class="info-item"><strong>ΑΑ:</strong><code><?= htmlspecialchars($result['aa'] ?? '') ?></code></div>
            <div class="info-item"><strong>Καθαρή αξία:</strong><code>€<?= number_format($result['amount_net'], 2) ?></code></div>
            <div class="info-item"><strong>ΦΠΑ:</strong><code>€<?= number_format($result['amount_vat'], 2) ?></code></div>
            <div class="info-item"><strong>Σύνολο:</strong><code>€<?= number_format($result['amount_total'], 2) ?></code></div>
            <?php if (!empty($result['qrUrl'])): ?>
            <div class="info-item"><strong>QR:</strong><a class="qr-link" href="<?= htmlspecialchars($result['qrUrl']) ?>" target="_blank">Προβολή QR Code</a></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <?php $markSafe = htmlspecialchars(urlencode($result['mark'])); ?>
        <a href="?pdf=<?= $markSafe ?>"
           class="btn-primary" target="_blank">
            📄 Προβολή PDF
        </a>
        <a href="?pdf=<?= $markSafe ?>"
           class="btn-secondary" download="invoice-<?= $markSafe ?>.pdf">
            ⬇️ Λήψη PDF
        </a>
        <a href="?" class="btn-secondary">📝 Νέο Παραστατικό</a>
    </div>

<?php elseif ($result !== null && !$result['success']): ?>

    <!-- Any failure → error page -->
    <div class="error-header">
        <h1>❌ Αποτυχία Έκδοσης</h1>
    </div>
    <div class="error-section">
        <ul class="error-list">
            <li><?= htmlspecialchars($result['error'] ?? 'Άγνωστο σφάλμα') ?></li>
        </ul>
    </div>
    <div class="actions">
        <a href="?" class="btn-primary">← Επιστροφή στη φόρμα</a>
    </div>

<?php else: ?>

    <!-- Form — also shown after draft success, with notice at top -->

    <form method="POST" class="invoice-form" id="invoiceForm">
        <input type="hidden" name="net_amount" id="net_amount" value="">

        <?php if ($result !== null && $result['success'] && empty($result['live'])): ?>
        <div class="draft-notice">
            <div class="icon">📋</div>
            <div>
                <strong>Αποθηκεύτηκε ως Προσωρινό — Temp ID: <?= htmlspecialchars($result['temp_id']) ?></strong>
                <small>
                    Καθαρή: €<?= number_format($result['amount_net'], 2) ?> &nbsp;+&nbsp;
                    ΦΠΑ: €<?= number_format($result['amount_vat'], 2) ?> &nbsp;=&nbsp;
                    Σύνολο: €<?= number_format($result['amount_total'], 2) ?>
                    &nbsp;·&nbsp; Δεν υποβλήθηκε στην ΑΑΔΕ, δεν εκχωρήθηκε MARK.
                </small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Type -->
        <?php
        $hasApy     = !empty($apyProducts);
        $hasTpy     = !empty($tpyProducts);
        $defaultDoc = $hasApy ? 'apy' : 'tpy';
        $postDoc    = $_POST['doc_type'] ?? $defaultDoc;
        // If POST requests a type with no products, fall back to default
        if ($postDoc === 'apy' && !$hasApy) $postDoc = 'tpy';
        if ($postDoc === 'tpy' && !$hasTpy) $postDoc = 'apy';
        $dimStyle   = 'style="opacity:0.35;cursor:not-allowed;pointer-events:none;"';
        ?>
        <div class="form-section">
            <h3>📄 Τύπος Παραστατικού</h3>
            <div class="doc-type-pills">
                <div class="doc-pill <?= $postDoc === 'apy' ? 'active' : '' ?>" id="pill_apy"
                     <?= $hasApy ? 'onclick="setDocType(\'apy\')"' : $dimStyle ?>>
                    ΑΠΥ
                    <small>Απόδειξη Παροχής Υπηρεσιών<br>Ιδιώτες / Private Clients</small>
                </div>
                <div class="doc-pill <?= $postDoc === 'tpy' ? 'active' : '' ?>" id="pill_tpy"
                     <?= $hasTpy ? 'onclick="setDocType(\'tpy\')"' : $dimStyle ?>>
                    ΤΠΥ
                    <small>Τιμολόγιο Παροχής Υπηρεσιών<br>Επαγγελματίες / Businesses</small>
                </div>
            </div>
            <input type="radio" name="doc_type" id="dt_apy" value="apy" <?= $postDoc === 'apy' ? 'checked' : '' ?>>
            <input type="radio" name="doc_type" id="dt_tpy" value="tpy" <?= $postDoc === 'tpy' ? 'checked' : '' ?>>
            <small class="help-text" style="margin-top:8px;display:block;">
                <strong>ΑΠΥ:</strong> Για ιδιώτες — χωρίς παρακράτηση φόρου &nbsp;|&nbsp;
                <strong>ΤΠΥ:</strong> Για επαγγελματίες — με δυνατότητα παρακράτησης 20%
            </small>
        </div>

        <!-- Service -->
        <div class="form-section">
            <h3>⚖️ Υπηρεσία</h3>

            <div class="form-group">
                <label for="product_select">Αγαθό / Υπηρεσία:</label>
                <?php if ($productsError): ?>
                <div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:10px;margin-bottom:8px;color:#721c24;font-size:13px;">
                    ⚠️ Αδυναμία φόρτωσης αγαθών από e-timologio: <?= htmlspecialchars($productsError) ?>
                </div>
                <?php endif; ?>
                <select name="product" id="product_select" onchange="onProductChange()"
                        <?= empty($products) ? 'disabled' : '' ?>>
                    <?php if (empty($products)): ?>
                    <option value="">— Δεν βρέθηκαν αγαθά —</option>
                    <?php endif; ?>
                    <?php foreach ($apyProducts as $code => $p): ?>
                    <option value="<?= htmlspecialchars($code) ?>"
                            data-vat="<?= htmlspecialchars($p['vat_pct']) ?>"
                            data-type="apy"
                            <?= $oldSelected('product', $code) ?>>
                        <?= htmlspecialchars($code) ?> — <?= htmlspecialchars($p['description']) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php foreach ($tpyProducts as $code => $p): ?>
                    <option value="<?= htmlspecialchars($code) ?>"
                            data-vat="<?= htmlspecialchars($p['vat_pct']) ?>"
                            data-type="tpy"
                            style="display:none"
                            <?= $oldSelected('product', $code) ?>>
                        <?= htmlspecialchars($code) ?> — <?= htmlspecialchars($p['description']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="help-text">Η λίστα φιλτράρεται αυτόματα ανάλογα με τον τύπο παραστατικού</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ΦΠΑ:</label>
                    <div><span class="vat-badge" id="vat_display">24%</span></div>
                    <input type="hidden" name="vat_rate" id="vat_rate" value="24">
                    <small class="help-text">Αυτόματα από το επιλεγμένο αγαθό</small>
                </div>
                <div class="form-group">
                    <label for="payment">Τρόπος Πληρωμής:</label>
                    <select name="payment" id="payment">
                        <option value="3" <?= $oldSelected('payment','3') ?>>💰 Μετρητά</option>
                        <option value="6" <?= $oldSelected('payment','6') ?>>🏦 Web Banking</option>
                        <option value="7" <?= $oldSelected('payment','7') ?>>💳 POS / e-POS</option>
                        <option value="4" <?= $oldSelected('payment','4') ?>>📄 Επιταγή</option>
                        <option value="5" <?= $oldSelected('payment','5') ?>>📊 Επί Πιστώσει</option>
                        <option value="8" <?= $oldSelected('payment','8') ?>>📲 IRIS</option>
                        <option value="1" <?= $oldSelected('payment','1') ?>>🏛 Τρ. Λογ. Ημεδαπής</option>
                        <option value="2" <?= $oldSelected('payment','2') ?>>🌐 Τρ. Λογ. Αλλοδαπής</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:5px;">
                    <label for="amount" style="margin:0;font-weight:600;color:#495057;">Ποσό — €</label>
                    <label for="amount_with_vat" style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-size:13px;color:#495057;font-weight:400;margin:0;">
                        <input type="checkbox" id="amount_with_vat" onchange="updateCalc()" style="width:auto;transform:scale(1.1);">
                        Εισαγωγή με ΦΠΑ
                    </label>
                </div>
                <input type="number" id="amount" name="amount" value="<?= $old('amount') ?>" step="0.01" min="0.01"
                       placeholder="π.χ. 500.00" required oninput="updateCalc()">
                <small class="help-text" id="amount_hint">Καθαρή αξία χωρίς ΦΠΑ — το ΦΠΑ υπολογίζεται αυτόματα</small>
            </div>

            <div id="calculationDisplay" class="calculation-display" style="display:none"></div>

            <div class="form-group" style="margin-top:15px;">
                <label>Γλώσσα Παραστατικού:</label>
                <div class="lang-group">
                    <div class="lang-btn active" data-lang="el" onclick="setLang('el',this)">🇬🇷 Ελληνικά</div>
                    <div class="lang-btn" data-lang="en" onclick="setLang('en',this)">🇺🇸 English</div>
                </div>
                <input type="hidden" name="language" id="language" value="<?= $old('language','el') ?>">
            </div>
        </div>

        <!-- Withholding (ΤΠΥ only) -->
        <div class="form-section" id="withholding_section" style="display:none">
            <h3>📊 Παρακράτηση Φόρου</h3>
            <div class="withholding-info">
                <p><strong>ℹ️</strong> Για Έλληνες επαγγελματίες, εφαρμόζεται παρακράτηση 20% επί της καθαρής αξίας (Αμοιβές Συμβούλων Διοίκησης).</p>
            </div>
            <label class="checkbox-label">
                <input type="checkbox" name="withholding" id="withholding_cb" onchange="updateCalc()" <?= $oldChecked('withholding') ?>>
                Εφαρμογή παρακράτησης 20% (ο πελάτης αποδίδει απευθείας στην εφορία)
            </label>
            <input type="hidden" name="withholding_category" value="3">
        </div>

        <!-- Customer -->
        <div class="form-section" id="customer_section">
            <h3 id="customer_title">👤 Στοιχεία Πελάτη</h3>

            <div class="form-group">
                <label for="customer_vat">ΑΦΜ Πελάτη:</label>
                <input type="text" name="customer_vat" id="customer_vat"
                       placeholder="π.χ. 007690144" maxlength="15"
                       value="<?= $old('customer_vat') ?>"
                       oninput="onAfmInput(this.value)">
                <div class="lookup-status" id="lookup_status"></div>
                <small class="help-text" id="vat_help">Αυτόματη συμπλήρωση στοιχείων από Taxisnet μετά από 9 ψηφία</small>
            </div>

            <div class="form-group">
                <label for="customer_name">Επωνυμία:</label>
                <input type="text" name="customer_name" id="customer_name"
                       placeholder="Αυτόματη συμπλήρωση από ΑΦΜ"
                       value="<?= $old('customer_name') ?>">
            </div>


            <div class="form-group">
                <label for="invoice_notes">📝 Σημειώσεις <small style="font-weight:400;color:#6c757d;">(προαιρετικό — εμφανίζονται στο παραστατικό)</small>:</label>
                <input type="text" name="invoice_notes" id="invoice_notes"
                       placeholder="π.χ. Ref: 12345"
                       value="<?= $old('invoice_notes') ?>">
            </div>

            <div id="tpy_fields">
                <div class="form-row">
                    <div class="form-group">
                        <label for="customer_address">Διεύθυνση:</label>
                        <input type="text" name="customer_address" id="customer_address"
                               placeholder="Αυτόματη συμπλήρωση"
                               value="<?= $old('customer_address') ?>">
                    </div>
                    <div class="form-group">
                        <label for="customer_city">Πόλη:</label>
                        <input type="text" name="customer_city" id="customer_city"
                               placeholder="Αυτόματη συμπλήρωση"
                               value="<?= $old('customer_city') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="customer_zip">Τ.Κ.:</label>
                        <input type="text" name="customer_zip" id="customer_zip"
                               placeholder="Αυτόματη συμπλήρωση"
                               value="<?= $old('customer_zip') ?>">
                    </div>
                    <div class="form-group">
                        <label for="customer_country">Χώρα:</label>
                        <select name="customer_country" id="customer_country">
                            <option value="GR" <?= $oldSelected('customer_country','GR') ?>>🇬🇷 Ελλάδα</option>
                            <option value="US" <?= $oldSelected('customer_country','US') ?>>🇺🇸 ΗΠΑ</option>
                            <option value="DE" <?= $oldSelected('customer_country','DE') ?>>🇩🇪 Γερμανία</option>
                            <option value="FR" <?= $oldSelected('customer_country','FR') ?>>🇫🇷 Γαλλία</option>
                            <option value="IT" <?= $oldSelected('customer_country','IT') ?>>🇮🇹 Ιταλία</option>
                            <option value="ES" <?= $oldSelected('customer_country','ES') ?>>🇪🇸 Ισπανία</option>
                            <option value="GB" <?= $oldSelected('customer_country','GB') ?>>🇬🇧 Ηνωμένο Βασίλειο</option>
                            <option value="CA" <?= $oldSelected('customer_country','CA') ?>>🇨🇦 Καναδάς</option>
                            <option value="CN" <?= $oldSelected('customer_country','CN') ?>>🇨🇳 Κίνα</option>
                            <option value="AU" <?= $oldSelected('customer_country','AU') ?>>🇦🇺 Αυστραλία</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="form-section">
            <div class="mode-toggle">
                <input type="checkbox" name="live" id="live_cb" onchange="onLiveToggle()">
                <div class="mode-text">
                    <strong id="mode_label">🔴 Υποβολή LIVE στην ΑΑΔΕ (με MARK)</strong>
                    <small id="mode_hint">Χωρίς επιλογή: αποθήκευση ως Προσωρινό — ασφαλές για δοκιμές.</small>
                </div>
            </div>
            <button type="submit" class="btn-primary" id="submit_btn">
                📋 Αποθήκευση Προσωρινού
            </button>
        </div>

    </form>

    <footer class="app-footer">
        <p>Συνδέεται με ΑΑΔΕ myDATA μέσω e_timologio — DRAFT: ασφαλές για δοκιμές | LIVE: άμεση υποβολή</p>
    </footer>

<?php endif; ?>

</div><!-- /container -->

<script>
const GEN_CONFIG = {
    default_apy:          '<?= htmlspecialchars($GEN_CONFIG['default_apy']) ?>',
    default_tpy:          '<?= htmlspecialchars($GEN_CONFIG['default_tpy']) ?>',
    default_payment_apy:  <?= (int) $GEN_CONFIG['default_payment_apy'] ?>,
    default_payment_tpy:  <?= (int) $GEN_CONFIG['default_payment_tpy'] ?>,
    amount_with_vat_apy:  <?= $GEN_CONFIG['amount_with_vat_apy'] ? 'true' : 'false' ?>,
    amount_with_vat_tpy:  <?= $GEN_CONFIG['amount_with_vat_tpy'] ? 'true' : 'false' ?>,
};

// ── Doc type ──────────────────────────────────────────────────────────────────
function setDocType(type) {
    document.getElementById('dt_' + type).checked = true;
    document.getElementById('pill_apy').classList.toggle('active', type === 'apy');
    document.getElementById('pill_tpy').classList.toggle('active', type === 'tpy');

    document.getElementById('withholding_section').style.display = type === 'tpy' ? '' : 'none';

    if (type === 'apy') {
        document.getElementById('withholding_cb').checked = false;
        document.getElementById('customer_title').textContent = '👤 Στοιχεία Πελάτη (προαιρετικά για ΑΠΥ)';
        document.getElementById('vat_help').textContent = 'Δεν απαιτείται ΑΦΜ για ΑΠΥ — προαιρετικό';
    } else {
        document.getElementById('customer_title').textContent = '🏢 Στοιχεία Πελάτη (απαιτείται ΑΦΜ)';
        document.getElementById('vat_help').textContent = 'Απαιτείται ΑΦΜ 9 ψηφίων για Τιμολόγιο';
    }

    // Apply config defaults for this doc type
    document.getElementById('amount_with_vat').checked =
        type === 'tpy' ? GEN_CONFIG.amount_with_vat_tpy : GEN_CONFIG.amount_with_vat_apy;

    // Set default payment if not already changed by user
    const paymentSel = document.getElementById('payment');
    const defaultPay = type === 'tpy' ? GEN_CONFIG.default_payment_tpy : GEN_CONFIG.default_payment_apy;
    paymentSel.value = String(defaultPay);

    // For ΤΠΥ prefer configured default, otherwise first visible
    const sel = document.getElementById('product_select');
    Array.from(sel.options).forEach(opt => {
        opt.style.display = opt.dataset.type === type ? '' : 'none';
    });
    // Select configured default product for this type, fallback to first visible
    const defaultCode = type === 'tpy' ? GEN_CONFIG.default_tpy : GEN_CONFIG.default_apy;
    const preferred   = Array.from(sel.options).find(o => o.style.display !== 'none' && o.value === defaultCode);
    const first       = Array.from(sel.options).find(o => o.style.display !== 'none');
    const target      = preferred || first;
    if (target) { sel.value = target.value; onProductChange(); }

    updateCalc();
}

// ── Product → VAT ─────────────────────────────────────────────────────────────
function onProductChange() {
    const sel = document.getElementById('product_select');
    const opt = sel.options[sel.selectedIndex];
    const vat = opt ? opt.dataset.vat : '24%';

    document.getElementById('vat_display').textContent = vat;
    document.getElementById('vat_rate').value = parseInt(vat) || 0;

    updateCalc();
}

// ── Calculation ───────────────────────────────────────────────────────────────
function updateCalc() {
    const raw     = parseFloat(document.getElementById('amount').value) || 0;
    const withVat = document.getElementById('amount_with_vat').checked;
    const box     = document.getElementById('calculationDisplay');
    const hint    = document.getElementById('amount_hint');

    if (raw <= 0) { box.style.display = 'none'; return; }

    const vatRate = (parseInt(document.getElementById('vat_rate').value) || 0) / 100;

    // Back-calculate net if input is gross
    const net   = withVat && vatRate > 0
                  ? Math.round((raw / (1 + vatRate)) * 100) / 100
                  : raw;
    const vat   = Math.round(net * vatRate * 100) / 100;
    const total = Math.round((net + vat) * 100) / 100;
    const wh    = document.getElementById('withholding_cb').checked
                  ? Math.round(net * 0.20 * 100) / 100 : 0;
    const payable = Math.round((total - wh) * 100) / 100;
    const fmt   = v => '€' + v.toFixed(2);

    // Update hint text
    hint.textContent = withVat
        ? 'Ποσό με ΦΠΑ — η καθαρή αξία υπολογίζεται αυτόματα: ' + fmt(net)
        : 'Καθαρή αξία χωρίς ΦΠΑ — το ΦΠΑ υπολογίζεται αυτόματα';

    // Always store the net in the hidden field sent to PHP
    document.getElementById('net_amount').value = net.toFixed(2);

    let html = `
        <h4 style="color:#155724;margin-bottom:10px;">💰 Ανάλυση Ποσών</h4>
        <div class="calc-row"><span>Καθαρή αξία:</span><span>${fmt(net)}</span></div>
        <div class="calc-row"><span>ΦΠΑ ${(vatRate*100).toFixed(0)}%:</span><span>${fmt(vat)}</span></div>
        <div class="calc-row calc-total"><span>Σύνολο:</span><span>${fmt(total)}</span></div>`;

    if (wh > 0) {
        html += `
        <div class="calc-row calc-withholding" style="margin-top:6px;"><span>Παρακράτηση 20%:</span><span>−${fmt(wh)}</span></div>
        <div class="calc-row calc-payable"><span>Πληρωτέο ποσό:</span><span>${fmt(payable)}</span></div>`;
    }

    box.innerHTML = html;
    box.style.display = '';
}

// ── Language ──────────────────────────────────────────────────────────────────
function setLang(lang, el) {
    document.getElementById('language').value = lang;
    document.querySelectorAll('.lang-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
}

// ── Live/Draft toggle ─────────────────────────────────────────────────────────
function onLiveToggle() {
    const live = document.getElementById('live_cb').checked;
    document.getElementById('mode_label').textContent = live
        ? '🔴 Υποβολή LIVE στην ΑΑΔΕ (με MARK)'
        : '🔴 Υποβολή LIVE στην ΑΑΔΕ (με MARK)';
    document.getElementById('mode_hint').textContent = live
        ? 'Το παραστατικό θα υποβληθεί άμεσα στην ΑΑΔΕ και θα λάβει MARK.'
        : 'Χωρίς επιλογή: αποθήκευση ως Προσωρινό — ασφαλές για δοκιμές.';
    const btn = document.getElementById('submit_btn');
    btn.textContent = live ? '🔴 Έκδοση LIVE' : '📋 Αποθήκευση Προσωρινού';
    btn.classList.toggle('live-mode', live);
}

// ── AFM / Tax ID lookup — DB first, Taxisnet fallback for Greek 9-digit AFMs ──
let afmTimer = null;

function onAfmInput(val) {
    clearTimeout(afmTimer);
    const status = document.getElementById('lookup_status');

    if (val.length < 3) {
        status.textContent = val.length > 0 ? val.length + '/3+ χαρακτήρες' : '';
        status.className   = 'lookup-status';
        return;
    }

    status.textContent = '🔍 Αναζήτηση…';
    status.className   = 'lookup-status loading';

    afmTimer = setTimeout(() => {
        fetch('?lookup_vat=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.info) {
                    const i = data.info;
                    if (i.name)    document.getElementById('customer_name').value    = i.name;
                    if (i.address) document.getElementById('customer_address').value = i.address;
                    if (i.city)    document.getElementById('customer_city').value    = i.city;
                    if (i.zip)     document.getElementById('customer_zip').value     = i.zip;
                    if (i.country) {
                        const sel = document.getElementById('customer_country');
                        if ([...sel.options].some(o => o.value === i.country)) sel.value = i.country;
                    }
                    status.textContent = '✓ ' + (i.name || 'Βρέθηκε');
                    status.className   = 'lookup-status ok';
                } else {
                    status.textContent = '✗ ' + (data.error || 'Δεν βρέθηκε');
                    status.className   = 'lookup-status err';
                }
            })
            .catch(() => {
                status.textContent = '✗ Σφάλμα δικτύου';
                status.className   = 'lookup-status err';
            });
    }, 600);
}


// ── Init ──────────────────────────────────────────────────────────────────────
// Restore state from previous POST (draft reload)
const _post = {
    doc_type:  '<?= htmlspecialchars($_POST['doc_type']  ?? 'apy') ?>',
    language:  '<?= htmlspecialchars($_POST['language']  ?? 'el') ?>',
    amount:    '<?= htmlspecialchars($_POST['amount']    ?? '') ?>',
    product:   '<?= htmlspecialchars($_POST['product']   ?? '') ?>',
};

// Set doc type (triggers product filter + withholding visibility)
setDocType('<?= $postDoc ?? 'apy' ?>');

// Restore product selection after setDocType has filtered the options
if (_post.product) {
    const sel = document.getElementById('product_select');
    const opt = Array.from(sel.options).find(o => o.value === _post.product);
    if (opt) { sel.value = _post.product; onProductChange(); }
}

// Restore language button active state
document.querySelectorAll('.lang-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.lang === _post.language);
});

// Trigger calculation if amount was restored
if (_post.amount) updateCalc();
</script>
</body>
</html>
