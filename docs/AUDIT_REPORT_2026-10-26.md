# GLC-BLOC — Quality Audit Report (26 October 2026)

Engineering assessment of the GLC-BLOC cooperative management system against
ISO/IEC 25010:2023 and WCAG 2.2 AA (scoped as stated below). This is not a
certification and does not claim formal ISO or WCAG compliance.

## 1. Executive summary

GLC-BLOC lets a cooperative's administrators and members manage accounts,
monthly contributions, loans and installments, dividends and a tamper-evident
ledger. The application was already in good condition after earlier audit
passes; this pass added the data retention policy and per-account
deactivation, then audited the whole app with automated browser crawls.

- **Found:** 7 defects, all Low or Medium (no Critical or High). The most visible was that per-account deactivation could not be seen in the admin UI.
- **Fixed and retested:** all 7.
- **Testing:** 88 automated PHPUnit tests pass, 21 of them new. Each crawl (one before and one after the fixes) loaded 29 pages at 3 widths, 87 page loads in total. The final crawl found 0 console errors, 0 failed requests, 0 horizontal overflow and 0 axe-core WCAG 2.2 AA violations.
- **Live performance (lab):** first-visit LCP was 1.5–1.8 s on a throttled phone profile, and CLS was ≤ 0.02.
- **Blockers on the live site:** the database update is not yet applied to production and `CRON_SECRET` is not set. Both are covered in section 11.

**Recommendation: READY WITH CONDITIONS** (section 13).

## 2. Project inventory

| Area | Finding |
|---|---|
| Language / framework | PHP 8.2 (local), 8.4 (Vercel `vercel-php@0.8.0`); CodeIgniter 4.7.5 |
| Frontend | Server-rendered views, Bootstrap 5.3.2, Font Awesome 6.5, Chart.js 4.4.1, Plus Jakarta Sans (all self-hosted under `public/assets/lib`) |
| Database | MySQL-compatible; production TiDB Cloud (TLS), local MariaDB 10.4; migrations in `app/Database/Migrations`, schema repair in `App\Libraries\DatabaseUpdates` |
| Auth | Session login (DB sessions on Vercel), roles Admin / Member, super-admin flag; CSRF on all POSTs; login throttling via audit log |
| Hosting | Vercel, region `sin1`, single PHP function `api/index.php`; static assets served with long-lived caching; daily cron `/cron/retention` |
| Tests | PHPUnit 10.5, 88 tests against MySQL (GitHub Actions on PHP 8.2 and 8.4) |
| Audit tooling (this pass) | Headless Microsoft Edge 156 driven over the DevTools protocol; axe-core 4.10.2; curl |

## 3. Defect register

| ID | Severity | Component | Problem | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|
| D-01 | Medium | Admin › Members › accounts dialog | Per-account Deactivate was not visible | The Actions column was the 7th column of a `modal-lg` dialog. With the inline reason field it overflowed off-screen | Wider dialog (`modal-xl`, full screen below `md`); a compact Deactivate button opens a confirm row with the reason | PASS: browser flow deactivated an account and showed the flash message |
| D-02 | Medium | Same | Before the DB update, Actions showed only "—" with no explanation | The availability check hid the controls silently | Banner explains the needed database update; the cell says "Needs database update"; the member page gets a matching note | PASS (code path); production still pending, see B-01 |
| D-03 | Low | Admin › Members | Member-level "Deactivate" next to "Manage Accounts" could be mistaken for account deactivation | Ambiguous label | Relabelled "Deactivate member" / "Reactivate member" | PASS (visual) |
| D-04 | Low | Admin account actions | Search was lost after deactivating or reactivating an account | Redirect to bare `/admin/members` | Search carried in a hidden `q`; `membersPage()` redirect | PASS: returned to `?q=MEM-003` |
| D-05 | Medium | Audit Logs, Privacy table | axe `scrollable-region-focusable` (serious): a sideways-scrolling table could not be reached by keyboard | `.table-responsive` box without a tab stop | The shell script gives scrolling boxes `tabindex=0`, `role=region` and a name; the Privacy table is marked up statically | PASS: axe 0 violations |
| D-06 | Low | Account Requests card (phone) | Request text broke into one fragment per line | The stacked-table cell is a flex container | Wrapped in a single block | PASS (screenshot comparison) |
| D-07 | Low | Retention summary | "1 members deactivated" | Plural-only labels | Singular for counts of 1 | PASS |

**Risks and recommendations (not defects, not changed):**
- **R-01:** Deactivating a whole *member* is not blocked while that member has an open loan, so a member who still owes money could lose access to pay it. Changing this is a business-rule decision for the cooperative.
- **R-02:** `HEAD /` returns 404, while `GET` returns 200. Use GET for uptime monitors.

