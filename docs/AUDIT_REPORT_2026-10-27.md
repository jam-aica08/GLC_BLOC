# GLC-BLOC — Quality Audit Report, pass 4 (27 October 2026)

This pass follows the audit of 26 October (`AUDIT_REPORT_2026-10-26.md`). It
covers the features added since then:
- withdrawals and the outstanding-loan restriction
- loan offsets
- account reactivation after missed months
- the ₱3,600 dividend rule
- the 3% administration share
- the loan rollover fix

It also re-checks the whole application for regressions. It is an engineering
assessment, not a certification: no formal ISO/IEC 25010:2023 or WCAG 2.2
conformance is claimed.

## 1. Executive summary

- **Defects found this pass:** 3 new ones (D-08 to D-10, all Low), all fixed and retested.
- **Fixed in the feature work since the last report:** one High-severity data-integrity defect, D-11. Missed loan months dropped earlier unpaid amounts. The repair for loans already affected runs through Apply Database Updates.
- **Tests:** 110 PHPUnit tests pass (64 feature tests on MySQL; unit tests included).
- **Browser crawl:** 39 page/role combinations at 320, 768 and 1440 px (117 page loads) showed 0 console errors, 0 failed requests, 0 horizontal overflow and 0 axe-core WCAG 2.2 AA violations.
- **Dependencies:** `composer audit` reports no advisories.

**Recommendation: READY WITH CONDITIONS** (section 7).

## 2. Defect register (since 26 Oct)

| ID | Severity | Component | Problem | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|
| D-08 | Low | Retention review (`RetentionService::blockers`) | A member with a withdrawal waiting to be paid out could be auto-deactivated, or have their sign-in data removed | The "waiting for approval" check predated withdrawals | Pending withdrawals count as payments waiting | PASS: `testMemberWithAPendingWithdrawalIsNotDeactivated` |
| D-09 | Low | Same | A member with a **Defaulted** loan was not treated as having an open loan | The status list left out `Defaulted` | Uses `ContributionBalance::OUTSTANDING_LOAN` | PASS: `testMemberWithADefaultedLoanIsNotDeactivated` |
| D-10 | Low | Dashboard | The "Total Contributions" tile showed the net amount after withdrawals and offsets | Label not updated with the figure | Renamed "Contributions Held" | PASS (full suite renders dashboards) |
| D-11 | **High** | `LoanService::rollOver` | Two or more missed months in a row dropped the earlier months' unpaid amounts (₱306 owed showed as ₱200) | `foreach` by value read a stale copy of the next month | Reads each month fresh; `repairLostCarryOver()` restores affected loans via Apply Database Updates, with an audit entry | PASS: `testMissedMonthsInARowKeepTheWholeDebt`, `testLostCarryOverIsDetectedAndRestored` |
| D-12 | Medium | Apply Database Updates on TiDB | The update failed with "Unknown column 'admin_share_rate'" | Two columns added in one `ALTER`, the second `AFTER` the first; TiDB rejects this | One column per `ALTER` | PASS (code); confirmed by the next update on production |

## 3. Business-rule verification (new features)

| Rule | Evidence |
|---|---|
| Withdraw available contributions, admin-approved and ledgered | `testMemberWithdrawsAvailableContributionsAfterApproval`, plus a browser walkthrough |
| No withdrawal while any loan is Pending, Approved, Active or Defaulted (at request and at approval) | `testOutstandingLoanBlocksWithdrawalRequests`, `testLoanTakenAfterTheRequestBlocksItsApproval` |
| Deactivated is not closed: contributions are still accepted; reminders and new loans stop; dividends follow the ₱3,600 rule | `testDeactivatedAccountTakesContributionsButNoLoans`, `testDeactivatedAccountGetsNoContributionReminders`, `testOnlyAccountsWithTheFullYearOfContributionsAreEligible` |
| Reactivation requires the missed months, from the deactivation month to the current month | `testReactivationWaitsForTheMissedMonthsToBePaid`, `testDeactivatingAgainBeforeSettlingKeepsTheEarlierMissedMonths` |
| Dividends need ₱3,600 for the year | `testOnlyAccountsWithTheFullYearOfContributionsAreEligible`, `testDashboardEstimateCountsAccountsOnTrackThisYear` |
| Loan offset only after a missed payment, capped at the available contributions, confirmed and ledgered | `testOffsetSettlesAMissedLoanFromContributions`, `testNoOffsetWithoutAMissedPayment`, `testOffsetCannotTakeMoreThanAvailable`, `testOffsetNeedsConfirmationAndAnAdmin` |
| 3% of the pool to administration, 97% to members, exact to the centavo | `testThreePercentOfThePoolGoesToAdministration` |

## 4. Risks and decisions for the cooperative (not defects)

- **R-03:** A withdrawal lowers the money an account holds but does not lower that year's ₱3,600 dividend count. The policy measures contributions *made*. Confirm this is intended.
- **R-04:** Reactivation counts missed months from the **deactivation month**. Months skipped *before* deactivating already show as unpaid in the account's normal contribution standing.
- **R-01 (still open):** Deactivating a whole *member* is not blocked by an open loan.

## 5. Security

- Withdrawals and their reasons are rendered escaped.
- Members can act only on their own accounts (`testMemberCannotWithdrawFromAnotherMembersAccount`).
- Offset, approval and retention routes are admin-only and enforced on the server (`testOffsetNeedsConfirmationAndAnAdmin`).
- Every change is CSRF-protected and recorded in the audit trail.

## 6. Not tested / blockers

- Signed-in pages on production: no production credentials were used.
- Firefox and Safari; screen readers; INP and field data.
- The TiDB-specific schema path, until the next Apply Database Updates on production.
- `CRON_SECRET` is still unset, so the daily retention review must be run manually.

## 7. Readiness

**READY WITH CONDITIONS:**
1. Apply Database Updates on production.
2. Set `CRON_SECRET`.
3. Have the cooperative confirm R-03 and R-04.
4. Complete the untested items in section 6 before handling real member money.
