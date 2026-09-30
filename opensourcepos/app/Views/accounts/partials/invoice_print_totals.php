<?php
/**
 * Print/PDF consolidated-invoice totals block.
 *
 * Expects parent table column layout where the last two cells are label + value.
 *
 * @var array $summary
 * @var string $invoice_number
 * @var int $label_colspan  Number of columns spanning the label cell (usually 1 or 2)
 * @var int $blank_colspan  Leading blank columns before the label
 */

$invoiceNumber = $invoice_number ?? ($summary['invoice_number'] ?? 'CI');
$hasReturns = ((float) ($summary['returns_total'] ?? 0) != 0.0);
$previouslyPaid = (float) ($summary['previously_paid_on_sales'] ?? 0);
$ciPaid = (float) ($summary['payments_applied_to_ci'] ?? $summary['payments_applied'] ?? 0);
$ciPaidLabel = lang('Accounts.payments_applied_to_ci', [$invoiceNumber !== '' ? $invoiceNumber : 'CI']);
$labelColspan = (int) ($label_colspan ?? 1);
$blankColspan = (int) ($blank_colspan ?? 0);
$labelAttr = $labelColspan > 1 ? ' colspan="' . $labelColspan . '"' : '';
?>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank-bottom"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= lang('Accounts.original_sales_total') ?></td>
    <td class="total-value"><?= to_currency($summary['original_sales_total'] ?? 0) ?></td>
</tr>
<?php if ($hasReturns): ?>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= lang('Accounts.returns_total') ?></td>
    <td class="total-value"><?= to_currency($summary['returns_total'] ?? 0) ?></td>
</tr>
<?php endif; ?>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= lang('Accounts.net_invoice_amount') ?></td>
    <td class="total-value"><?= to_currency($summary['net_invoice_amount'] ?? 0) ?></td>
</tr>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= lang('Accounts.previously_paid_on_sales') ?></td>
    <td class="total-value"><?= to_currency($previouslyPaid > 0 ? -$previouslyPaid : 0) ?></td>
</tr>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= esc($ciPaidLabel) ?></td>
    <td class="total-value"><?= to_currency($ciPaid) ?></td>
</tr>
<tr>
    <?php if ($blankColspan > 0): ?><td colspan="<?= $blankColspan ?>" class="blank"> </td><?php endif; ?>
    <td<?= $labelAttr ?> class="total-line"><?= lang('Accounts.balance_due') ?></td>
    <td class="total-value"><?= to_currency($summary['balance_due'] ?? 0) ?></td>
</tr>
