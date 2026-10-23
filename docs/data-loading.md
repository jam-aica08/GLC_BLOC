# Loading data

There are two ways to fill the system:

- **Demo data:** invented members for development, testing and the capstone
  presentation.
- **Client import:** a cooperative's real records, uploaded from an Excel file.

Both write through the same rules as normal use. Accounts are activated,
instalment schedules built and ledger entries chained exactly as if the
records had been entered by hand.

## Demo data

```bash
php spark demo:load                 # adds demo members to what is there
php spark demo:load --fresh         # deletes members and transactions first
php spark demo:load --members 60    # how many members to create (default 40)
php spark demo:load --file demo.xlsx   # only write the Excel file, change nothing
php spark demo:load --fresh --yes   # skip the confirmation prompt
```

Options take a space, not `=` (`--members 40`, not `--members=40`).

The demo data includes:

- Founding members who joined in **2022** with **Pioneer** accounts; members
  who joined later have Basic accounts.
- Monthly contributions, loans at every stage (pending, active, paid) and their
  payments.
- Dividends distributed for each finished year from 2022 onwards.

`--fresh` keeps the administrator logins. It removes all members, their
accounts and logins, and every contribution, loan, payment, dividend and
ledger entry.

The same data is available as an Excel file for the import screen (**Import data → Filled-in sample**), or with `--file`.

## Importing a client's records

Only the **super admin** can import. Admins without super-admin rights do not
see the menu item.

1. Open **Data → Import data** and download **Template (.xlsx)**. The workbook
   has one sheet per record type and an Instructions sheet. Columns marked `*`
   are required.
2. Fill in the sheets. The reference columns (`member_ref`, `account_ref`,
   `loan_ref`) are your own IDs; they only link rows within the file.

   | Sheet | One row per |
   |-------|-------------|
   | Members | person; creates a member login |
   | Accounts | savings account, Basic or Pioneer |
   | Contributions | monthly payment (`period` as `YYYY-MM`, PHP 300–3,600) |
   | Loans | released loan (terms 3, 6, 12, 18 or 24 months) |
   | Loan Payments | repayment, applied to the oldest unpaid month first |

3. Upload the file (4 MB at most). The system first checks every row. If any
   row is wrong, **nothing is saved** and you get a list of problems by sheet,
   row and column. Fix them and upload again.
4. When the file is clean, everything is saved in one go. Members who had no
   password in the file get a generated one, shown once after the import.
   Copy the list and hand the passwords out privately.

After importing, open **Dividends** to distribute and approve any past
years. Imports do not distribute dividends automatically.

## Connecting to the live database

To run `demo:load` or migrations against TiDB from your computer:

1. Copy the connection details from **TiDB Cloud → your cluster → Connect**
   into a git-ignored file named `.env.tidb` in the project folder:

   ```ini
   DB_HOST="gateway01.ap-southeast-1.prod.aws.tidbcloud.com"
   DB_PORT="4000"
   DB_USERNAME="..."
   DB_PASSWORD="..."
   DB_DATABASE="..."
   DB_SSL="true"
   DB_SSL_CA="C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt"
   CI_ENVIRONMENT="production"
   ```

2. Load it into the shell and run the command (Git Bash):

   ```bash
   set -a; source .env.tidb; set +a
   php spark migrate:status
   php spark demo:load --fresh
   ```

3. Delete `.env.tidb` when you are done.

> `--fresh` against the live database deletes every real member and
> transaction. Use it only when the live site holds demo data.
