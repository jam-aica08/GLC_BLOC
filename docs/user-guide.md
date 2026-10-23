# User guide

The system works on phones, tablets and computers. On small screens the menu
opens from the button at the top left, and tables show each row as a card.

After 5 wrong passwords for one username (or 20 from one network) sign-in is
paused for 15 minutes.

## For members

### Getting started

A new account starts as **Pending**. It becomes **Active** once every month
from January up to the month the account was opened has been paid and
approved. The notification bell shows how much is still needed.

### Contributions

- The monthly contribution is **PHP 300**. One payment can cover 300 to 3,600
  (up to a year ahead).
- Submit a payment under **Contributions**. It counts once an admin approves
  it.

### Loans

- You can borrow up to **twice your approved contributions**, with one loan
  open at a time.
- Terms: 3, 6, 12, 18 or 24 months.
- Interest: **2%** for Pioneer accounts and **5%** for Basic accounts. It is
  added to the first instalment.
- After approval, **Loans** shows the monthly schedule. Payments go to the
  oldest unpaid month first. Anything unpaid after a due date carries over to
  the next month.

### Dividends

At the end of a year, the interest earned on loans approved that year is
shared among active accounts that were open by 31 December. Each Basic
account counts as one share and each Pioneer account as 1.02 shares. Your dividends are listed under **Dividends**.

### Ledger

**Ledger** lists every transaction on your account, in order, with its
verification status.

## For admins

### Daily work

- **Dashboard:** totals and anything waiting for you.
- **Approvals:** approve or reject contributions and account requests.
  Approving contributions can activate an account.
- **Loans:** approve or reject applications and record payments. Approval is
  refused if the account is no longer Active or the loan now exceeds the
  member's limit.
- **Members:** search, view, deactivate or reactivate members. A deactivated
  member cannot sign in.

### Reports

**Reports** has filters with date presets and tabs per report. Summary
cards compare with the previous period, and clicking a chart drills down to
the transactions behind it. The transaction list can be sorted, searched and
paged, and **Download CSV** exports every row.

### Dividends

Press **Distribute Dividends**, pick a year, and check the profit pool and
the share for each account. The distribution is created as a batch waiting
for approval; it is paid and added to the ledger only when an admin approves
it. A year can only be distributed once.

### Ledger and Financial records

Both are searchable and paged. **Run Integrity Verification** recomputes the hash chain;
any entry that was changed or removed outside the app shows as **Invalid**.

### Settings

- Every admin can change their own password and see today's recent errors.
- **Database updates** says whether the database matches the current
  release. If it does not, press **Apply Database Updates**; data is kept.

### Super admin only

- **Add admin:** create other admin accounts.
- **Import data:** load a client's records from Excel (see
  [Loading data](data-loading.md)).
- **Reset data:** delete all members and transactions. This cannot be undone.
