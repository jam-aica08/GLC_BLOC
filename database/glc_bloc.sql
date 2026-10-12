-- GLC-BLOC Database Schema
-- Guyo & Liding Cooperative
-- Version: 2.0 (System Review Implemented)
-- Generated: 2026-10-12
--
-- Changes from v1:
--   Fix 4.4: Added first_approved_by, first_approval_date, second_approved_by, second_approval_date to financial_records
--   Fix 4.5: blockchain_ledger.verification_status ENUM is 'Valid','Tampered' (was inconsistent)
--   Fix 4.8: financial_records -> cooperative_accounts FK uses RESTRICT (was CASCADE)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- Database: `glc_bloc`

-- --------------------------------------------------------
-- Table: roles
-- --------------------------------------------------------
CREATE TABLE `roles` (
  `role_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'Admin'),
(2, 'Member');

-- --------------------------------------------------------
-- Table: users
-- --------------------------------------------------------
CREATE TABLE `users` (
  `user_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` int(11) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default admin: password is Admin@123
INSERT INTO `users` (`user_id`, `role_id`, `username`, `email`, `password_hash`, `account_status`) VALUES
(1, 1, 'admin', 'admin@glcbloc.com', '$2y$10$QE38MSyN62U2tC5Lx4HNZOUKmwUqYCipEa7bR.W7cPC9r8JV2TSE6', 'Active'),
(2, 1, 'admin2', 'admin2@glcbloc.com', '$2y$10$eje8upnXzrOgaj6XTBEbWe1/8FtXEzMChcwMx.dRZCweaR0KP2jTi', 'Active'),
(3, 2, 'jamjam', 'jamjam@glc.bloc', '$2y$10$EdSZLZrBscXzs457Qv5dkOjxck98T0HBQmD2kci9FSoDnrjCDmNyi', 'Active'),
(4, 2, 'rona', 'rona@glcbloc.com', '$2y$10$NL5mmTQcwPN9LlMGoV3r1.LgHVZYBGsNRRZt7yXIyDdf.Lj3MsgZG', 'Active'),
(5, 2, 'Angel', 'angel@glcbloc.com', '$2y$10$GKvquusXVTMbKvO6jVrjNupjs/w82Eru.Ezp5zkftLSZeRborcJQW', 'Active'),
(6, 2, 'Shane', 'shane@glcbloc.com', '$2y$10$gXDUym09cr1HgUxPyiWAi.q4gAYuLgjHv4g4Y2xwChqq1wo0JAo2q', 'Active');

-- --------------------------------------------------------
-- Table: members
-- --------------------------------------------------------
CREATE TABLE `members` (
  `member_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED NOT NULL,
  `member_number` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `membership_date` date NOT NULL,
  `membership_status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `member_number` (`member_number`),
  CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `members` (`member_id`, `user_id`, `member_number`, `first_name`, `last_name`, `membership_date`, `membership_status`) VALUES
(1, 3, 'MEM-001', 'Jamaica Rose', 'Rebatis', '2026-09-30', 'Active'),
(2, 4, 'MEM-002', 'Rona', 'Mejia', '2026-09-30', 'Active'),
(3, 5, 'MEM-003', 'Angel', 'Grampil', '2026-09-30', 'Active'),
(4, 6, 'MEM-004', 'Shane', 'Fermindoza', '2026-09-30', 'Active');

-- --------------------------------------------------------
-- Table: cooperative_accounts
-- --------------------------------------------------------
CREATE TABLE `cooperative_accounts` (
  `account_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(11) UNSIGNED NOT NULL,
  `account_number` varchar(20) NOT NULL,
  `account_type` enum('Pioneer','Basic') DEFAULT 'Basic',
  `account_start_date` date NOT NULL,
  `monthly_contribution` decimal(10,2) DEFAULT 300.00,
  `account_status` enum('Active','Inactive','Closed') DEFAULT 'Active',
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `account_number` (`account_number`),
  KEY `fk_accounts_member` (`member_id`),
  CONSTRAINT `fk_accounts_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `cooperative_accounts` (`account_id`, `member_id`, `account_number`, `account_type`, `account_start_date`, `monthly_contribution`, `account_status`) VALUES
(1, 4, 'ACC-001', 'Pioneer', '2026-09-30', 300.00, 'Active'),
(2, 4, 'ACC-002', 'Pioneer', '2026-09-30', 300.00, 'Active'),
(3, 1, 'ACC-003', 'Basic', '2026-09-30', 300.00, 'Active'),
(4, 4, 'ACC-004', 'Basic', '2026-09-30', 300.00, 'Active'),
(5, 3, 'ACC-005', 'Pioneer', '2026-10-06', 300.00, 'Active'),
(6, 3, 'ACC-006', 'Pioneer', '2026-10-06', 300.00, 'Active'),
(7, 3, 'ACC-007', 'Basic', '2026-10-06', 300.00, 'Active'),
(8, 3, 'ACC-008', 'Pioneer', '2026-10-06', 300.00, 'Active'),
(9, 3, 'ACC-009', 'Basic', '2026-10-07', 300.00, 'Active'),
(10, 2, 'ACC-010', 'Basic', '2026-10-07', 300.00, 'Active');

-- --------------------------------------------------------
-- Table: financial_records
-- FIX 4.4: Includes dual-approval columns
-- FIX 4.8: FK to cooperative_accounts uses RESTRICT
-- --------------------------------------------------------
CREATE TABLE `financial_records` (
  `financial_record_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(11) UNSIGNED NOT NULL,
  `record_type` enum('Contribution','Loan Disbursement','Loan Payment','Dividend') NOT NULL,
  `transaction_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `amount` decimal(12,2) NOT NULL,
  `approval_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `processed_by` int(11) UNSIGNED DEFAULT NULL,
  `first_approved_by` int(11) UNSIGNED DEFAULT NULL,
  `first_approval_date` datetime DEFAULT NULL,
  `second_approved_by` int(11) UNSIGNED DEFAULT NULL,
  `second_approval_date` datetime DEFAULT NULL,
  `reference_id` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`financial_record_id`),
  KEY `fk_financial_account` (`account_id`),
  KEY `fk_financial_processor` (`processed_by`),
  KEY `fk_financial_first_approver` (`first_approved_by`),
  KEY `fk_financial_second_approver` (`second_approved_by`),
  CONSTRAINT `fk_financial_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_financial_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_financial_first_approver` FOREIGN KEY (`first_approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_financial_second_approver` FOREIGN KEY (`second_approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `financial_records` (`financial_record_id`, `account_id`, `record_type`, `transaction_date`, `amount`, `approval_status`, `processed_by`, `first_approved_by`, `first_approval_date`, `second_approved_by`, `second_approval_date`, `reference_id`) VALUES
(1, 1, 'Contribution', '2026-10-31 00:00:00', 300.00, 'Pending', 1, NULL, NULL, NULL, NULL, 4),
(2, 1, 'Contribution', '2026-09-30 00:00:00', 300.00, 'Pending', 1, NULL, NULL, NULL, NULL, 1),
(3, 2, 'Contribution', '2026-09-30 00:00:00', 300.00, 'Pending', 1, NULL, NULL, NULL, NULL, 2),
(4, 4, 'Contribution', '2026-09-30 00:00:00', 300.00, 'Approved', 1, 1, '2026-09-30 15:18:23', 2, '2026-09-30 15:27:05', 3),
(5, 5, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 5),
(6, 6, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 6),
(7, 7, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 7),
(8, 8, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 8),
(13, 6, 'Loan Payment', '2026-10-06 18:21:20', 229.50, 'Approved', 2, 2, '2026-10-06 18:22:41', 1, '2026-10-06 18:23:02', 1),
(14, 6, 'Loan Payment', '2026-10-06 18:37:51', 229.50, 'Approved', 2, 2, '2026-10-06 18:56:48', 1, '2026-10-06 18:58:16', 2),
(15, 4, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 9),
(16, 10, 'Contribution', '2026-10-07 00:00:00', 300.00, 'Pending', 2, NULL, NULL, NULL, NULL, 10),
(17, 10, 'Loan Payment', '2026-10-06 19:24:48', 315.00, 'Approved', 2, 2, '2026-10-06 19:25:00', 1, '2026-10-06 19:25:38', 3);

-- --------------------------------------------------------
-- Table: contributions
-- --------------------------------------------------------
CREATE TABLE `contributions` (
  `contribution_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(11) UNSIGNED NOT NULL,
  `contribution_period` date NOT NULL,
  `amount` decimal(10,2) DEFAULT 300.00,
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `payment_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `financial_record_id` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`contribution_id`),
  KEY `fk_contributions_account` (`account_id`),
  KEY `fk_contributions_financial` (`financial_record_id`),
  CONSTRAINT `fk_contributions_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_contributions_financial` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `contributions` (`contribution_id`, `account_id`, `contribution_period`, `amount`, `payment_date`, `payment_status`, `financial_record_id`) VALUES
(1, 1, '2026-09-30', 300.00, '2026-09-30 00:00:00', 'Approved', NULL),
(2, 2, '2026-09-30', 300.00, '2026-09-30 00:00:00', 'Approved', NULL),
(3, 4, '2026-09-30', 300.00, '2026-09-30 00:00:00', 'Approved', NULL),
(4, 1, '2026-10-31', 300.00, '2026-10-31 00:00:00', 'Approved', NULL),
(5, 5, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(6, 6, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(7, 7, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(8, 8, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(9, 4, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(10, 10, '2026-10-07', 300.00, '2026-10-07 00:00:00', 'Approved', NULL),
(11, 6, '2026-10-12', 300.00, '2026-10-12 00:00:00', 'Pending', NULL);

-- --------------------------------------------------------
-- Table: loans
-- --------------------------------------------------------
CREATE TABLE `loans` (
  `loan_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(11) UNSIGNED NOT NULL,
  `total_contributions` decimal(12,2) NOT NULL,
  `maximum_loan_limit` decimal(12,2) NOT NULL,
  `loan_amount` decimal(12,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `interest_amount` decimal(10,2) NOT NULL,
  `loan_term_months` tinyint(4) NOT NULL,
  `application_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `approval_date` datetime DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `loan_status` enum('Pending','Approved','Active','Paid','Rejected','Defaulted') DEFAULT 'Pending',
  `approved_by` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`loan_id`),
  KEY `fk_loans_account` (`account_id`),
  KEY `fk_loans_approver` (`approved_by`),
  CONSTRAINT `fk_loans_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_loans_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `loans` (`loan_id`, `account_id`, `total_contributions`, `maximum_loan_limit`, `loan_amount`, `interest_rate`, `interest_amount`, `loan_term_months`, `application_date`, `approval_date`, `release_date`, `loan_status`, `approved_by`) VALUES
(1, 1, 300.00, 600.00, 500.00, 5.00, 25.00, 3, '2026-09-29 00:00:00', '2026-09-30 00:00:00', NULL, 'Rejected', 1),
(2, 4, 300.00, 600.00, 400.00, 5.00, 20.00, 6, '2026-09-29 00:00:00', '2026-09-29 00:00:00', NULL, 'Rejected', 1),
(3, 2, 300.00, 600.00, 500.00, 2.00, 10.00, 2, '2026-09-29 00:00:00', '2026-09-30 00:00:00', '2026-09-30', 'Approved', 1),
(4, 4, 300.00, 600.00, 500.00, 5.00, 25.00, 5, '2026-09-30 00:00:00', '2026-09-30 00:00:00', '2026-09-30', 'Approved', 2),
(5, 5, 300.00, 600.00, 400.00, 2.00, 8.00, 1, '2026-10-06 00:00:00', NULL, NULL, 'Pending', NULL),
(6, 6, 300.00, 600.00, 450.00, 2.00, 9.00, 4, '2026-10-06 00:00:00', '2026-10-06 00:00:00', '2026-10-07', 'Approved', 2),
(7, 10, 300.00, 600.00, 600.00, 5.00, 30.00, 6, '2026-10-06 00:00:00', '2026-10-07 00:00:00', '2026-10-07', 'Approved', 2);

-- --------------------------------------------------------
-- Table: loan_installments
-- --------------------------------------------------------
CREATE TABLE `loan_installments` (
  `installment_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) UNSIGNED NOT NULL,
  `installment_number` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `principal_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `interest_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `penalty_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_date` datetime DEFAULT NULL,
  `payment_status` enum('Upcoming','Due','Late','Paid') NOT NULL DEFAULT 'Upcoming',
  `penalty_status` enum('None','Due','Paid') NOT NULL DEFAULT 'None',
  `financial_record_id` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`installment_id`),
  UNIQUE KEY `unique_loan_installment` (`loan_id`, `installment_number`),
  KEY `fk_installment_financial_record` (`financial_record_id`),
  CONSTRAINT `fk_installment_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_installment_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: loan_payments
-- --------------------------------------------------------
CREATE TABLE `loan_payments` (
  `payment_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) UNSIGNED NOT NULL,
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `amount_paid` decimal(10,2) NOT NULL,
  `principal_amount` decimal(10,2) NOT NULL,
  `interest_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `financial_record_id` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `fk_loan_payments_loan` (`loan_id`),
  KEY `fk_loan_payments_financial` (`financial_record_id`),
  CONSTRAINT `fk_loan_payments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_loan_payments_financial` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: loan_payment_installments
-- --------------------------------------------------------
CREATE TABLE `loan_payment_installments` (
  `payment_installment_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) UNSIGNED NOT NULL,
  `installment_id` int(11) UNSIGNED NOT NULL,
  `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`payment_installment_id`),
  UNIQUE KEY `unique_payment_installment` (`payment_id`, `installment_id`),
  CONSTRAINT `fk_payment_installment_payment` FOREIGN KEY (`payment_id`) REFERENCES `loan_payments` (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_installment_installment` FOREIGN KEY (`installment_id`) REFERENCES `loan_installments` (`installment_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: dividends
-- --------------------------------------------------------
CREATE TABLE `dividends` (
  `dividend_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(11) UNSIGNED NOT NULL,
  `distribution_year` year(4) NOT NULL,
  `profit_pool` decimal(12,2) NOT NULL,
  `eligible_account_count` int(11) NOT NULL,
  `dividend_per_account` decimal(10,2) NOT NULL,
  `dividend_amount` decimal(10,2) NOT NULL,
  `distribution_date` date NOT NULL,
  `dividend_status` enum('Pending','Approved','Distributed') DEFAULT 'Pending',
  `financial_record_id` int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`dividend_id`),
  KEY `fk_dividends_account` (`account_id`),
  KEY `fk_dividends_financial` (`financial_record_id`),
  CONSTRAINT `fk_dividends_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dividends_financial` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: blockchain_ledger
-- FIX 4.5: verification_status ENUM uses 'Valid','Tampered' (consistent with PHP code)
-- --------------------------------------------------------
CREATE TABLE `blockchain_ledger` (
  `ledger_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `financial_record_id` int(11) UNSIGNED NOT NULL,
  `transaction_hash` varchar(64) NOT NULL,
  `previous_hash` varchar(64) DEFAULT NULL,
  `ledger_sequence` bigint(20) UNSIGNED NOT NULL,
  `recorded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `verification_status` enum('Valid','Tampered') DEFAULT 'Valid',
  PRIMARY KEY (`ledger_id`),
  UNIQUE KEY `financial_record_id` (`financial_record_id`),
  UNIQUE KEY `ledger_sequence` (`ledger_sequence`),
  CONSTRAINT `fk_ledger_financial` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table: analytics_snapshots
-- --------------------------------------------------------
CREATE TABLE `analytics_snapshots` (
  `snapshot_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `period_type` enum('Monthly','Quarterly','Annual') NOT NULL,
  `totals` decimal(12,2) DEFAULT 0.00,
  PRIMARY KEY (`snapshot_id`),
  UNIQUE KEY `unique_snapshot` (`snapshot_date`, `period_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