## 4. Test execution summary

| Category | Executed | Pass | Fail | Blocked / not run |
|---|---|---|---|---|
| Unit + feature (PHPUnit, MySQL) | 88 | 88 | 0 | 0 |
| — of which new this pass | 21 (account deactivation 10, retention 11) | 21 | 0 | 0 |
| Browser crawl (29 pages × 375/768/1280 px) | 87 page loads | 87 (no console errors, failed requests or overflow) | 0 | 0 |
| Accessibility (axe-core, WCAG 2.0/2.1/2.2 A+AA tags, 375 and 1280 px) | 58 page scans | 58 after fixes (56 before) | 2 before fixes (D-05) | 0 |
| End-to-end flows in a real browser | 5 (admin deactivate, admin reactivate, member request, approve request, XSS probe) | 5 | 0 | 0 |
| Live performance (lab) | 8 measurements | 8 within targets | 0 | INP not measured (needs real interaction) |
| Dependency audit (`composer audit`) | 1 | 1 (no advisories) | 0 | 0 |
| Authenticated pages on production | — | — | — | BLOCKED: no production credentials used |
| Backup and restore | — | — | — | NOT RUN: TiDB Cloud access needed |

## 5. ISO/IEC 25010:2023 assessment

| Characteristic | Criteria | Evidence | Status | Remaining gaps |
|---|---|---|---|---|
| Functional suitability | Core workflows complete and correct: contributions, loans, payments, dividends, ledger, retention, per-account deactivation | 88 tests; browser flows (section 4) | PASS (in tested scope) | Production DB update pending (B-01) |
| Performance efficiency | LCP ≤ 2.5 s, CLS ≤ 0.1 on throttled mobile; static assets compressed and cached | LCP 1.50 / 1.76 s, CLS ≤ 0.018 (section 8); Brotli; `max-age=31536000, immutable` | PASS | Cold start ~1.4 s TTFB; INP unmeasured |
| Compatibility | Works in a Chromium browser at phone, tablet and desktop widths; PHP 8.2 and 8.4 | Edge crawl; CI matrix | PARTIAL | Firefox and Safari not tested |
| Interaction capability | No overflow; clear labels; destructive actions confirmed | Crawl; D-01..D-04, D-06 fixed | PASS (in tested scope) | No usability study with real members |
| Reliability | No errors during crawl; transactions for multi-row writes; failures reported, not thrown | Crawl; code review; tests | PASS (in tested scope) | No load or failover test |
| Security | Server-side auth on every route; CSRF; parameterized SQL; output escaping; login throttling; no vulnerable dependencies | Tests (IDOR, role checks); XSS probe; `composer audit`; headers | PASS (in tested scope) | No external penetration test |
| Maintainability | Modular services; tests for new features; documented config | `Config\Retention`, `*Service` classes, 21 new tests | PASS | — |
| Flexibility | Environment-driven configuration; schema repair; retention periods in one config file | `DatabaseUpdates`, `Config\Retention` | PASS | — |
| Safety | No silent loss of financial data | Retention never deletes financial records; deactivation blocked with open loans or pending payments; legal hold | PASS | R-01 |

## 6. UI/UX and responsive design

- **Widths tested:** 375, 768 and 1280 px across all 29 pages, plus a 390 px check of the accounts dialog. No horizontal overflow.
- **Changes:** D-01 to D-04 and D-06 (Members page and accounts dialog, phone request card). On phones the accounts dialog is full screen and each account becomes a card with its action.

## 7. Accessibility (WCAG 2.2 AA, scoped)

- **Automated:** axe-core 4.10.2 with the wcag2a, wcag2aa, wcag21a, wcag21aa and wcag22aa tags, on every page at 375 and 1280 px.
  - Before: 1 rule (scrollable-region-focusable) on 2 page loads.
  - After: 0 violations.
- **Manual:** the reason field takes focus when the confirm row opens, and Cancel returns focus to the Deactivate button. Fields are labelled. Confirmations are native dialogs.
- **Not done:** screen-reader testing (NVDA/VoiceOver), 200 % zoom and reduced-motion checks. Automated scanning alone is not proof of WCAG conformance.

## 8. Performance results (live site, lab)

Measured on 26 October 2026 with headless Edge.

- **Mobile profile:** 375 × 812, 4× CPU slowdown, 1.6 Mbps, 150 ms RTT.
- **Desktop profile:** 1350 × 940, no throttling.

