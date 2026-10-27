# GLC-BLOC — Quality Audit, pass 6 (27 October 2026)

This pass covers what changed after pass 5:
- the demo data loader and showcase (Load Demo Data)
- the live notification bell
- the self-refreshing dashboard tiles

It also re-runs the full regression checks. It is an engineering assessment;
no formal ISO/IEC 25010:2023 or WCAG 2.2 conformance is claimed.

## 1. Summary

- **Defects found:** 2 (D-19, D-20), both fixed and retested.
- **Tests:** 117 PHPUnit tests pass in one uninterrupted run.
- **Browser crawl:** 39 page/role combinations at 320 and 1440 px (78 page loads) found 0 console errors, 0 failed requests, 0 horizontal overflow and 0 axe-core WCAG 2.2 AA violations.
- **Dependencies:** `composer audit` reports no advisories.
- **Live site:** 6 security headers present; `X-Powered-By` not sent; the new background endpoints answer 401 when signed out.

**Recommendation: READY WITH CONDITIONS** (section 5).

## 2. Defect register

| ID | Severity | Component | Problem | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|
| D-19 | Medium | Live bell + dashboard tiles | An open admin dashboard made **6 background requests a minute** (bell and tiles separately), about 360 an hour per open tab, which counts against the hosting plan's function allowance | Two independent 20-second checks | The bell's check now also returns the four pending counts; the tiles update from it and make no requests of their own | PASS: measured 3 requests per minute (–50%) over 65 s with a live member submission, and the tile still updated (1 → 2). Test asserts `pending` is in the feed |
| D-20 | Medium | Load Demo Data / test suite | The full test suite stopped partway with "Maximum execution time of 55 seconds exceeded" (the unexplained crash seen in pass 5) | `set_time_limit(55)`, meant for the 60-second web function, also applied to the long-running test process once the demo test had run | The limit applies to web requests only | PASS: 117/117 in one run, three times |

## 3. Checks of the new features

| Feature | Evidence |
|---|---|
| New loan application appears in the admin's bell without reload | `testAdminFeedReportsANewLoanApplication`; browser: bell 2 → 3 |
| New notifications pop up even when the page had none | Same test (fixed before pass 6) |
| Background checks never extend the 20-minute idle sign-out, nor count as retention activity | `testBackgroundCheckDoesNotCountAsActivity` |
| Dashboard pending tiles follow new requests | `testPendingSummaryCountsNewRequests`; browser: tile 0 → 1 and 1 → 2 |
| Feeds are role-scoped; signed-out access refused | `testFeedsAreForTheirOwnRoleOnly`; live 401 |
| Load Demo Data: super admin only, needs DEMO + password; admins kept; showcase created; audited | `testOnlyTheSuperAdminCanLoadDemoData`, `testLoadingDemoDataReplacesMembersAndAddsTheShowcase` |
| Ledger stays verifiable after a demo load | Integrity verification: 1,595 valid, 0 tampered (local) |

## 4. Not tested / limits

- The pop-up notice itself was not scanned by axe while visible. It uses `role="status"` and a labelled close button.
- Load Demo Data has not been run against production from here (no production admin password). Its duration on Vercel and TiDB is unmeasured (about 10 s locally).
- Browsers other than Edge/Chromium, screen readers, 200% zoom, and INP/field data remain untested.

## 5. Conditions

1. If not done since the last deploy, run Apply Database Updates on production.
2. Have the cooperative confirm R-03 and R-04 (pass 4).
3. Before real member data:
   - run the untested checks in section 4;
   - consider a paid hosting plan if many admins keep dashboards open all day, since each open dashboard makes about 180 background requests an hour.
