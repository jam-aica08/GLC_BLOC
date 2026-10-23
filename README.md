# GLC-BLOC

A cooperative savings and loan system: members save a monthly contribution,
borrow against it, repay in instalments and share the year's loan interest as
dividends. Every money movement is written to a tamper-evident ledger.

Built with CodeIgniter 4 (PHP 8.2+), MySQL/MariaDB or TiDB, Bootstrap 5 and
Chart.js. Production runs on Vercel (`sin1`) with a TiDB Cloud database in
`ap-southeast-1`.

## Features

- **Members:** dashboard, contributions, loan applications, instalment
  payments, dividends, ledger history and notifications.
- **Admins:** member and account management, approval queues, loans,
  dividends, an interactive Reports page, the ledger and its verification.
- **Super admin:** adds admins, imports a client's data from Excel and resets
  data.
- **Security:** CSRF, login throttling, per-request account checks, security
  headers and parameterised queries.

## Quick start (local)

```bash
composer install
cp env .env              # then set the database section, see docs/setup-and-deployment.md
php spark migrate --all
php spark db:seed GlcBlocSeeder
php spark serve          # http://localhost:8080
```

The seeder creates two accounts. Change both passwords before using the
system for real.

| Role | Username | Password |
|------|----------|----------|
| Super admin | `admin` | `Admin@123` |
| Member | `jdoe` | `Member@123` |

To fill the system with realistic demo data, run `php spark demo:load`
([details](docs/data-loading.md)).

## Documentation

| Guide | What it covers |
|-------|----------------|
| [Setup and deployment](docs/setup-and-deployment.md) | Local setup with XAMPP, Vercel and TiDB configuration, migrations and tests |
| [Loading data](docs/data-loading.md) | Demo data for presentations, and importing a client's records from Excel |
| [User guide](docs/user-guide.md) | Day-to-day use for admins and members |
| [Architecture](docs/architecture.md) | Code layout, business rules, the ledger and security |

## Tests

```bash
vendor/bin/phpunit
```

The feature tests need a MySQL test database; see
[Setup and deployment](docs/setup-and-deployment.md#running-the-tests). GitHub
Actions runs the full suite on PHP 8.2 and 8.3 for every push and pull request.