| Page | Profile | Visit | TTFB | FCP | LCP | CLS | Transfer |
|---|---|---|---|---|---|---|---|
| /login | mobile | first | 187 ms | 1500 ms | 1500 ms | 0.001 | 315 KB |
| /login | mobile | repeat | 62 ms | 336 ms | 336 ms | 0 | 3 KB |
| /privacy | mobile | first | 107 ms | 1760 ms | 1760 ms | 0.011 | 269 KB |
| /privacy | mobile | repeat | 69 ms | 396 ms | 396 ms | 0 | 4 KB |
| /login | desktop | first | 72 ms | 436 ms | 436 ms | 0 | 315 KB |
| /privacy | desktop | first | 175 ms | 1456 ms | 1456 ms | 0.018 | 269 KB |

- **Server response (curl):** warm requests answered in 0.29–0.33 s. The first request after idle took 1.42 s (serverless cold start).
- **Signed-in pages:** measured on a local server only, so not representative of production. DOMContentLoaded was 0.2–0.4 s.
- **Not measured:** field data (no real-user monitoring is configured) and INP.
- **No optimization was needed:** all measured lab metrics already meet the targets.

## 9. Security findings

- **XSS probe (this pass):** attribute-breaking, `<img onerror>`, `<script>` and JS-string payloads were submitted as a deactivation reason. Tags were stripped when saved; the rest rendered as text in the dialog and the audit log. No script ran. PASS.
- **Access control:**
  - Members cannot act on another member's account. Tested by `testMemberCannotRequestChangesToAnotherMembersAccount`.
  - Members cannot call admin routes.
  - Ordinary admins cannot use retention tools.
  - The cron endpoint returns 404 without the secret.
- **Dependencies:** `composer audit` reported no advisories.
- **Headers (live):** the site sends HSTS, `X-Frame-Options` and `Content-Security-Policy`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`, and does not send `X-Powered-By`.
- **Remaining:**
  - The local `.env.tidb` file holds live database credentials. Delete it when it is no longer needed.
  - No external penetration test has been done.

## 10. Code changes (this audit pass)

| File | Change | Addresses |
|---|---|---|
| `app/Views/admin/members.php` | Wider and responsive accounts dialog; confirm row for Deactivate; disabled button with the reason; database-update banner; search kept; member-level labels; request cell as one block | D-01, D-02, D-03, D-04, D-06 |
| `app/Controllers/Admin.php` | `membersPage()` redirect keeps the search | D-04 |
| `app/Views/member/profile.php` | Note when requests are unavailable | D-02 |
| `app/Views/partials/app_shell_js.php` | Scrolling tables get a tab stop, role and name | D-05 |
| `app/Views/privacy/body.php` | Same for the public Privacy table | D-05 |
| `app/Services/RetentionService.php` | Singular wording in the summary | D-07 |

Commits: `ac9e4e1`, `d9b1d53` on branch `fix/account-deactivation-ui`. The earlier feature work was merged to `main` as `a16b3e3`.

## 11. Remaining issues and blockers

| ID | Item | Action needed |
|---|---|---|
| B-01 | Production database has not received the update (new columns), so retention and per-account deactivation are switched off live | Super admin: Settings → **Apply Database Updates** |
| B-02 | `CRON_SECRET` not set in Vercel; the daily review will not run | `vercel env add CRON_SECRET production --sensitive`, then redeploy |
| B-03 | Branch `fix/account-deactivation-ui` is not yet merged or deployed | Merge to `main` and deploy |
| B-04 | `.env.tidb` username lacks the TiDB user prefix, so the database cannot be reached from this computer | Correct it from TiDB Cloud → Connect, or delete the file |
| B-05 | Not tested: Firefox and Safari, screen readers, INP and field data, authenticated production pages, backup restore | Manual checks, ideally with the adviser and the cooperative |
| B-06 | Retention periods marked "to confirm" (10-year financial records, backup retention) | Confirm with the auditor and adviser |

## 12. Deployment status

| Status | Evidence |
|---|---|
| Committed to Git | `main` at `a16b3e3` (pushed); `fix/account-deactivation-ui` at `d9b1d53` (local, not pushed) |
| Production deployment verified | Retention and deactivation feature (`a16b3e3`) deployed 26 October 2026: `/login`, `/privacy` 200; cron 404 without secret; no runtime errors in the logs |
| Not yet deployed | D-01 to D-07 fixes (this audit pass) |
| Rollback | Vercel dashboard → Deployments → promote the previous deployment, or `vercel rollback`. The database changes only add columns, so the older code keeps working |

## 13. Final readiness recommendation

**READY WITH CONDITIONS.**

No Critical or High defects were found. All automated tests and browser checks pass, and the live performance targets are met. The conditions are:

1. Merge and deploy the audit fixes (B-03).
2. Apply the database updates in production (B-01).
3. Set `CRON_SECRET` (B-02).
4. Have the cooperative and adviser confirm the retention periods (B-06).
5. Complete the untested items in B-05 before relying on the app for real member data.
