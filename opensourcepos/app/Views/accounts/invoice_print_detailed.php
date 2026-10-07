<?php
/**
 * Consolidated invoice detailed print layout (sale-invoice design).
 *
 * @var array $details
 * @var object $person_info
 * @var string $customer_info
 * @var string $company_info
 * @var array $status_labels
 * @var array $config
 * @var string $barcode
 * @var string $page_title
 * @var bool $auto_print
 * @var string $template
 */

$invoice = $details['invoice'];
$invoiceId = (int) $invoice['consolidated_invoice_id'];
$summary = $details['summary'] ?? [];
$invoiceColumns = 5;
?>

<?= view('partial/header') ?>

<?= view('partial/print_receipt', [
    'print_after_sale' => false,
    'selected_printer' => 'invoice_printer',
    'page_title'       => $page_title ?? $invoice['invoice_number'],
    'print_filename'   => $page_title ?? $invoice['invoice_number'],
]) ?>

<div class="print_hide" id="control_buttons" style="text-align: right;">
    <a href="javascript:printdoc();">
        <div class="btn btn-info btn-sm" id="show_print_button"><?= '<span class="glyphicon glyphicon-print">&nbsp;</span>' . lang('Common.print') ?></div>
    </a>
    <?= anchor('accounts/pdf/' . $invoiceId . '/detailed', '<span class="glyphicon glyphicon-download-alt">&nbsp;</span>' . lang('Accounts.download_pdf'), ['class' => 'btn btn-info btn-sm', 'target' => '_blank']) ?>
    <?= anchor('accounts/printInvoice/' . $invoiceId . '/short', '<span class="glyphicon glyphicon-file">&nbsp;</span>' . lang('Accounts.preview_short_pdf'), ['class' => 'btn btn-info btn-sm']) ?>
    <?= anchor('accounts/invoice/' . $invoiceId, '<span class="glyphicon glyphicon-remove">&nbsp;</span>' . lang('Common.close'), ['class' => 'btn btn-info btn-sm']) ?>
</div>

