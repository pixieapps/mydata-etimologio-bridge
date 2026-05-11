# Production TODO vs Official timologio Manual

## Priority A (Core gaps)
- [x] Invoice categories full CRUD (manual section 7): create/update/delete with server-side validations and dependency checks.
- [x] Deductions full CRUD (manual section 8): create/update/list/delete with business rules.
- [ ] Product category classifications management (manual section 9): add/edit/remove classifications inside category definitions.
- [x] Full customer edit flow (manual section 11.2): load/edit/save existing customer from ViewCustomer flow.
- [ ] Invoice cancellation endpoint (manual section 12.4): cancel issued invoice and verify status transitions.
- [ ] Draft invoice full workflow (manual section 12.5): open/edit/reissue draft from saved temporary entries.

## Priority B (Invoice issuance parity)
- [ ] Full invoice line-item API parity (manual section 12.2.3): multi-line rows, per-line classifications, advanced line validations.
- [ ] Misc taxes API parity (manual section 12.2.4): all tax categories and constraints as in UI.
- [ ] Invoice notes/remarks parity (manual section 12.2.5) with full validation.
- [ ] Totals/rounding parity tests (manual section 12.2.6) against UI outputs.
- [ ] Counterpart/related invoice MARK helper flows (manual section 12.2.1 / 12.3).

## Priority C (Reporting & account features)
- [ ] Statistics endpoints (manual section 13): expose statistics views as API JSON.
- [ ] Summary book endpoints (manual section 15): export/retrieve summary book data.
- [ ] Company settings update endpoints (manual section 6): persist profile edits (not only read/get from taxis).
- [ ] User/account management related capabilities where technically feasible (manual section 2.2): document unsupported flows that require interactive auth.

## Reliability & Production hardening
- [ ] Add integration tests for HTML parsing on all key pages (customers/invoices/temp/products/series).
- [ ] Add resilient retries + timeout strategy for transient AADE failures.
- [ ] Add structured logging and request correlation IDs.
- [ ] Add API versioning and changelog policy for upstream UI/manual changes.
- [ ] Add secure secret management guidance (environment variables/secret store).
- [ ] Add health-check endpoint and smoke-test script for login/search/pdf lifecycle.

## Notes
- Manual baseline used: `manualTIMOLOGIO.pdf` / `manualTIMOLOGIO.txt`.
- Current implementation already covers invoice search, PDF retrieval by MARK, customer list/create/delete, personal customer create (no AFM), product/category CRUD basics, series/deductions/product/category listing, and selected delete flows.
