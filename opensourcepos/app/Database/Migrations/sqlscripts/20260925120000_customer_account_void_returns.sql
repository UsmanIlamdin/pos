-- Customer Account: void/reallocate, return credits, audit events, grants
-- Extends 20260922190000_customer_accounts without altering historical sales_payments.
-- Statements are intentionally separate so partial re-runs can continue.

ALTER TABLE `ospos_customer_account_payments` ADD COLUMN `status` TINYINT(4) NOT NULL DEFAULT 1 AFTER `employee_id`;
ALTER TABLE `ospos_customer_account_payments` ADD COLUMN `voided_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`;
ALTER TABLE `ospos_customer_account_payments` ADD COLUMN `voided_by` INT(10) NULL DEFAULT NULL AFTER `voided_at`;
ALTER TABLE `ospos_customer_account_payments` ADD COLUMN `void_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `voided_by`;
ALTER TABLE `ospos_customer_account_payments` ADD COLUMN `idempotency_key` VARCHAR(64) NULL DEFAULT NULL AFTER `void_reason`;
ALTER TABLE `ospos_customer_account_payments` ADD KEY `status` (`status`);
ALTER TABLE `ospos_customer_account_payments` ADD KEY `voided_by` (`voided_by`);
ALTER TABLE `ospos_customer_account_payments` ADD UNIQUE KEY `customer_idempotency` (`customer_id`, `idempotency_key`);

ALTER TABLE `ospos_customer_payment_allocations` ADD COLUMN `status` TINYINT(4) NOT NULL DEFAULT 1 AFTER `amount`;
ALTER TABLE `ospos_customer_payment_allocations` ADD KEY `status` (`status`);

ALTER TABLE `ospos_sales` ADD COLUMN `return_of_sale_id` INT(10) NULL DEFAULT NULL AFTER `sale_type`;
ALTER TABLE `ospos_sales` ADD KEY `return_of_sale_id` (`return_of_sale_id`);

CREATE TABLE IF NOT EXISTS `ospos_customer_return_credits` (
  `return_credit_id` INT(10) NOT NULL AUTO_INCREMENT,
  `return_sale_id` INT(10) NOT NULL,
  `customer_id` INT(10) NOT NULL,
  `original_sale_id` INT(10) NULL DEFAULT NULL,
  `return_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT(4) NOT NULL DEFAULT 1,
  `employee_id` INT(10) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `voided_at` TIMESTAMP NULL DEFAULT NULL,
  `voided_by` INT(10) NULL DEFAULT NULL,
  `void_reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`return_credit_id`),
  UNIQUE KEY `return_sale_id` (`return_sale_id`),
  KEY `customer_id` (`customer_id`),
  KEY `original_sale_id` (`original_sale_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_return_credit_allocations` (
  `allocation_id` INT(10) NOT NULL AUTO_INCREMENT,
  `return_credit_id` INT(10) NOT NULL,
  `sale_id` INT(10) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` TINYINT(4) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allocation_id`),
  KEY `return_credit_id` (`return_credit_id`),
  KEY `sale_id` (`sale_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_credit_applications` (
  `application_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(10) NOT NULL,
  `sale_id` INT(10) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `status` TINYINT(4) NOT NULL DEFAULT 1,
  `employee_id` INT(10) NOT NULL,
  `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
  `comment` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `voided_at` TIMESTAMP NULL DEFAULT NULL,
  `voided_by` INT(10) NULL DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  KEY `customer_id` (`customer_id`),
  KEY `sale_id` (`sale_id`),
  KEY `status` (`status`),
  UNIQUE KEY `customer_credit_app_idempotency` (`customer_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_credit_refunds` (
  `refund_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(10) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_type` VARCHAR(40) NOT NULL,
  `status` TINYINT(4) NOT NULL DEFAULT 1,
  `employee_id` INT(10) NOT NULL,
  `reference_code` VARCHAR(40) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
  `refund_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `voided_at` TIMESTAMP NULL DEFAULT NULL,
  `voided_by` INT(10) NULL DEFAULT NULL,
  PRIMARY KEY (`refund_id`),
  KEY `customer_id` (`customer_id`),
  KEY `status` (`status`),
  UNIQUE KEY `customer_credit_refund_idempotency` (`customer_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_account_audit_events` (
  `event_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(10) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL DEFAULT '',
  `entity_id` INT(10) NULL DEFAULT NULL,
  `payload` TEXT NULL,
  `employee_id` INT(10) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `customer_id` (`customer_id`),
  KEY `event_type` (`event_type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES
 ('accounts_void', 'accounts'),
 ('accounts_reallocate', 'accounts'),
 ('accounts_credit_apply', 'accounts'),
 ('accounts_refund', 'accounts')
ON DUPLICATE KEY UPDATE `module_id` = VALUES(`module_id`);

INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES
 ('accounts_void', 1, '--'),
 ('accounts_reallocate', 1, '--'),
 ('accounts_credit_apply', 1, '--'),
 ('accounts_refund', 1, '--')
ON DUPLICATE KEY UPDATE `menu_group` = VALUES(`menu_group`);