<div id="page-wrap">
    <div id="header"><?= lang('Accounts.consolidated_invoice') ?></div>
    <div id="block1">
        <div id="customer-title">
            <div id="customer"><?= nl2br(esc($customer_info)) ?></div>
        </div>

        <div id="logo">
            <?php if (!empty($config['company_logo'])): ?>
                <img id="image" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="company_logo">
            <?php endif; ?>
            <div>&nbsp;</div>
            <?php if (!empty($config['receipt_show_company_name'])): ?>
                <div id="company_name"><?= esc($config['company']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="block2">
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
        <tr>
            <th><?= lang('Accounts.description') ?></th>
            <th><?= lang('Accounts.reference') ?></th>
            <th><?= lang('Sales.quantity') ?></th>
            <th><?= lang('Accounts.unit_price') ?></th>
            <th><?= lang('Accounts.amount') ?></th>
        </tr>

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
            <tr class="item-row">
                <td colspan="<?= $invoiceColumns ?>" style="font-weight: bold; background: #f5f5f5;">
                    <?= lang('Sales.receipt_number') ?> <?= $saleId ?>
                    <?php if (!empty($sale['invoice_number'])): ?>
                        / <?= esc($sale['invoice_number']) ?>
                    <?php endif; ?>
                    — <?= esc(to_datetime(strtotime((string) $sale['sale_time']))) ?>
                </td>
            </tr>
            <?php foreach ($sale['items'] as $item): ?>
                <tr class="item-row">
                    <td class="item-name"><?= esc($item['description'] ?? '') ?></td>
                    <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                    <td style="text-align: center;"><?= esc((string) $item['qty']) ?></td>
                    <td style="text-align: right;"><?= to_currency($item['unit_price']) ?></td>
                    <td style="border-right: solid 1px; text-align: right;"><?= to_currency($item['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="<?= $invoiceColumns - 3 ?>" class="blank"> </td>
                <td colspan="2" class="total-line"><?= lang('Accounts.sale_total') ?></td>
                <td class="total-value"><?= to_currency($saleTotal) ?></td>
            </tr>
            <?php if ((float) ($sale['paid_on_sale'] ?? 0) > 0): ?>
                <tr>
                    <td colspan="<?= $invoiceColumns - 3 ?>" class="blank"> </td>
                    <td colspan="2" class="total-line"><?= lang('Accounts.paid_on_sale') ?></td>
                    <td class="total-value"><?= to_currency((float) $sale['paid_on_sale'] * -1) ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($salePaymentLines as $salePayment): ?>
                <tr>
                    <td colspan="<?= $invoiceColumns - 3 ?>" class="blank"> </td>
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
            <tr>
                <td class="blank" colspan="<?= $invoiceColumns ?>" style="text-align: center;"><?= '&nbsp;' ?></td>
            </tr>
            <tr class="item-row">
                <td colspan="<?= $invoiceColumns ?>" style="font-weight: bold; background: #f5f5f5;">
                    <?= lang('Accounts.returns_adjustments') ?>
                </td>
            </tr>
            <?php foreach ($details['returns'] as $return): ?>
                <tr class="item-row">
                    <td class="item-name"><?= esc($return['description'] ?? '') ?></td>
                    <td><?= esc($return['reference'] ?? '') ?></td>
                    <td colspan="2"><?= esc($return['related_sale'] ?? '') ?></td>
                    <td style="border-right: solid 1px; text-align: right;"><?= to_currency($return['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>

        <tr>
            <td class="blank" colspan="<?= $invoiceColumns ?>" style="text-align: center;"><?= '&nbsp;' ?></td>
        </tr>
        <?= view('accounts/partials/invoice_print_totals', [
            'summary'         => $summary,
            'invoice_number'  => (string) $invoice['invoice_number'],
            'blank_colspan'   => $invoiceColumns - 3,
            'label_colspan'   => 2,
        ]) ?>
        <?php
        // Fallback: payments with no sale allocations still show as a single line.
        $unallocatedPayments = [];
        foreach ($details['payments'] as $payment) {
            if (empty($payment['allocations'])) {
                $unallocatedPayments[] = $payment;
            }
        }
        ?>
        <?php foreach ($unallocatedPayments as $payment): ?>
            <tr>
                <td colspan="<?= $invoiceColumns - 3 ?>" class="blank"> </td>
                <td colspan="2" class="total-line">
                    <?= esc($payment['payment_type']) ?>
                    <?php if (!empty($payment['reference_code'])): ?>
                        (<?= esc($payment['reference_code']) ?>)
                    <?php endif; ?>
                </td>
                <td class="total-value"><?= to_currency((float) $payment['payment_amount'] * -1) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div id="terms">
        <div id="sale_return_policy">
            <h5>
                <span><?= nl2br(esc($config['payment_message'] ?? '')) ?></span>
                <?php if (!empty($invoice['comment'])): ?>
                    <span style="padding: 4%;"><?= lang('Accounts.comment') . ': ' . esc($invoice['comment']) ?></span>
                <?php endif; ?>
            </h5>
            <?php if (!empty(trim((string) ($config['return_policy'] ?? '')))): ?>
                <div style="padding: 2%;"><?= nl2br(esc($config['return_policy'] ?? '')) ?></div>
            <?php endif; ?>
        </div>
        <div id="barcode">
            <?= $barcode ?><br>
            <?= esc($invoice['invoice_number']) ?>
        </div>
    </div>
</div>

<script type="text/javascript">
    function printdoc() {
        document.title = <?= json_encode($page_title ?? $invoice['invoice_number']) ?>;
        window.print();
    }

    $(window).on('load', function() {
        if (window.jsPrintSetup) {
            <?php if (empty($config['print_header'])): ?>
                jsPrintSetup.setOption('headerStrLeft', '');
                jsPrintSetup.setOption('headerStrCenter', '');
                jsPrintSetup.setOption('headerStrRight', '');
            <?php endif; ?>
            <?php if (empty($config['print_footer'])): ?>
                jsPrintSetup.setOption('footerStrLeft', '');
                jsPrintSetup.setOption('footerStrCenter', '');
                jsPrintSetup.setOption('footerStrRight', '');
            <?php endif; ?>
        }

        <?php if (!empty($auto_print)): ?>
            printdoc();
        <?php endif; ?>
    });
</script>

<?= view('partial/footer') ?>
