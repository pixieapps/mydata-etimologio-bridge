<?php
// ============================================================================
// e-Timologio API — Configuration
// ============================================================================
// Copy this file to config.php and fill in your credentials.
// NEVER commit config.php to version control.
// ============================================================================

// Your company VAT number (ΑΦΜ εταιρείας)
const COMPANY_VAT      = 'YOUR_COMPANY_VAT';

// Your e-timologio username
const USERNAME         = 'YOUR_USERNAME';

// Your e-timologio subscription key
// Found in: e-timologio → Ρυθμίσεις → Στοιχεία Χρήστη
const SUBSCRIPTION_KEY = 'YOUR_SUBSCRIPTION_KEY';

// Base URL — do not change
const BASE_URL         = 'https://mydata.aade.gr/timologio';

// Cookie file path for session persistence
// Must be writable by your web server
const COOKIE_FILE      = '/tmp/etimologio_cookies.txt';

// Invoice types that carry 0% VAT (non-EU clients)
const ZERO_VAT_TYPES   = ['22', '23'];
