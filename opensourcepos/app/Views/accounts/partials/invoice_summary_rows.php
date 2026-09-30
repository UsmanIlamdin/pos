<?php
/**
 * Shared consolidated-invoice financial summary rows.
 *
 * @var array $summary
 * @var string|null $invoice_number
 */

$invoiceNumber = $invoice_number ?? ($summary['invoice_number'] ?? '');
$hasReturns = ((float) ($summary['returns_total'] ?? 0) != 0.0);
$previouslyPaid = (float) ($summary['previously_paid_on_sales'] ?? 0);
$ciPaid = (float) ($summary['payments_applied_to_ci'] ?? $summary['payments_applied'] ?? 0);
$ciPaidLabel = lang('Accounts.payments_applied_to_ci', [$invoiceNumber !== '' ? $invoiceNumber : 'CI']);
?>
<tr>
    <td><?= lang('Accounts.original_sales_total') ?></td>
    <td class="text-right"><?= to_currency($summary['original_sales_total'] ?? 0) ?></td>
</tr>
<?php if ($hasReturns): ?>
<tr>
    <td><?= lang('Accounts.returns_total') ?></td>
    <td class="text-right"><?= to_currency($summary['returns_total'] ?? 0) ?></td>
</tr>
<?php endif; ?>
<tr>
    <td><?= lang('Accounts.net_invoice_amount') ?></td>
    <td class="text-right"><?= to_currency($summary['net_invoice_amount'] ?? 0) ?></td>
</tr>
<tr>
    <td><?= lang('Accounts.previously_paid_on_sales') ?></td>
    <td class="text-right"><?= to_currency($previouslyPaid > 0 ? -$previouslyPaid : 0) ?></td>
</tr>
<tr>
    <td><?= esc($ciPaidLabel) ?></td>
    <td class="text-right"><?= to_currency($ciPaid) ?></td>
</tr>
<tr>
    <td><strong><?= lang('Accounts.balance_due') ?></strong></td>
    <td class="text-right"><strong><?= to_currency($summary['balance_due'] ?? 0) ?></strong></td>
</tr>
