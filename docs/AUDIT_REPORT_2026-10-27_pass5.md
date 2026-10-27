# GLC-BLOC — Quality Audit, pass 5: responsive design (27 October 2026)

This pass follows pass 4 earlier the same day (`AUDIT_REPORT_2026-10-27.md`).
It focuses on responsive design and touch use, and on one access gap a user
reported. It also re-runs every regression check. It is an engineering
assessment; no formal ISO/IEC 25010:2023 or WCAG 2.2 conformance is claimed.

## 1. Summary

- **Defects found:** 6 (D-13 to D-18): 1 Medium, 5 Low. All are fixed and retested.
- **Responsive check:** 272 automated checks across 8 viewports. This covers 34 page/role combinations, including members with reactivating and deactivated accounts, and every popup opened at phone width. Before: 24 small tap targets, 5 sideways-scrolling tables and 1 clipped chart label. After: 0 of each.
- **Regression:** 111 PHPUnit tests pass. The crawl at 320 and 1440 px (78 page loads) found 0 console errors, 0 failed requests, 0 horizontal overflow and 0 axe-core WCAG 2.2 AA violations.

**Recommendation: READY WITH CONDITIONS** (section 6).

## 2. Defect register

| ID | Severity | Component | Problem | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|
| D-13 | Medium | Admin sidebar | Ordinary admins (e.g. `admin2`) had no link to the Privacy & Data Retention notice (reported by a user) | The link existed only in the member sidebar and on the login page | "Privacy & My Data" added to the admin sidebar for every admin; Import and Data Retention remain super-admin only | PASS: `testOrdinaryAdminHasThePrivacyLink`; seen in the browser as `admin2` |
| D-14 | Low | Phones and tablets (<1024 px) | 20+ controls under the 24×24 px touch minimum (WCAG 2.5.8): table links ("#9", "2024", "Loan #1"), "Open", "Mark as read", Reports legend buttons, Settings radios, "Back to sign in" | Default Bootstrap sizes for small and link controls | Touch-size rule in `glc.css` (min 24 px; checkboxes and radios 24 px); Reports legend buttons 28 px | PASS: 0 small targets |
| D-15 | Low | Privacy notice, phones | The 900 px retention table scrolled sideways on phones; the public page could not use the app's card layout | Fixed `min-width`, and the card conversion is done by a script the public page does not load | Cards marked up in the HTML; the wide table only from 768 px | PASS: no sideways tables at ≤414 px |
| D-16 | Low | My Contributions / My Accounts at 320 px | The "Reactivating — ₱… missed contributions to settle" badge pushed the card 16 px wider than the screen | Badges do not wrap | `text-wrap-sm` badge style | PASS |
| D-17 | Low | Reports › Approved amount by type | On phones "Loan Disbursement" was cut off at the left edge, and Dividend and Loan Offset had no label at all | Long labels; Chart.js skipping labels when crowded | Two-line labels on narrow cards, every label shown, chart height by number of types | PASS (screenshots at 375 and 1366 px) |
| D-18 | Low | Same chart | Two-line labels were still clipped by a few pixels, and charts drawn before the web font loaded measured labels too narrow | Chart.js under-measures multi-line labels; measured with the fallback font | 10 px extra axis room; charts re-measured once fonts load | PASS (screenshots) |

**Not a defect:** the login page's left panel reports hidden overflow at desktop sizes. These are its decorative blurred circles; a screenshot at 1024 px confirms all text is visible.

## 3. Viewports tested

320×640, 375×812, 414×896, 844×390 (phone landscape), 768×1024, 1024×768,
1366×768 and 1920×1080. Every popup was opened at 375 px; none was wider than
the screen or had content sticking out.

## 4. Performance and security (live site)

- **Throttled mobile profile, first visit:**
  - login: LCP 1.82 s, CLS 0.001
  - privacy: LCP 1.44 s, CLS 0.001
  - targets: LCP ≤ 2.5 s, CLS ≤ 0.1
- **Repeat visits:** LCP 0.4–0.6 s.
- **Security headers:** HSTS, CSP (`frame-ancestors`, `form-action`, `object-src`), `nosniff`, `Referrer-Policy`, `Permissions-Policy` and `X-Frame-Options` are present; `X-Powered-By` is not sent.
- **Dependencies:** `composer audit` (pass 4) reported no advisories, and no dependencies have changed since.

## 5. Files changed

- `public/assets/css/glc.css`: touch-size rule, wrapping badge style, responsive privacy table.
- `app/Views/layouts/main.php`: Privacy link for every admin.
- `app/Views/privacy/body.php`: labelled cells for the phone card layout.
- `app/Views/admin/reports.php`: legend button size, chart labels, height, font re-measure.
- `app/Views/member/contributions.php`, `app/Views/member/profile.php`: wrapping reactivation badge.
- `tests/feature/DataRetentionTest.php`: `testOrdinaryAdminHasThePrivacyLink`.

## 6. Conditions (unchanged from pass 4)

1. Apply Database Updates on production.
2. Have the cooperative confirm R-03 and R-04.
3. Not yet tested:
   - Firefox and Safari
   - screen readers
   - 200% zoom
   - INP and field data
   - signed-in pages on production

`CRON_SECRET` is now set.
