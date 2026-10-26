-- Consolidated invoice void metadata + allow sales to reappear on a replacement CI
-- while preserving historical consolidated_invoice_sales rows for voided invoices.
-- MySQL requires dropping the sale_id FK before converting UNIQUE(sale_id) to a plain KEY.

ALTER TABLE `ospos_consolidated_invoices` ADD COLUMN `voided_at` TIMESTAMP NULL DEFAULT NULL AFTER `employee_id`;
ALTER TABLE `ospos_consolidated_invoices` ADD COLUMN `voided_by` INT(10) NULL DEFAULT NULL AFTER `voided_at`;
ALTER TABLE `ospos_consolidated_invoices` ADD COLUMN `void_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `voided_by`;
ALTER TABLE `ospos_consolidated_invoices` ADD KEY `voided_by` (`voided_by`);

ALTER TABLE `ospos_consolidated_invoice_sales` DROP FOREIGN KEY `ospos_consolidated_invoice_sales_ibfk_2`;
ALTER TABLE `ospos_consolidated_invoice_sales` DROP INDEX `sale_id`;
ALTER TABLE `ospos_consolidated_invoice_sales` ADD KEY `sale_id` (`sale_id`);
ALTER TABLE `ospos_consolidated_invoice_sales`
  ADD CONSTRAINT `ospos_consolidated_invoice_sales_ibfk_2`
  FOREIGN KEY (`sale_id`) REFERENCES `ospos_sales` (`sale_id`);
