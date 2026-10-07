<?php
/**
 * Short consolidated invoice PDF — sale-invoice layout.
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
        #items th, #items td { border: 1px solid #ccc; padding: 6px; }
        #items th { background: #f3f3f3; text-align: left; }
        .right { text-align: right; }
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
            <th><?= lang('Common.id') ?></th>
            <th><?= lang('Sales.invoice_number') ?></th>
            <th class="right"><?= lang('Accounts.original_sales_total') ?></th>
            <th class="right"><?= lang('Accounts.paid_on_sale') ?></th>
            <th class="right"><?= lang('Accounts.sale_returns') ?></th>
            <th class="right"><?= lang('Accounts.current_balance') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($details['sales'] as $sale): ?>
            <tr>
                <td><?= (int) $sale['sale_id'] ?></td>
                <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                <td class="right"><?= to_currency($sale['original_total'] ?? $sale['attached_amount']) ?></td>
                <td class="right"><?= to_currency($sale['paid_on_sale'] ?? 0) ?></td>
                <td class="right"><?= to_currency($sale['returns'] ?? 0) ?></td>
                <td class="right"><?= to_currency($sale['current_balance'] ?? $sale['outstanding'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        <?= view('accounts/partials/invoice_print_totals', [
            'summary'         => $summary,
            'invoice_number'  => (string) $invoice['invoice_number'],
            'blank_colspan'   => 4,
            'label_colspan'   => 1,
        ]) ?>
        </tbody>
    </table>

    <div id="terms">
        <div><?= nl2br(esc($config['payment_message'] ?? '')) ?></div>
        <?php if (!empty($invoice['comment'])): ?>
            <div style="margin-top: 8px;"><?= lang('Accounts.comment') ?>: <?= esc($invoice['comment']) ?></div>
        <?php endif; ?>
        <?php if (!empty(trim((string) ($config['return_policy'] ?? '')))): ?>
            <div style="margin-top: 8px;"><?= nl2br(esc($config['return_policy'] ?? '')) ?></div>
        <?php endif; ?>
        <div id="barcode">
            <?= $barcode ?><br>
            <?= esc($invoice['invoice_number']) ?>
        </div>
    </div>
</div>
</body>
</html>
