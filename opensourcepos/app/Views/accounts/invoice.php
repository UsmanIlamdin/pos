<?php
/**
 * @var array $details
 * @var object $person_info
 * @var array $payment_options
 * @var array $reference_types
 * @var bool $can_pay
 * @var bool $can_cancel
 * @var array $status_labels
 */

$invoice = $details['invoice'];
$invoiceId = (int) $invoice['consolidated_invoice_id'];
$isCancelled = (int) $invoice['status'] === CI_STATUS_CANCELLED;
$isPaid = (int) $invoice['status'] === CI_STATUS_PAID;
$summary = $details['summary'] ?? [
    'original_sales_total'     => (float) $invoice['total_amount'],
    'returns_total'            => 0.0,
    'net_invoice_amount'       => (float) $invoice['total_amount'],
    'previously_paid_on_sales' => 0.0,
    'payments_applied_to_ci'   => 0.0,
    'payments_applied'         => 0.0,
    'balance_due'              => (float) $details['balance'],
    'invoice_number'           => (string) $invoice['invoice_number'],
];
$summary['invoice_number'] = $summary['invoice_number'] ?? (string) $invoice['invoice_number'];
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        dialog_support.init('a.modal-dlg');

        const referenceTypes = <?= json_encode(array_values($reference_types)) ?>;

        function toggleReference() {
            const type = $('#invoice_payment_type').val();
            const needsRef = referenceTypes.indexOf(type) !== -1;
            $('.reference-group').toggle(needsRef);
            $('#invoice_reference_code').prop('required', needsRef);
        }

        toggleReference();
        $('#invoice_payment_type').on('change', toggleReference);

        $('#invoice-payment-form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            $.post($form.attr('action'), $form.serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });

        $('#cancel-invoice').on('click', function() {
            if (!confirm(<?= json_encode(lang('Reports.confirm_delete')) ?>)) {
                return;
            }
            $.post('<?= site_url('accounts/cancelInvoice/' . $invoiceId) ?>', {}, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location = '<?= site_url('accounts/view/' . (int) $invoice['customer_id']) ?>';
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="btn-toolbar">
    <?= anchor('accounts/view/' . (int) $invoice['customer_id'], '<span class="glyphicon glyphicon-arrow-left">&nbsp;</span>' . lang('Accounts.account'), ['class' => 'btn btn-info btn-sm']) ?>
    <?= anchor('accounts/printInvoice/' . $invoiceId . '/short', '<span class="glyphicon glyphicon-file">&nbsp;</span>' . lang('Accounts.preview_short_pdf'), ['class' => 'btn btn-info btn-sm', 'target' => '_blank']) ?>
    <?= anchor('accounts/printInvoice/' . $invoiceId . '/detailed', '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang('Accounts.preview_detailed_pdf'), ['class' => 'btn btn-info btn-sm', 'target' => '_blank']) ?>
    <a class="btn btn-info btn-sm" target="_blank" href="<?= esc(site_url('accounts/printInvoice/' . $invoiceId . '/short') . '?print=1', 'attr') ?>">
        <span class="glyphicon glyphicon-print">&nbsp;</span><?= lang('Accounts.print_invoice') ?>
    </a>
    <?php if ($can_cancel && !$isCancelled && !$isPaid && empty($details['payments'])): ?>
        <button type="button" id="cancel-invoice" class="btn btn-danger btn-sm"><?= lang('Datepicker.cancel') ?></button>
    <?php endif; ?>
</div>

<div class="panel panel-primary">
    <div class="panel-heading">
        <?= lang('Accounts.consolidated_invoice') ?>: <?= esc($invoice['invoice_number']) ?>
    </div>
    <div class="panel-body">
        <div class="row">
            <div class="col-md-6">
                <p><strong><?= lang('Reports.customer') ?>:</strong> <?= esc($person_info->first_name . ' ' . $person_info->last_name) ?></p>
                <p><strong><?= lang('Accounts.invoice_date') ?>:</strong> <?= esc($invoice['invoice_date']) ?></p>
                <p><strong><?= lang('Accounts.due_date') ?>:</strong> <?= esc($invoice['due_date'] ?? '') ?></p>
                <p><strong><?= lang('Accounts.status') ?>:</strong> <?= esc($status_labels[(int) $invoice['status']] ?? $invoice['status']) ?></p>
            </div>
            <div class="col-md-6 text-right">
                <p><?= lang('Accounts.net_invoice_amount') ?>: <strong><?= to_currency($summary['net_invoice_amount']) ?></strong></p>
                <p><?= lang('Accounts.total_paid') ?>: <strong><?= to_currency($details['paid']) ?></strong></p>
                <p><?= lang('Accounts.balance_due') ?>: <strong><?= to_currency($details['balance']) ?></strong></p>
            </div>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.invoice_summary') ?></div>
    <div class="panel-body">
        <table class="table table-condensed" style="max-width: 520px; margin-bottom:0;">
            <tbody>
                <?= view('accounts/partials/invoice_summary_rows', [
                    'summary'         => $summary,
                    'invoice_number'  => (string) $invoice['invoice_number'],
                ]) ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.items') ?></div>
    <div class="panel-body">
        <?php if ($details['sales'] === []): ?>
            <p>No sales are associated with this consolidated invoice.</p>
        <?php else: ?>
            <?php foreach ($details['sales'] as $sale): ?>
                <div style="margin-bottom: 16px;">
                    <div style="font-weight: bold; margin-bottom: 8px;">
                        Sale #<?= (int) $sale['sale_id'] ?> / <?= esc($sale['invoice_number'] ?? '') ?>
                    </div>
                    <table class="table table-striped table-bordered table-condensed">
                        <thead>
                        <tr>
                            <th>Description</th>
                            <th>Reference</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sale['items'] as $item): ?>
                            <tr>
                                <td><?= esc($item['description'] ?? '') ?></td>
                                <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                                <td class="text-right"><?= esc((string) $item['qty']) ?></td>
                                <td class="text-right"><?= to_currency($item['unit_price']) ?></td>
                                <td class="text-right"><?= to_currency($item['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="4" class="text-right">Sale Total</th>
                            <th class="text-right"><?= to_currency($sale['original_total']) ?></th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($details['returns'])): ?>
<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.returns_adjustments') ?></div>
    <div class="panel-body">
        <table class="table table-striped table-bordered table-condensed">
            <thead>
            <tr>
                <th><?= lang('Accounts.description') ?></th>
                <th><?= lang('Accounts.reference') ?></th>
                <th><?= lang('Accounts.related_sale') ?></th>
                <th class="text-right"><?= lang('Accounts.amount') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($details['returns'] as $return): ?>
                <tr>
                    <td><?= esc($return['description'] ?? '') ?></td>
                    <td><?= esc($return['reference'] ?? '') ?></td>
                    <td><?= esc($return['related_sale'] ?? '') ?></td>
                    <td class="text-right"><?= to_currency($return['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.payment_details') ?></div>
    <div class="panel-body">
        <?php if (empty($details['payments'])): ?>
            <p class="text-muted" style="margin:0;">
                <?= esc(lang('Accounts.payments_applied_to_ci', [(string) $invoice['invoice_number']])) ?>:
                <?= to_currency($summary['payments_applied_to_ci'] ?? 0) ?>
            </p>
        <?php else: ?>
            <table class="table table-striped table-bordered table-condensed">
                <thead>
                <tr>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.payment_type') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th class="text-right"><?= lang('Accounts.amount') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details['payments'] as $payment): ?>
                    <tr>
                        <td><?= esc($payment['payment_time']) ?></td>
                        <td><?= esc($payment['payment_type']) ?></td>
                        <td><?= esc($payment['reference_code'] ?: '-') ?></td>
                        <td class="text-right"><?= to_currency($payment['payment_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.underlying_sales') ?></div>
    <div class="panel-body">
        <?php if ($details['sales'] === []): ?>
            <p>No sales are associated with this consolidated invoice.</p>
        <?php else: ?>
            <table class="table table-striped table-bordered table-condensed">
                <thead>
                <tr>
                    <th><?= lang('Common.id') ?></th>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Sales.invoice_number') ?></th>
                    <th class="text-right"><?= lang('Accounts.original_sales_total') ?></th>
                    <th class="text-right"><?= lang('Accounts.paid_on_sale') ?></th>
                    <th class="text-right"><?= lang('Accounts.sale_returns') ?></th>
                    <th class="text-right"><?= lang('Accounts.current_balance') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details['sales'] as $sale): ?>
                    <tr>
                        <td><?= (int) $sale['sale_id'] ?></td>
                        <td><?= esc(to_datetime(strtotime((string) $sale['sale_time']))) ?></td>
                        <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                        <td class="text-right"><?= to_currency($sale['original_total']) ?></td>
                        <td class="text-right"><?= to_currency($sale['paid_on_sale'] ?? 0) ?></td>
                        <td class="text-right"><?= to_currency($sale['returns'] ?? 0) ?></td>
                        <td class="text-right"><?= to_currency($sale['current_balance'] ?? $sale['outstanding']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_pay && !$isCancelled && !$isPaid && $details['balance'] > 0): ?>
    <div class="panel panel-default">
        <div class="panel-heading"><?= lang('Accounts.add_payment') ?></div>
        <div class="panel-body">
            <?= form_open('accounts/payInvoice/' . $invoiceId, ['id' => 'invoice-payment-form', 'class' => 'form-horizontal']) ?>
                <div class="form-group form-group-sm">
                    <?= form_label(lang('Accounts.payment_type'), 'invoice_payment_type', ['class' => 'control-label col-xs-3']) ?>
                    <div class="col-xs-4">
                        <?= form_dropdown('payment_type', $payment_options, '', 'class="form-control input-sm" id="invoice_payment_type"') ?>
                    </div>
                </div>
                <div class="form-group form-group-sm">
                    <?= form_label(lang('Sales.amount_tendered'), 'invoice_payment_amount', ['class' => 'control-label col-xs-3']) ?>
                    <div class="col-xs-4">
                        <?= form_input(['name' => 'payment_amount', 'id' => 'invoice_payment_amount', 'class' => 'form-control input-sm', 'required' => 'required', 'value' => $details['balance']]) ?>
                    </div>
                </div>
                <div class="form-group form-group-sm reference-group" style="display:none;">
                    <?= form_label(lang('Sales.reference_code'), 'invoice_reference_code', ['class' => 'control-label col-xs-3']) ?>
                    <div class="col-xs-4">
                        <?= form_input(['name' => 'reference_code', 'id' => 'invoice_reference_code', 'class' => 'form-control input-sm']) ?>
                    </div>
                </div>
                <div class="form-group form-group-sm">
                    <?= form_label(lang('Accounts.comment'), 'invoice_comment', ['class' => 'control-label col-xs-3']) ?>
                    <div class="col-xs-4">
                        <?= form_input(['name' => 'comment', 'id' => 'invoice_comment', 'class' => 'form-control input-sm']) ?>
                    </div>
                </div>
                <div class="form-group form-group-sm">
                    <div class="col-xs-offset-3 col-xs-4">
                        <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounts.add_payment') ?></button>
                    </div>
                </div>
            <?= form_close() ?>
        </div>
    </div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.payment_history') ?></div>
    <div class="panel-body">
        <?php if ($details['payments'] === []): ?>
            <p><?= lang('Accounts.no_outstanding_sales') ?></p>
        <?php else: ?>
            <table class="table table-striped">
                <thead>
                <tr>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.payment_type') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th class="text-right"><?= lang('Sales.amount_tendered') ?></th>
                    <th><?= lang('Accounts.allocated_to') ?></th>
                    <th><?= lang('Accounts.comment') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details['payments'] as $payment): ?>
                    <?php
                    $allocParts = [];
                    foreach ($payment['allocations'] ?? [] as $alloc) {
                        $allocParts[] = 'Sale #' . (int) $alloc['sale_id'] . ' — ' . to_currency($alloc['amount']);
                    }
                    ?>
                    <tr>
                        <td><?= esc($payment['payment_time']) ?></td>
                        <td><?= esc($payment['payment_type']) ?></td>
                        <td><?= esc($payment['reference_code']) ?></td>
                        <td class="text-right"><?= to_currency($payment['payment_amount']) ?></td>
                        <td><?= esc(implode('; ', $allocParts)) ?></td>
                        <td><?= esc($payment['comment'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?= view('partial/footer') ?>
