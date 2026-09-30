-- Return / accounting / inventory integrity schema extensions.
-- Idempotent column adds (ignore errors on re-run via migration PHP).

ALTER TABLE `ospos_customer_return_credits`
  ADD COLUMN `settlement_mode` VARCHAR(20) NOT NULL DEFAULT 'outstanding' AFTER `return_amount`;

ALTER TABLE `ospos_customer_credit_refunds`
  ADD COLUMN `return_sale_id` INT(10) NULL DEFAULT NULL AFTER `customer_id`;

ALTER TABLE `ospos_customer_credit_refunds`
  ADD KEY `return_sale_id` (`return_sale_id`);

ALTER TABLE `ospos_sales_items`
  ADD COLUMN `source_line` INT(3) NULL DEFAULT NULL AFTER `line`;

ALTER TABLE `ospos_inventory`
  ADD COLUMN `sale_id` INT(10) NULL DEFAULT NULL AFTER `trans_id`;

ALTER TABLE `ospos_inventory`
  ADD KEY `sale_id` (`sale_id`);
