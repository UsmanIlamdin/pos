<?php
/**
 * Detailed consolidated invoice PDF — sale-invoice layout with line items.
 *
 * @var array $details
 * @var object $person_info
 * @var string $customer_info
 * @var string $company_info
 * @var array $status_labels
 * @var array $config
 * @var string $barcode
 * @var string $logo_path
 * @var string $logo_mime
 */

$invoice = $details['invoice'];
$summary = $details['summary'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?= current_language_code() ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($invoice['invoice_number']) ?></title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; margin: 24px; }
        #page-wrap { width: 100%; }
        #header {
            background: #222;
            color: #fff;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 2px;
            padding: 10px 0;
            margin-bottom: 18px;
        }
        table { width: 100%; border-collapse: collapse; }
        #block1 td { vertical-align: top; }
        #customer { white-space: pre-line; }
        #company-title { white-space: pre-line; width: 55%; float: left; }
        #meta { width: 40%; float: right; border-collapse: collapse; }
        #meta td { border: 1px solid #ccc; padding: 5px 8px; }
        .meta-head { background: #eee; font-weight: bold; width: 45%; }
        #items { margin-top: 18px; clear: both; }
        #items th, #items td { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
        #items th { background: #f3f3f3; text-align: left; }
        .group-row td { background: #f5f5f5; font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .blank { border: none !important; }
        .total-line { text-align: right; font-weight: bold; }
        .total-value { text-align: right; }
        #terms { margin-top: 24px; }
        #barcode { text-align: center; margin-top: 16px; }
    </style>
</head>
<body>
<div id="page-wrap">
    <div id="header"><?= lang('Accounts.consolidated_invoice') ?></div>

    <table id="block1">
        <tr>
            <td style="width: 55%;">
                <div id="customer"><?= nl2br(esc($customer_info)) ?></div>
            </td>
            <td style="width: 45%; text-align: right;">
                <?php if (!empty($logo_path) && is_file($logo_path)): ?>
                    <img src="data:<?= esc($logo_mime, 'attr') ?>;base64,<?= base64_encode((string) file_get_contents($logo_path)) ?>" alt="company_logo" style="max-width: 160px; max-height: 70px;">
                <?php endif; ?>
                <?php if (!empty($config['receipt_show_company_name'])): ?>
                    <div style="font-weight: bold; margin-top: 6px;"><?= esc($config['company'] ?? '') ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div style="margin-top: 14px; overflow: hidden;">
        <div id="company-title"><?= nl2br(esc($company_info)) ?></div>
        <table id="meta">
            <tr>
                <td class="meta-head"><?= lang('Accounts.consolidated_invoice_number') ?></td>
                <td><?= esc($invoice['invoice_number']) ?></td>
            </tr>
            <tr>
                <td class="meta-head"><?= lang('Accounts.invoice_date') ?></td>
                <td><?= esc($invoice['invoice_date']) ?></td>
            </tr>
            <?php if (!empty($invoice['due_date'])): ?>
                <tr>
                    <td class="meta-head"><?= lang('Accounts.due_date') ?></td>
                    <td><?= esc($invoice['due_date']) ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td class="meta-head"><?= lang('Accounts.status') ?></td>
                <td><?= esc($status_labels[(int) $invoice['status']] ?? '') ?></td>
            </tr>
            <tr>
                <td class="meta-head"><?= lang('Accounts.invoice_total') ?></td>
                <td><?= to_currency($summary['net_invoice_amount'] ?? $invoice['total_amount']) ?></td>
            </tr>
            <tr>
                <td class="meta-head"><?= lang('Accounts.balance_due') ?></td>
                <td><?= to_currency($summary['balance_due'] ?? $details['balance']) ?></td>
            </tr>
        </table>
    </div>

    <table id="items">
        <thead>
        <tr>
            <th><?= lang('Accounts.description') ?></th>
            <th><?= lang('Accounts.reference') ?></th>
            <th class="center"><?= lang('Sales.quantity') ?></th>
            <th class="right"><?= lang('Accounts.unit_price') ?></th>
            <th class="right"><?= lang('Accounts.amount') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php
        $returnsTotal = (float) ($summary['returns_total'] ?? 0);
        $hasReturns = $returnsTotal != 0.0 || !empty($details['returns']);
        ?>
        <?php foreach ($details['sales'] as $sale): ?>
            <?php
            $saleId = (int) $sale['sale_id'];
            $saleItemTotal = 0.0;
            foreach ($sale['items'] as $item) {
                $saleItemTotal += (float) ($item['amount'] ?? 0);
            }
            $saleTotal = isset($sale['original_total']) ? (float) $sale['original_total'] : $saleItemTotal;
            $salePaymentLines = [];
            foreach ($details['payments'] as $payment) {
                foreach ($payment['allocations'] ?? [] as $alloc) {
                    if ((int) ($alloc['sale_id'] ?? 0) === $saleId) {
                        $salePaymentLines[] = [
                            'payment_type'   => (string) $payment['payment_type'],
                            'reference_code' => (string) ($payment['reference_code'] ?? ''),
                            'amount'         => (float) $alloc['amount'],
                        ];
                    }
                }
            }
            ?>
            <tr class="group-row">
                <td colspan="5">
                    <?= lang('Sales.receipt_number') ?> <?= $saleId ?>
                    <?php if (!empty($sale['invoice_number'])): ?>
                        / <?= esc($sale['invoice_number']) ?>
                    <?php endif; ?>
                    — <?= esc(to_datetime(strtotime((string) $sale['sale_time']))) ?>
                </td>
            </tr>
            <?php foreach ($sale['items'] as $item): ?>
                <tr>
                    <td><?= esc($item['description'] ?? '') ?></td>
                    <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                    <td class="center"><?= esc((string) $item['qty']) ?></td>
                    <td class="right"><?= to_currency($item['unit_price']) ?></td>
                    <td class="right"><?= to_currency($item['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td class="blank" colspan="2"></td>
                <td colspan="2" class="total-line"><?= lang('Accounts.sale_total') ?></td>
                <td class="total-value"><?= to_currency($saleTotal) ?></td>
            </tr>
            <?php if ((float) ($sale['paid_on_sale'] ?? 0) > 0): ?>
                <tr>
                    <td class="blank" colspan="2"></td>
                    <td colspan="2" class="total-line"><?= lang('Accounts.paid_on_sale') ?></td>
                    <td class="total-value"><?= to_currency((float) $sale['paid_on_sale'] * -1) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($salePaymentLines as $salePayment): ?>
                <tr>
                    <td class="blank" colspan="2"></td>
                    <td colspan="2" class="total-line">
                        <?= esc($salePayment['payment_type']) ?>
                        <?php if ($salePayment['reference_code'] !== ''): ?>
                            (<?= esc($salePayment['reference_code']) ?>)
                        <?php endif; ?>
                    </td>
                    <td class="total-value"><?= to_currency($salePayment['amount'] * -1) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($hasReturns): ?>
            <tr class="group-row">
                <td colspan="5"><?= lang('Accounts.returns_adjustments') ?></td>
            </tr>
            <?php foreach ($details['returns'] as $return): ?>
                <tr>
                    <td><?= esc($return['description'] ?? '') ?></td>
                    <td><?= esc($return['reference'] ?? '') ?></td>
                    <td colspan="2"><?= esc($return['related_sale'] ?? '') ?></td>
                    <td class="right"><?= to_currency($return['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>

        <?= view('accounts/partials/invoice_print_totals', [
            'summary'         => $summary,
            'invoice_number'  => (string) $invoice['invoice_number'],
            'blank_colspan'   => 2,
            'label_colspan'   => 2,
        ]) ?>
        <?php
        $unallocatedPayments = [];
        foreach ($details['payments'] as $payment) {
            if (empty($payment['allocations'])) {
                $unallocatedPayments[] = $payment;
            }
        }
        ?>
        <?php foreach ($unallocatedPayments as $payment): ?>
            <tr>
                <td class="blank" colspan="2"></td>
                <td colspan="2" class="total-line">
                    <?= esc($payment['payment_type']) ?>
                    <?php if (!empty($payment['reference_code'])): ?>
                        (<?= esc($payment['reference_code']) ?>)
                    <?php endif; ?>
                </td>
                <td class="total-value"><?= to_currency((float) $payment['payment_amount'] * -1) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="terms">
        <div><?= nl2br(esc($config['payment_message'] ?? '')) ?></div>
        <?php if (!empty($invoice['comment'])): ?>
            <div style="margin-top: 8px;"><?= lang('Accounts.comment') ?>: <?= esc($invoice['comment']) ?></div>
        <?php endif; ?>
        <div style="margin-top: 8px;"><?= nl2br(esc($config['return_policy'] ?? '')) ?></div>
        <div id="barcode">
            <?= $barcode ?><br>
            <?= esc($invoice['invoice_number']) ?>
        </div>
    </div>
</div>
</body>
</html>
