# Setup and deployment

## Requirements

- PHP 8.2 or 8.3 with the `intl`, `mbstring`, `mysqli`, `zip` and `xml`
  extensions. OpenSpout, the Excel library, does not support PHP 8.5 yet.
- Composer 2.
- MySQL 8, MariaDB 10.4+ (XAMPP) or TiDB.

## Local setup with XAMPP

1. Start Apache and MySQL in the XAMPP control panel, then create an empty
   database (for example `glc_bloc`) in phpMyAdmin.
2. Install the dependencies and create your `.env`:

   ```bash
   composer install
   cp env .env
   ```

3. In `.env`, set:

   ```ini
   CI_ENVIRONMENT = development
   app.baseURL = 'http://localhost:8080/'

   database.default.hostname = localhost
   database.default.database = glc_bloc
   database.default.username = root
   database.default.password =
   database.default.DBDriver = MySQLi
   ```

4. Create the tables and the starter accounts:

   ```bash
   php spark migrate --all
   php spark db:seed GlcBlocSeeder
   ```

5. Run `php spark serve` and open <http://localhost:8080>. Sign in as `admin` /
   `Admin@123`.

`.env` and every other `.env*` file are git-ignored. Never commit them.

## How the app finds its settings

`app/Config/Database.php` and `app/Config/App.php` read plain environment
variables first, so the same code runs locally and on Vercel.

| Variable | Used for |
|----------|----------|
| `DB_HOST` | When set, the app uses the variables below instead of `database.default.*`. |
| `DB_PORT` | TiDB uses `4000`. |
| `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` | Database login and schema. |
| `DB_SSL` | Set to `true` for TiDB. |
| `DB_SSL_CA` | Certificate bundle path. Default `/etc/pki/tls/certs/ca-bundle.crt` (Vercel). |
| `APP_BASE_URL` | Optional. Otherwise `https://` + `VERCEL_PROJECT_PRODUCTION_URL`. |
| `CI_ENVIRONMENT` | `production` on Vercel. |

## Deployment on Vercel

The project deploys from GitHub. Pushing to `main` makes a production
deployment; any other branch gets a preview deployment.

- `vercel.json` uses the `vercel-php@0.7.3` runtime (PHP 8.3) in region `sin1`,
  close to the TiDB cluster, with a 60-second limit.
- `api/index.php` is the entry point. It points CodeIgniter's writable folder
  at `/tmp` and takes the visitor's IP from `x-real-ip`, which login throttling
  relies on.
- Files under `/assets/` are served straight from `public/assets/` and cached
  for one day.
- Sessions are stored in the database (`ci_sessions`), because each function
  instance has its own `/tmp`.

Set the `DB_*` variables in **Vercel → Project → Settings → Environment
Variables** for Production and Preview. Vercel does not show their values
again after saving.

### Database changes after a deployment

After a release that adds a migration, sign in as an admin and open
**Settings**. The **Database updates** panel lists any pending updates;
press **Apply Database Updates** to run the pending
migrations. From a computer you can run `php spark migrate --all` instead,
using the connection described in [Loading data](data-loading.md#connecting-to-the-live-database).

## Running the tests

The unit tests run without a database. The feature tests (login security, loan
rules, super-admin rights) need an empty MySQL or MariaDB database that the
tests may wipe. Never point them at real data.

Add to `.env`:

```ini
database.tests.hostname = localhost
database.tests.database = glc_test
database.tests.username = root
database.tests.password =
database.tests.DBDriver = MySQLi
```

Then run:

```bash
vendor/bin/phpunit
```

Without a test database the feature tests are skipped locally. In GitHub
Actions (`.github/workflows/phpunit.yml`) a MySQL 8 service is provided, and
the tests fail instead of being skipped if it is missing. The workflow runs on
PHP 8.2 and 8.3 for every push and pull request.
