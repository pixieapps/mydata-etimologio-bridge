<?php
// ============================================================================
// e-Timologio — Configuration
// ============================================================================
//
// HOW TO USE:
//   1. Copy this file to config.php
//   2. Fill in your credentials below
//   3. Never commit config.php to version control (it's in .gitignore)
//
// ============================================================================

// ── Core library credentials (required by e-timologio.php) ───────────────────

$ETIMOLOGIO_CONFIG = [
    // Do not change this
    'base_url'         => 'https://mydata.aade.gr/timologio',

    // Your company VAT number — 9 digits (ΑΦΜ εταιρείας)
    'company_vat'      => 'CHANGE_ME',

    // Your e-timologio username
    'username'         => 'CHANGE_ME',

    // Your e-timologio subscription key
    // Found in: e-timologio → Ρυθμίσεις → Στοιχεία Χρήστη
    'subscription_key' => 'CHANGE_ME',

    // Your company display name (printed on invoices)
    'company_name'     => 'CHANGE_ME',

    // Invoice types that carry 0% VAT (non-EU clients) — usually no need to change
    'zero_vat_types'   => ['22'],
];

// ── Invoice generator settings (required by greek-invoice-generator.php) ─────
// Only needed if you use the HTML form generator — skip if using the library only.

$GEN_CONFIG = [
    // Default product/service code for each document type
    // Must exist in your e-timologio product catalogue
    'default_apy'         => 'ΥΠ001',   // ΑΠΥ — Απόδειξη Παροχής Υπηρεσιών
    'default_tpy'         => 'ΥΠ001',   // ΤΠΥ — Τιμολόγιο Παροχής Υπηρεσιών

    // Default payment method per document type
    // 1=Τρ.Λογ.Ημεδαπής  2=Τρ.Λογ.Αλλοδαπής  3=Μετρητά  4=Επιταγή
    // 5=Επί Πιστώσει      6=Web Banking         7=POS       8=IRIS
    'default_payment_apy' => 7,
    'default_payment_tpy' => 7,

    // Amount input mode per document type:
    //   true  = user enters gross amount (με ΦΠΑ) — net is back-calculated
    //   false = user enters net amount (χωρίς ΦΠΑ) — VAT is added on top
    'amount_with_vat_apy' => true,
    'amount_with_vat_tpy' => true,
];
