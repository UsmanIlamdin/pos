-- Customer Accounts / Consolidated Invoices (OSPOS receivables layer)
-- Does not alter historical sales or sales_payments.

CREATE TABLE IF NOT EXISTS `ospos_consolidated_invoices` (
  `consolidated_invoice_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(10) NOT NULL,
  `invoice_number` VARCHAR(32) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT(4) NOT NULL DEFAULT 1,
  `comment` TEXT NULL,
  `employee_id` INT(10) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`consolidated_invoice_id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `customer_id` (`customer_id`),
  KEY `employee_id` (`employee_id`),
  KEY `status` (`status`),
  KEY `invoice_date` (`invoice_date`),
  CONSTRAINT `ospos_consolidated_invoices_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `ospos_customers` (`person_id`),
  CONSTRAINT `ospos_consolidated_invoices_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `ospos_employees` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_consolidated_invoice_sales` (
  `id` INT(10) NOT NULL AUTO_INCREMENT,
  `consolidated_invoice_id` INT(10) NOT NULL,
  `sale_id` INT(10) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sale_id` (`sale_id`),
  KEY `consolidated_invoice_id` (`consolidated_invoice_id`),
  CONSTRAINT `ospos_consolidated_invoice_sales_ibfk_1` FOREIGN KEY (`consolidated_invoice_id`) REFERENCES `ospos_consolidated_invoices` (`consolidated_invoice_id`) ON DELETE CASCADE,
  CONSTRAINT `ospos_consolidated_invoice_sales_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `ospos_sales` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_account_payments` (
  `payment_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(10) NOT NULL,
  `consolidated_invoice_id` INT(10) NULL,
  `payment_type` VARCHAR(40) NOT NULL,
  `payment_amount` DECIMAL(15,2) NOT NULL,
  `payment_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_code` VARCHAR(40) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NULL,
  `employee_id` INT(10) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `customer_id` (`customer_id`),
  KEY `consolidated_invoice_id` (`consolidated_invoice_id`),
  KEY `payment_time` (`payment_time`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `ospos_customer_account_payments_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `ospos_customers` (`person_id`),
  CONSTRAINT `ospos_customer_account_payments_ibfk_2` FOREIGN KEY (`consolidated_invoice_id`) REFERENCES `ospos_consolidated_invoices` (`consolidated_invoice_id`) ON DELETE SET NULL,
  CONSTRAINT `ospos_customer_account_payments_ibfk_3` FOREIGN KEY (`employee_id`) REFERENCES `ospos_employees` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ospos_customer_payment_allocations` (
  `allocation_id` INT(10) NOT NULL AUTO_INCREMENT,
  `customer_account_payment_id` INT(10) NOT NULL,
  `sale_id` INT(10) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allocation_id`),
  KEY `customer_account_payment_id` (`customer_account_payment_id`),
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `ospos_customer_payment_allocations_ibfk_1` FOREIGN KEY (`customer_account_payment_id`) REFERENCES `ospos_customer_account_payments` (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `ospos_customer_payment_allocations_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `ospos_sales` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `ospos_app_config` (`key`, `value`) VALUES
 ('last_used_consolidated_invoice_number', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO `ospos_modules` (`name_lang_key`, `desc_lang_key`, `sort`, `module_id`) VALUES
 ('module_accounts', 'module_accounts_desc', 75, 'accounts');

INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES
 ('accounts', 'accounts'),
 ('accounts_payments', 'accounts'),
 ('accounts_consolidated', 'accounts'),
 ('accounts_cancel', 'accounts');

INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES
 ('reports_accounts', 'reports');

INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES
 ('accounts', 1, 'office'),
 ('accounts_payments', 1, '--'),
 ('accounts_consolidated', 1, '--'),
 ('accounts_cancel', 1, '--'),
 ('reports_accounts', 1, '--');
