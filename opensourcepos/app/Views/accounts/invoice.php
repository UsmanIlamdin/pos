<?php
/**
 * @var array $details
 * @var object $person_info
 * @var array $payment_options
 * @var array $reference_types
 * @var bool $can_pay
 * @var bool $can_cancel
 * @var bool $can_void
 * @var array $status_labels
 */

$invoice = $details['invoice'];
$invoiceId = (int) $invoice['consolidated_invoice_id'];
$isCancelled = (int) $invoice['status'] === CI_STATUS_CANCELLED;
$isPaid = (int) $invoice['status'] === CI_STATUS_PAID;
$can_void = $can_void ?? false;
$summary = $details['summary'] ?? [
    'original_sales_total' => (float) $invoice['total_amount'],
    'returns_total' => 0.0,
    'net_invoice_amount' => (float) $invoice['total_amount'],
    'payments_applied' => (float) $details['paid'],
    'balance_due' => (float) $details['balance'],
];
$hasReturns = ((float) ($summary['returns_total'] ?? 0) != 0.0) || !empty($details['returns']);
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

        function resetVoidInvoiceModal() {
            $('#void-invoice-reason').val('').closest('.form-group').removeClass('has-error');
            $('#void-invoice-error').hide().text('');
            $('#void-invoice-loading').show();
            $('#void-invoice-details').hide();
            $('#confirm-void-invoice').prop('disabled', true);
        }

        function populateVoidInvoiceModal(invoice) {
            const titleTpl = <?= json_encode(lang('Accounts.void_invoice_title')) ?>;
            $('#void-invoice-title').text(titleTpl.replace('{0}', invoice.invoice_number || ''));
            $('#void-invoice-number').text(invoice.invoice_number || '');
            $('#void-invoice-date').text(invoice.invoice_date_formatted || invoice.invoice_date || '');
            $('#void-invoice-total').text(invoice.total_amount_formatted || invoice.total_amount || '');
            $('#void-invoice-paid').text(invoice.total_paid_formatted || invoice.total_paid || '');
            $('#void-invoice-balance').text(invoice.balance_formatted || invoice.balance || '');
            $('#void-invoice-sale-outstanding').text(
                invoice.sale_outstanding_total_formatted || invoice.receivables_after_void_formatted || ''
            );
            if (invoice.payments_will_remain_active) {
                $('#void-invoice-impact')
                    .removeClass('alert-info')
                    .addClass('alert-warning')
                    .text(<?= json_encode(lang('Accounts.void_invoice_payments_warning')) ?>)
                    .show();
            } else {
                $('#void-invoice-impact')
                    .removeClass('alert-warning')
                    .addClass('alert-info')
                    .text(<?= json_encode(lang('Accounts.void_invoice_unpaid_note')) ?>)
                    .show();
            }
            $('#void-invoice-loading').hide();
            $('#void-invoice-details').show();
            $('#confirm-void-invoice').prop('disabled', !!invoice.voided);
            if (invoice.voided) {
                $('#void-invoice-error').text(<?= json_encode(lang('Accounts.error_invoice_already_voided')) ?>).show();
            }
        }

        $('#void-invoice').on('click', function() {
            resetVoidInvoiceModal();
            $('#void-invoice-modal').modal('show');
            $.getJSON(<?= json_encode(site_url('accounts/invoiceDetails/' . $invoiceId)) ?>, {
                customer_id: <?= (int) $invoice['customer_id'] ?>
            }, function(response) {
                if (!response || !response.success || !response.invoice) {
                    $('#void-invoice-loading').hide();
                    $('#void-invoice-error').text(
                        (response && response.message) || <?= json_encode(lang('Accounts.error_loading_invoice')) ?>
                    ).show();
                    return;
                }
                populateVoidInvoiceModal(response.invoice);
            }).fail(function() {
                $('#void-invoice-loading').hide();
                $('#void-invoice-error').text(<?= json_encode(lang('Accounts.error_loading_invoice')) ?>).show();
            });
        });

        $('#confirm-void-invoice').on('click', function() {
            const reason = $.trim($('#void-invoice-reason').val() || '');
            const $btn = $(this);
            if (reason === '') {
                $('#void-invoice-reason').closest('.form-group').addClass('has-error');
                $('#void-invoice-error').text(<?= json_encode(lang('Accounts.error_void_reason_required')) ?>).show();
                return;
            }
            if ($btn.prop('disabled')) {
                return;
            }
            $btn.prop('disabled', true);
            $('#void-invoice-error').hide().text('');
            $('#void-invoice-reason').closest('.form-group').removeClass('has-error');

            $.post(<?= json_encode(site_url('accounts/voidInvoice/' . $invoiceId)) ?>, {
                void_reason: reason,
                customer_id: <?= (int) $invoice['customer_id'] ?>
            }, function(response) {
                if (response && response.success) {
                    $('#void-invoice-modal').modal('hide');
                    $.notify({ message: response.message }, { type: 'success' });
                    window.location = '<?= site_url('accounts/view/' . (int) $invoice['customer_id']) ?>';
                    return;
                }
                $btn.prop('disabled', false);
                $('#void-invoice-error').text(
                    (response && response.message) || <?= json_encode(lang('Accounts.error_void_invoice_failed')) ?>
                ).show();
            }, 'json').fail(function() {
                $btn.prop('disabled', false);
                $('#void-invoice-error').text(<?= json_encode(lang('Accounts.error_void_invoice_failed')) ?>).show();
            });
        });

        const customerId = <?= (int) $invoice['customer_id'] ?>;

        const resetVoidPaymentModal = function() {
            $('#void-payment-id').val('');
            $('#void-payment-reason').val('').closest('.form-group').removeClass('has-error');
            $('#void-payment-error').hide().text('');
            $('#void-payment-loading').show();
            $('#void-payment-details').hide();
            $('#void-payment-allocations').empty();
            $('#void-payment-invoice-row').hide();
            $('#confirm-void-payment').prop('disabled', true);
        };

        const populateVoidPaymentModal = function(payment) {
            const title = <?= json_encode(lang('Accounts.void_payment_confirm_title')) ?>
                .replace('{0}', payment.payment_id);
            $('#void-payment-title').text(title);
            $('#void-payment-number').text('#' + payment.payment_id);
            $('#void-payment-date').text(payment.payment_time_formatted || payment.payment_time || '');
            $('#void-payment-method').text(payment.payment_type || '');
            $('#void-payment-amount').text(payment.payment_amount_formatted || payment.payment_amount || '');

            const $allocBody = $('#void-payment-allocations').empty();
            (payment.allocations || []).forEach(function(alloc) {
                let saleLabel = 'Sale #' + alloc.sale_id;
                if (alloc.invoice_number) {
                    saleLabel += ' / ' + alloc.invoice_number;
                }
                $allocBody.append(
                    '<tr><td>' + $('<div>').text(saleLabel).html() +
                    '</td><td class="text-right">' +
                    $('<div>').text(alloc.amount_formatted || alloc.amount || '').html() +
                    '</td></tr>'
                );
            });
            if (!(payment.allocations || []).length) {
                $allocBody.append(
                    '<tr><td colspan="2"><em>' + <?= json_encode(lang('Common.none_selected_text')) ?> + '</em></td></tr>'
                );
            }

            if (payment.consolidated_invoice_number) {
                $('#void-payment-invoice').text(payment.consolidated_invoice_number);
                $('#void-payment-invoice-row').show();
            } else {
                $('#void-payment-invoice-row').hide();
            }

            $('#void-payment-loading').hide();
            $('#void-payment-details').show();
            $('#confirm-void-payment').prop('disabled', !!payment.voided);
            if (payment.voided) {
                $('#void-payment-error').text(<?= json_encode(lang('Accounts.error_payment_already_voided')) ?>).show();
            }
        };

        $(document).on('click', '.void-invoice-payment', function() {
            const paymentId = $(this).data('payment-id');
            if (!paymentId) {
                return;
            }
            resetVoidPaymentModal();
            $('#void-payment-id').val(paymentId);
            $('#void-payment-modal').modal('show');

            $.getJSON(<?= json_encode(site_url('accounts/paymentDetails')) ?> + '/' + paymentId, {
                customer_id: customerId
            }).done(function(response) {
                if (!response || !response.success || !response.payment) {
                    $('#void-payment-loading').hide();
                    $('#void-payment-error').text(
                        (response && response.message) || <?= json_encode(lang('Accounts.error_loading_payment')) ?>
                    ).show();
                    return;
                }
                populateVoidPaymentModal(response.payment);
            }).fail(function() {
                $('#void-payment-loading').hide();
                $('#void-payment-error').text(<?= json_encode(lang('Accounts.error_loading_payment')) ?>).show();
            });
        });

        $(document).on('click', '#confirm-void-payment', function() {
            const paymentId = $('#void-payment-id').val();
            const reason = $.trim($('#void-payment-reason').val() || '');
            if (!paymentId) {
                return;
            }
            if (!reason) {
                $('#void-payment-reason').closest('.form-group').addClass('has-error');
                $('#void-payment-error').text(<?= json_encode(lang('Accounts.error_void_reason_required')) ?>).show();
                return;
            }

            const $button = $(this);
            $button.prop('disabled', true);
            $('#void-payment-error').hide().text('');
            $('#void-payment-reason').closest('.form-group').removeClass('has-error');

            $.post(<?= json_encode(site_url('accounts/voidPayment')) ?> + '/' + paymentId, {
                void_reason: reason,
                customer_id: customerId
            }, function(response) {
                if (!response || !response.success) {
                    const message = (response && response.message)
                        ? response.message
                        : <?= json_encode(lang('Accounts.error_void_failed')) ?>;
                    $('#void-payment-error').text(message).show();
                    $.notify({ message: message }, { type: 'danger' });
                    $button.prop('disabled', false);
                    return;
                }
                $('#void-payment-modal').modal('hide');
                $.notify({ message: response.message }, { type: 'success' });
                window.location.reload();
            }, 'json').fail(function(xhr) {
                const message = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : <?= json_encode(lang('Accounts.error_void_failed')) ?>;
                $('#void-payment-error').text(message).show();
                $.notify({ message: message }, { type: 'danger' });
                $button.prop('disabled', false);
            });
        });

        $('#void-payment-modal').on('hidden.bs.modal', function() {
            resetVoidPaymentModal();
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
    <?php if ($can_cancel && !$isCancelled): ?>
        <button type="button" id="void-invoice" class="btn btn-danger btn-sm"><?= lang('Accounts.void_invoice') ?></button>
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
                <p><?= lang('Accounts.invoice_total') ?>: <strong><?= to_currency($invoice['total_amount']) ?></strong></p>
                <p><?= lang('Accounts.total_paid') ?>: <strong><?= to_currency($details['paid']) ?></strong></p>
                <?php if ((float) ($details['credits_applied'] ?? $summary['credits_applied_to_sales'] ?? 0) > 0.00001): ?>
                    <p><?= lang('Accounts.credits_applied') ?>: <strong><?= to_currency($details['credits_applied'] ?? $summary['credits_applied_to_sales']) ?></strong></p>
                <?php endif; ?>
                <p><?= lang('Accounts.balance') ?>: <strong><?= to_currency($details['balance']) ?></strong></p>
                <?php if ($isCancelled): ?>
                    <p class="text-muted" style="margin-top:8px;">
                        <?= lang('Accounts.void_invoice_sale_outstanding') ?>:
                        <strong><?= to_currency($summary['sale_outstanding_total'] ?? 0) ?></strong>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Invoice Summary</div>
    <div class="panel-body">
        <?php
        $previouslyPaid = (float) ($summary['previously_paid_on_sales'] ?? 0);
        $creditsApplied = (float) ($summary['credits_applied_to_sales'] ?? $details['credits_applied'] ?? 0);
        ?>
        <table class="table table-condensed" style="max-width: 520px; margin-bottom:0;">
            <tbody>
                <tr>
                    <td><?= lang('Accounts.original_sales_total') ?></td>
                    <td class="text-right"><?= to_currency($summary['original_sales_total'] ?? 0) ?></td>
                </tr>
                <?php if ($hasReturns): ?>
                <tr>
                    <td><?= lang('Accounts.returns_total') ?></td>
                    <td class="text-right"><?= to_currency($summary['returns_total']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($previouslyPaid > 0.00001): ?>
                <tr>
                    <td><?= lang('Accounts.previously_paid_on_sales') ?></td>
                    <td class="text-right"><?= to_currency(-$previouslyPaid) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><?= lang('Accounts.net_invoice_amount') ?></td>
                    <td class="text-right"><?= to_currency($summary['consolidated_invoice_total'] ?? $invoice['total_amount']) ?></td>
                </tr>
                <tr>
                    <td><?= lang('Accounts.payments_applied_to_ci') ?></td>
                    <td class="text-right"><?= to_currency($summary['payments_applied'] ?? $details['paid']) ?></td>
                </tr>
                <?php if ($creditsApplied > 0.00001): ?>
                <tr>
                    <td><?= lang('Accounts.credits_applied_to_sales') ?></td>
                    <td class="text-right"><?= to_currency(-$creditsApplied) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><strong><?= lang('Accounts.balance_due') ?></strong></td>
                    <td class="text-right"><strong><?= to_currency($summary['balance_due'] ?? $details['balance']) ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Items</div>
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
    <div class="panel-heading">Returns &amp; Adjustments</div>
    <div class="panel-body">
        <table class="table table-striped table-bordered table-condensed">
            <thead>
            <tr>
                <th>Description</th>
                <th>Reference</th>
                <th>Related Sale</th>
                <th class="text-right">Amount</th>
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
    <div class="panel-heading">Payment Summary</div>
    <div class="panel-body">
        <table class="table table-condensed" style="max-width: 520px; margin-bottom:0;">
            <tbody>
                <tr>
                    <td><?= lang('Accounts.invoice_total') ?> <small class="text-muted">(consolidated)</small></td>
                    <td class="text-right"><?= to_currency($summary['consolidated_invoice_total'] ?? $invoice['total_amount']) ?></td>
                </tr>
                <tr>
                    <td><?= lang('Accounts.payments_applied_to_ci') ?></td>
                    <td class="text-right"><?= to_currency($summary['payments_applied'] ?? $details['paid']) ?></td>
                </tr>
                <?php if ((float) ($summary['credits_applied_to_sales'] ?? 0) > 0.00001): ?>
                <tr>
                    <td><?= lang('Accounts.credits_applied_to_sales') ?></td>
                    <td class="text-right"><?= to_currency(-((float) $summary['credits_applied_to_sales'])) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><strong><?= lang('Accounts.balance_due') ?></strong></td>
                    <td class="text-right"><strong><?= to_currency($summary['balance_due'] ?? $details['balance']) ?></strong></td>
                </tr>
            </tbody>
        </table>
        <?php
        $activePayments = array_values(array_filter(
            $details['payments'] ?? [],
            static fn (array $payment): bool => empty($payment['voided'])
        ));
        ?>
        <?php if ($activePayments !== []): ?>
            <table class="table table-striped table-bordered table-condensed" style="margin-top: 15px;">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th class="text-right">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($activePayments as $payment): ?>
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
    <div class="panel-heading">Underlying Transactions</div>
    <div class="panel-body">
        <?php if ($details['sales'] === []): ?>
            <p>No sales are associated with this consolidated invoice.</p>
        <?php else: ?>
            <table class="table table-striped table-bordered table-condensed">
                <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th class="text-right">Original Total</th>
                    <th class="text-right">Current Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details['sales'] as $sale): ?>
                    <tr>
                        <td><?= (int) $sale['sale_id'] ?></td>
                        <td><?= esc(to_datetime(strtotime((string) $sale['sale_time']))) ?></td>
                        <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                        <td class="text-right"><?= to_currency($sale['original_total']) ?></td>
                        <td class="text-right"><?= to_currency($sale['outstanding']) ?></td>
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

<?php if ($isCancelled && !empty($details['payments'])): ?>
    <div class="alert alert-warning">
        <?= lang('Accounts.void_invoice_payments_warning') ?>
        <?= anchor('accounts/view/' . (int) $invoice['customer_id'], lang('Accounts.view_account'), ['class' => 'alert-link']) ?>
    </div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.payment_history') ?></div>
    <div class="panel-body">
        <?php
        $creditsAppliedHistory = (float) ($summary['credits_applied_to_sales'] ?? $details['credits_applied'] ?? 0);
        $creditSaleRows = array_values(array_filter(
            $details['sales'] ?? [],
            static fn (array $sale): bool => (float) ($sale['credits_applied'] ?? 0) > 0.00001
        ));
        ?>
        <?php if ($creditsAppliedHistory > 0.00001): ?>
            <table class="table table-striped" style="margin-bottom: 15px;">
                <thead>
                <tr>
                    <th><?= lang('Accounts.credits_applied_to_sales') ?></th>
                    <th><?= lang('Common.id') ?></th>
                    <th class="text-right"><?= lang('Sales.amount_tendered') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($creditSaleRows as $sale): ?>
                    <tr>
                        <td><?= lang('Accounts.customer_credit') ?></td>
                        <td>Sale #<?= (int) $sale['sale_id'] ?></td>
                        <td class="text-right"><?= to_currency($sale['credits_applied']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($details['payments'] === []): ?>
            <?php if ($creditsAppliedHistory <= 0.00001): ?>
                <p><?= lang('Accounts.no_payments_on_invoice') ?></p>
            <?php endif; ?>
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
                    <?php if ($can_void): ?>
                        <th></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details['payments'] as $payment): ?>
                    <?php
                    $isVoidedPayment = !empty($payment['voided']);
                    $allocParts = [];
                    foreach ($payment['allocations'] ?? [] as $alloc) {
                        $allocParts[] = 'Sale #' . (int) $alloc['sale_id'] . ' — ' . to_currency($alloc['amount']);
                    }
                    ?>
                    <tr class="<?= $isVoidedPayment ? 'text-muted' : '' ?>">
                        <td><?= esc($payment['payment_time']) ?></td>
                        <td>
                            <?= esc($payment['payment_type']) ?>
                            <?php if ($isVoidedPayment): ?>
                                <span class="label label-default"><?= lang('Accounts.status_voided') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc($payment['reference_code']) ?></td>
                        <td class="text-right"><?= to_currency($payment['payment_amount']) ?></td>
                        <td><?= esc(implode('; ', $allocParts)) ?></td>
                        <td>
                            <?= esc($payment['comment'] ?? '') ?>
                            <?php if ($isVoidedPayment && !empty($payment['void_reason'])): ?>
                                <div><small><?= esc(lang('Accounts.void_reason')) ?>: <?= esc((string) $payment['void_reason']) ?></small></div>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_void): ?>
                            <td>
                                <?php if (!$isVoidedPayment): ?>
                                    <button type="button"
                                            class="btn btn-xs btn-danger void-invoice-payment"
                                            data-payment-id="<?= (int) $payment['payment_id'] ?>">
                                        <?= lang('Accounts.void_payment_from_invoice') ?>
                                    </button>
                                <?php else: ?>
                                    <span class="label label-default"><?= lang('Accounts.status_voided') ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="void-invoice-modal" tabindex="-1" role="dialog" aria-labelledby="void-invoice-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="void-invoice-title"><?= lang('Accounts.void_invoice') ?></h4>
            </div>
            <div class="modal-body">
                <div id="void-invoice-loading" class="text-muted"><?= lang('Accounts.loading_records') ?></div>
                <div id="void-invoice-error" class="alert alert-danger" style="display:none;"></div>
                <div id="void-invoice-details" style="display:none;">
                    <p><?= lang('Accounts.void_invoice_confirm_body') ?></p>
                    <p class="help-block"><?= lang('Accounts.void_invoice_confirm_replace') ?></p>
                    <div id="void-invoice-impact" class="alert alert-info" style="display:none;"></div>
                    <dl class="dl-horizontal">
                        <dt><?= lang('Accounts.consolidated_invoice_number') ?></dt>
                        <dd id="void-invoice-number"></dd>
                        <dt><?= lang('Accounts.invoice_date') ?></dt>
                        <dd id="void-invoice-date"></dd>
                        <dt><?= lang('Accounts.invoice_total') ?></dt>
                        <dd id="void-invoice-total"></dd>
                        <dt><?= lang('Accounts.total_paid') ?></dt>
                        <dd id="void-invoice-paid"></dd>
                        <dt><?= lang('Accounts.outstanding_balance') ?></dt>
                        <dd id="void-invoice-balance"></dd>
                        <dt><?= lang('Accounts.void_invoice_sale_outstanding') ?></dt>
                        <dd id="void-invoice-sale-outstanding"></dd>
                    </dl>
                    <div class="form-group">
                        <label for="void-invoice-reason"><?= lang('Accounts.void_reason') ?> <span class="text-danger">*</span></label>
                        <textarea id="void-invoice-reason" class="form-control" rows="3" required
                                  placeholder="<?= esc(lang('Accounts.void_reason_placeholder'), 'attr') ?>"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="button" class="btn btn-danger" id="confirm-void-invoice" disabled><?= lang('Accounts.confirm_void') ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="void-payment-modal" tabindex="-1" role="dialog" aria-labelledby="void-payment-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="void-payment-title"><?= lang('Accounts.void_payment') ?></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="void-payment-id" value="">
                <div id="void-payment-loading" class="text-muted"><?= lang('Accounts.loading_records') ?></div>
                <div id="void-payment-error" class="alert alert-danger" style="display:none;"></div>
                <div id="void-payment-details" style="display:none;">
                    <p><?= lang('Accounts.void_payment_confirm_body') ?></p>
                    <p class="help-block"><?= lang('Accounts.void_payment_confirm_preserve') ?></p>
                    <dl class="dl-horizontal">
                        <dt><?= lang('Accounts.payment_number') ?></dt>
                        <dd id="void-payment-number"></dd>
                        <dt><?= lang('Accounts.payment_date') ?></dt>
                        <dd id="void-payment-date"></dd>
                        <dt><?= lang('Accounts.payment_method') ?></dt>
                        <dd id="void-payment-method"></dd>
                        <dt><?= lang('Accounts.payment_total_amount') ?></dt>
                        <dd id="void-payment-amount"></dd>
                    </dl>
                    <div id="void-payment-invoice-row" style="display:none; margin-bottom: 10px;">
                        <strong><?= lang('Accounts.void_associated_invoice') ?>:</strong>
                        <span id="void-payment-invoice"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-condensed table-striped">
                            <thead>
                                <tr>
                                    <th><?= lang('Accounts.void_allocations') ?></th>
                                    <th class="text-right"><?= lang('Accounts.amount_paid') ?></th>
                                </tr>
                            </thead>
                            <tbody id="void-payment-allocations"></tbody>
                        </table>
                    </div>
                    <div class="form-group">
                        <label for="void-payment-reason"><?= lang('Accounts.void_reason') ?> <span class="text-danger">*</span></label>
                        <textarea id="void-payment-reason" class="form-control" rows="3" required
                                  placeholder="<?= esc(lang('Accounts.void_reason_placeholder'), 'attr') ?>"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.cancel') ?></button>
                <button type="button" class="btn btn-danger" id="confirm-void-payment" disabled><?= lang('Accounts.confirm_void') ?></button>
            </div>
        </div>
    </div>
</div>

<?= view('partial/footer') ?>
