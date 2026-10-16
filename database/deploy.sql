-- GLC-BLOC deploy schema + starter data (generated from app/Database/Migrations and GlcBlocSeeder).
-- Import into an EMPTY MySQL database. Default logins: admin / Admin@123, jdoe / Member@123
SET FOREIGN_KEY_CHECKS=0;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_requests` (
  `request_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `account_type` enum('Pioneer','Basic') NOT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `account_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `member_id_status` (`member_id`,`status`),
  CONSTRAINT `fk_account_requests_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `analytics_snapshots` (
  `snapshot_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `period_type` enum('Monthly','Quarterly','Annual') NOT NULL,
  `totals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`totals`)),
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`snapshot_id`),
  KEY `snapshot_date` (`snapshot_date`),
  KEY `period_type` (`period_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `analytics_snapshots` VALUES
(1,'2026-01-31','Monthly','{\"total_contributions\":300,\"total_loans\":0,\"total_dividends\":0,\"active_members\":1,\"active_accounts\":1}','2026-10-16 18:13:31','2026-10-16 18:13:31');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `blockchain_ledger` (
  `ledger_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `financial_record_id` int(10) unsigned NOT NULL,
  `transaction_hash` varchar(64) NOT NULL,
  `previous_hash` varchar(64) DEFAULT NULL,
  `ledger_sequence` bigint(20) unsigned NOT NULL,
  `recorded_at` datetime NOT NULL,
  `verification_status` enum('Valid','Tampered') NOT NULL DEFAULT 'Valid',
  PRIMARY KEY (`ledger_id`),
  UNIQUE KEY `ledger_sequence` (`ledger_sequence`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `financial_record_id` (`financial_record_id`),
  CONSTRAINT `fk_blockchain_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `blockchain_ledger` VALUES
(1,1,'23a169db3e8188135645b2466f0b55dfd52863354b1a0da833531a208a70ddf7',NULL,1,'2026-10-16 18:13:31','Valid');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ci_sessions` (
  `id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` blob NOT NULL,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contributions` (
  `contribution_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `contribution_period` varchar(7) NOT NULL COMMENT 'YYYY-MM',
  `amount` decimal(10,2) NOT NULL DEFAULT 300.00,
  `payment_date` date DEFAULT NULL,
  `payment_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `financial_record_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`contribution_id`),
  KEY `account_id` (`account_id`),
  KEY `payment_status` (`payment_status`),
  KEY `fk_contributions_financial_record` (`financial_record_id`),
  CONSTRAINT `fk_contributions_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_contributions_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `contributions` VALUES
(1,1,'2026-01',300.00,'2026-01-05','Approved',1,'2026-10-16 18:13:31','2026-10-16 18:13:31');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cooperative_accounts` (
  `account_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `account_number` varchar(30) NOT NULL,
  `account_type` enum('Pioneer','Basic') NOT NULL,
  `account_start_date` date NOT NULL,
  `monthly_contribution` decimal(10,2) NOT NULL DEFAULT 300.00,
  `account_status` enum('Active','Inactive','Closed','Pending') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `account_number` (`account_number`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `fk_coop_accounts_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `cooperative_accounts` VALUES
(1,1,'ACC-2026-001','Pioneer','2026-01-01',300.00,'Active');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividends` (
  `dividend_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `distribution_year` year(4) NOT NULL,
  `profit_pool` decimal(15,2) NOT NULL DEFAULT 0.00,
  `eligible_account_count` int(10) unsigned NOT NULL DEFAULT 0,
  `dividend_per_account` decimal(15,2) NOT NULL DEFAULT 0.00,
  `dividend_amount` decimal(15,2) NOT NULL,
  `distribution_date` date DEFAULT NULL,
  `dividend_status` enum('Pending','Approved','Distributed') NOT NULL DEFAULT 'Pending',
  `financial_record_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`dividend_id`),
  KEY `account_id` (`account_id`),
  KEY `dividend_status` (`dividend_status`),
  KEY `distribution_year` (`distribution_year`),
  KEY `fk_dividends_financial_record` (`financial_record_id`),
  CONSTRAINT `fk_dividends_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_dividends_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_records` (
  `financial_record_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `record_type` enum('Contribution','Loan Disbursement','Loan Payment','Dividend') NOT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `approval_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `processed_by` int(10) unsigned DEFAULT NULL,
  `first_approved_by` int(10) unsigned DEFAULT NULL,
  `first_approval_date` datetime DEFAULT NULL,
  `second_approved_by` int(10) unsigned DEFAULT NULL,
  `second_approval_date` datetime DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`financial_record_id`),
  KEY `account_id` (`account_id`),
  KEY `approval_status` (`approval_status`),
  KEY `record_type` (`record_type`),
  KEY `fk_financial_records_processed_by` (`processed_by`),
  KEY `fk_financial_records_first_approved_by` (`first_approved_by`),
  KEY `fk_financial_records_second_approved_by` (`second_approved_by`),
  CONSTRAINT `fk_financial_records_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_financial_records_first_approved_by` FOREIGN KEY (`first_approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_financial_records_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_financial_records_second_approved_by` FOREIGN KEY (`second_approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `financial_records` VALUES
(1,1,'Contribution','2026-01-05',300.00,'Approved',1,1,'2026-01-06 09:00:00',1,'2026-01-06 10:00:00',NULL,'2026-10-16 18:13:31','2026-10-16 18:13:31');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_installments` (
  `installment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` int(10) unsigned NOT NULL,
  `installment_number` int(10) unsigned NOT NULL,
  `due_date` date NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `amount_due` decimal(15,2) NOT NULL,
  `carried_over` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remaining_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_status` enum('Upcoming','Due','Late','Paid','Partial') NOT NULL DEFAULT 'Upcoming',
  `financial_record_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`installment_id`),
  KEY `loan_id` (`loan_id`),
  KEY `payment_status` (`payment_status`),
  KEY `due_date` (`due_date`),
  KEY `fk_loan_installments_financial_record` (`financial_record_id`),
  CONSTRAINT `fk_loan_installments_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loan_installments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_payment_installments` (
  `payment_installment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int(10) unsigned NOT NULL,
  `installment_id` int(10) unsigned NOT NULL,
  `amount_applied` decimal(15,2) NOT NULL,
  PRIMARY KEY (`payment_installment_id`),
  KEY `payment_id` (`payment_id`),
  KEY `installment_id` (`installment_id`),
  CONSTRAINT `fk_lpi_installment` FOREIGN KEY (`installment_id`) REFERENCES `loan_installments` (`installment_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lpi_payment` FOREIGN KEY (`payment_id`) REFERENCES `loan_payments` (`payment_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_payments` (
  `payment_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` int(10) unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_method` varchar(30) DEFAULT NULL,
  `principal_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `interest_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `financial_record_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `loan_id` (`loan_id`),
  KEY `payment_status` (`payment_status`),
  KEY `fk_loan_payments_financial_record` (`financial_record_id`),
  CONSTRAINT `fk_loan_payments_financial_record` FOREIGN KEY (`financial_record_id`) REFERENCES `financial_records` (`financial_record_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loan_payments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `loan_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `total_contributions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `maximum_loan_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `loan_amount` decimal(15,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `loan_term_months` int(10) unsigned NOT NULL,
  `application_date` date NOT NULL,
  `approval_date` date DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `loan_status` enum('Pending','Approved','Active','Paid','Rejected','Defaulted') NOT NULL DEFAULT 'Pending',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`loan_id`),
  KEY `account_id` (`account_id`),
  KEY `loan_status` (`loan_status`),
  KEY `fk_loans_approved_by` (`approved_by`),
  CONSTRAINT `fk_loans_account` FOREIGN KEY (`account_id`) REFERENCES `cooperative_accounts` (`account_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_loans_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `member_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `member_number` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `membership_date` date NOT NULL,
  `membership_status` enum('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `member_number` (`member_number`),
  CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `members` VALUES
(1,2,'MEM-001','John','Doe','2026-01-01','Active');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `migrations` VALUES
(1,'2026-10-12-000001','App\\Database\\Migrations\\CreateGlcBlocSchema','default','App',1792145611,1),
(2,'2026-10-12-000002','App\\Database\\Migrations\\CreateCiSessions','default','App',1792145611,1),
(3,'2026-10-14-000003','App\\Database\\Migrations\\RemoveLoanPenalties','default','App',1792145611,1),
(4,'2026-10-14-000004','App\\Database\\Migrations\\AccountJoiningAndPaymentMethod','default','App',1792145611,1),
(5,'2026-10-14-000005','App\\Database\\Migrations\\InstallmentBalances','default','App',1792145611,1),
(6,'2026-10-14-000006','App\\Database\\Migrations\\EnsureSchema','default','App',1792145611,1),
(7,'2026-10-16-000007','App\\Database\\Migrations\\InstallmentCarryOver','default','App',1792145611,1),
(8,'2026-10-16-000008','App\\Database\\Migrations\\CreateAccountRequests','default','App',1792145611,1);
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `role_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `roles` VALUES
(1,'Admin'),
(2,'Member');
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status` enum('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `users` VALUES
(1,1,'admin','admin@glcbloc.com','$2y$10$HPAjJ5bGmFqfjlMnwu0gSOGtVObH3LY3zO9MIEYE4tl8/L17a5axG','Active'),
(2,2,'jdoe','jdoe@example.com','$2y$10$dZ1bGmTOZ9KTNA9IC5pIreG8tGbOlQTSBsAJkp9hGb9Glq102zBu6','Active');
SET FOREIGN_KEY_CHECKS=1;
