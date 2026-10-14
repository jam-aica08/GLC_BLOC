-- One-time upgrade for a GLC-BLOC database created from an earlier deploy.sql.
-- Run once in the TiDB / MySQL SQL editor. Keeps all existing data.
USE glc_bloc;

-- 1. Remove late-payment penalties (never charged).
ALTER TABLE loan_installments DROP COLUMN penalty_amount, DROP COLUMN penalty_paid, DROP COLUMN penalty_status;
ALTER TABLE loan_payments DROP COLUMN penalty_amount;
ALTER TABLE loan_payment_installments DROP COLUMN penalty_amount;

-- 2. Accounts join after contributing from January; every account owes PHP 300 per month.
ALTER TABLE cooperative_accounts MODIFY account_status ENUM('Active','Inactive','Closed','Pending') NOT NULL DEFAULT 'Active';
UPDATE cooperative_accounts SET monthly_contribution = 300.00;

-- 3. Loan payments record how the member paid.
ALTER TABLE loan_payments ADD COLUMN payment_method VARCHAR(30) NULL AFTER amount_paid;

-- 4. Record the migrations as applied.
INSERT INTO migrations (version, class, `group`, namespace, time, batch) VALUES
('2026-10-14-000003', 'App\\Database\\Migrations\\RemoveLoanPenalties', 'default', 'App', UNIX_TIMESTAMP(), 2),
('2026-10-14-000004', 'App\\Database\\Migrations\\AccountJoiningAndPaymentMethod', 'default', 'App', UNIX_TIMESTAMP(), 2);
