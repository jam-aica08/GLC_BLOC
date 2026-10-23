# Architecture

## Overview

```
Browser ──► Vercel (sin1) ── api/index.php ──► CodeIgniter 4 ──► TiDB Cloud (ap-southeast-1)
                │
                └── /assets/* served from public/assets (cached 1 day)
```

A standard CodeIgniter 4 MVC app. Controllers stay thin; the money rules live
in services so that the web pages, the Excel import and the `demo:load`
command all follow the same rules.

## Code layout

| Path | Contents |
|------|----------|
| `app/Controllers/` | `Auth`, `Admin`, `Member`, `Settings`, `DataImport` |
| `app/Services/` | Business rules: `LoanService`, `DividendService`, `LedgerService`, `DataImportService`, `Notifier`, `AnalyticsService`, `DashboardService`, `AccountRequestService` |
| `app/Libraries/` | `AccountAccess`, `LoginThrottle`, `AuditLog`, `DatabaseUpdates`, `DataReset`, `DemoData`, `ImportWorkbook`, `SchemaCache` |
| `app/Filters/` | `AuthFilter` (role check on every request) and `SecurityHeaders` |
| `app/Commands/DemoLoad.php` | `php spark demo:load` |
| `app/Database/Migrations/` | Schema history; `Seeds/GlcBlocSeeder.php` creates the starter accounts |
| `app/Views/` | Layouts, shared partials, admin and member pages, branded error pages |
| `public/assets/css/glc.css` | Theme and responsive rules |
| `tests/` | PHPUnit unit and feature tests |

## Main tables

| Table | Holds |
|-------|-------|
| `users`, `roles` | Logins; `is_super_admin` and `account_status` on `users` |
| `members` | Personal details, one per member login |
| `cooperative_accounts` | Basic or Pioneer savings accounts, status and start date |
| `financial_records` | Contributions and other money movements, with approval status |
| `loans`, `loan_installments`, `loan_payments`, `loan_payment_installments` | Loans, their monthly schedule, repayments and how each repayment was split |
| `contributions` | Monthly contribution periods per account |
| `dividend_distributions`, `dividends` | A year's distribution batch, and one payout per account |
| `blockchain_ledger` | The hash-chained ledger |
| `notifications`, `audit_logs`, `ci_sessions` | Notifications, audit trail (also used for login throttling), sessions |

## Business rules

**Contributions.**

- PHP 300 a month; a single payment is 300 to 3,600.
- An account becomes Active when January up to its opening month is paid and
  approved (`syncStatuses`).

**Loans** (`LoanService`).

- The limit is 2× approved contributions, checked when the member applies and
  again when an admin approves.
- One open loan (Pending, Approved or Active) per member, enforced under a row
  lock so two quick submissions cannot both pass.
- Terms 3/6/12/18/24 months; interest 2% (Pioneer) or 5% (Basic), collected
  in the first instalment.
- Payments fill the oldest unpaid instalment first. `rollOver` moves unpaid
  amounts to the next month after the due date; imports pass an `$asOf` date
  so history is replayed correctly.

**Dividends** (`DividendService`).

- The profit pool is the interest on loans approved in that year.
- Eligible accounts are Active and opened on or before 31 December of that
  year.
- Each Basic account weighs 1.00 and each Pioneer account 1.02. The
  arithmetic is done in whole centavos, and leftover centavos go to the
  largest remainders, so payouts add up to the pool exactly.
- A distribution is a batch that an admin approves; only approval marks the
  dividends Distributed and writes them to the ledger.

## The ledger

`LedgerService::createEntry` stores each transaction with the SHA-256 hash of
its own data plus the previous entry's hash, so the entries form a chain.
Changing, inserting or deleting any row in the database breaks every hash
after it, and **Run Integrity Verification** reports the first broken entry. Imports use
`appendMany` to write a whole batch to the chain inside the import
transaction.

## Security

- **Access.**
  - `AuthFilter` checks the role for every admin or member route.
  - `AccountAccess::allowed()` re-reads the account on every request, so a
    deactivated user is signed out at once.
  - Super-admin rights are read from the database, not trusted from the
    session.
- **Sign-in.**
  - `LoginThrottle` allows 5 failures per username and 20 per IP in 15
    minutes.
  - The real client IP comes from Vercel's `x-real-ip` header.
- **Requests.**
  - CSRF protection on every form (cookie mode).
  - All database access goes through the query builder or bound parameters.
- **Output.**
  - Views escape with `esc()`, using the `js` context inside inline
    handlers.
  - `SecurityHeaders` sets `X-Frame-Options`, `X-Content-Type-Options`,
    `Referrer-Policy`, `Permissions-Policy` and a CSP with `frame-ancestors`
    and `form-action`.
- **Errors.** Production error pages are static and reveal no details.
