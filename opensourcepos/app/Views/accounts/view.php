<?php
/**
 * @var object $person_info
 * @var int $customer_id
 * @var float $outstanding_balance
 * @var array $payable_sales
 * @var array $eligible_sales
 * @var array $open_invoices
 * @var int $open_invoice_count
 * @var array $payment_options
 * @var array $reference_types
 * @var bool $can_pay
 * @var bool $can_consolidate
 * @var bool $can_cancel
 * @var array $status_labels
 * @var int $per_page
 * @var array $account_filters
 * @var array $outstanding_sales_all
 */

$openInvoices = $open_invoices ?? [];
$payable_sales = $payable_sales ?? [];
$hasPayableSales = $payable_sales !== [];
$hasOpenInvoices = $openInvoices !== [];
$defaultSaleId = count($payable_sales) === 1 ? (int) $payable_sales[0]['sale_id'] : '';
$defaultApplyTo = '';
if ($hasOpenInvoices && !$hasPayableSales) {
    $defaultApplyTo = 'consolidated_invoice';
} elseif ($hasPayableSales && !$hasOpenInvoices) {
    $defaultApplyTo = count($payable_sales) === 1 ? 'sale' : 'sales';
} elseif ($hasPayableSales) {
    $defaultApplyTo = $defaultSaleId !== '' ? 'sale' : '';
}
$account_filters = $account_filters ?? [
    'outstanding' => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'active' => false],
    'ci'          => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'balance' => '', 'page' => 1, 'active' => false],
    'ledger'      => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'page' => 1, 'active' => false],
    'transaction' => ['search' => '', 'from' => '', 'to' => '', 'type' => '', 'side' => '', 'page' => 1, 'active' => false],
    'activity'    => ['search' => '', 'from' => '', 'to' => '', 'type' => '', 'voided' => '', 'page' => 1, 'active' => false],
];
$outstanding_sales_all = $outstanding_sales_all ?? ($outstanding_sales ?? []);
$can_void = $can_void ?? false;
$can_reallocate = $can_reallocate ?? false;
$can_credit_apply = $can_credit_apply ?? false;
$can_refund = $can_refund ?? false;
$customer_credit = $customer_credit ?? 0;
$typeChoices = [
    ''                     => lang('Accounts.filter_all'),
    'sale'                 => lang('Accounts.filter_type_sale'),
    'payment'              => lang('Accounts.filter_type_payment'),
    'return'               => lang('Accounts.filter_type_return'),
    'refund'               => lang('Accounts.filter_type_refund'),
    'customer_credit'      => lang('Accounts.filter_type_customer_credit'),
    'credit_allocation'    => lang('Accounts.filter_type_credit_allocation'),
    'consolidated_invoice' => lang('Accounts.filter_type_consolidated_invoice'),
];
?>

<?= view('partial/header') ?>
<?= view('accounts/partials/accounts_view_script') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const referenceTypes = <?= json_encode(array_values($reference_types)) ?>;
        const customerId = <?= (int) $customer_id ?>;

        function toggleReference($form) {
            const type = $form.find('[name="payment_type"]').val();
            const needsRef = referenceTypes.indexOf(type) !== -1;
            $form.find('.reference-group').toggle(needsRef);
            $form.find('[name="reference_code"]').prop('required', needsRef);
        }

        function toggleApplyTo() {
            const applyTo = $('#apply_to').val();
            $('.apply-sale-group, .apply-sales-group, .apply-ci-group').hide();
            $('#sale_id, #consolidated_invoice_id').prop('required', false);
            if (applyTo === 'sale') {
                $('.apply-sale-group').show();
                $('#sale_id').prop('required', true);
            } else if (applyTo === 'sales') {
                $('.apply-sales-group').show();
            } else if (applyTo === 'consolidated_invoice') {
                $('.apply-ci-group').show();
                $('#consolidated_invoice_id').prop('required', true);
            }
        }

        $('.payment-form').each(function() {
            const $form = $(this);
            toggleReference($form);
            $form.on('change', '[name="payment_type"]', function() {
                toggleReference($form);
            });
        });

        $('#apply_to').on('change', toggleApplyTo);
        toggleApplyTo();

        $('#customer-payment-form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            ensureIdempotency($form);
            $.post($form.attr('action'), $form.serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });

        function uuid() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }

        function ensureIdempotency($form) {
            let $key = $form.find('.idempotency-key, [name="idempotency_key"]');
            if ($key.length === 0) {
                $form.append('<input type="hidden" name="idempotency_key" class="idempotency-key" value="">');
                $key = $form.find('[name="idempotency_key"]');
            }
            if (!$key.val()) {
                $key.val(uuid());
            }
        }

        $(document).on('click', '.ledger-toggle', function() {
            const $row = $(this).closest('tr');
            const saleId = $row.data('sale-id');
            const $children = $('tr.ledger-child[data-parent="' + saleId + '"]');
            const open = $children.first().is(':visible');
            $children.toggle(!open);
            $(this).text(open ? '+' : '−');
        });

        const resetVoidModal = function() {
            $('#void-payment-id').val('');
            $('#void-payment-reason').val('').closest('.form-group').removeClass('has-error');
            $('#void-payment-error').hide().text('');
            $('#void-payment-loading').show();
            $('#void-payment-details').hide();
            $('#void-payment-allocations').empty();
            $('#void-payment-invoice-row').hide();
            $('#confirm-void-payment').prop('disabled', true);
        };

        const populateVoidModal = function(payment) {
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

        $(document).on('click', '.void-payment', function() {
            const paymentId = $(this).data('payment-id');
            if (!paymentId) {
                return;
            }
            resetVoidModal();
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
                populateVoidModal(response.payment);
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
                customer_id: customerId,
                idempotency_key: uuid()
            }, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (!response.success) {
                    return;
                }
                $('#void-payment-modal').modal('hide');
                if (response.summary) {
                    if (response.summary.outstanding_balance_formatted) {
                        $('#account-outstanding-balance').text(response.summary.outstanding_balance_formatted);
                    }
                    if (typeof response.summary.outstanding_count !== 'undefined') {
                        $('#account-outstanding-count').text(response.summary.outstanding_count);
                    }
                    if (typeof response.summary.open_invoice_count !== 'undefined') {
                        $('#account-open-invoice-count').text(response.summary.open_invoice_count);
                    }
                    if (response.summary.customer_credit_formatted) {
                        $('#account-customer-credit').text(response.summary.customer_credit_formatted);
                    }
                }
                if (window.accountsViewApi && typeof window.accountsViewApi.refreshAll === 'function') {
                    window.accountsViewApi.refreshAll();
                } else {
                    window.location.reload();
                }
            }, 'json').fail(function(xhr) {
                const message = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : <?= json_encode(lang('Accounts.error_void_failed')) ?>;
                $('#void-payment-error').text(message).show();
                $.notify({ message: message }, { type: 'danger' });
            }).always(function() {
                $button.prop('disabled', false);
            });
        });

        $('#void-payment-modal').on('hidden.bs.modal', function() {
            resetVoidModal();
        });

        $(document).on('click', '.reallocate-payment', function() {
            const paymentId = $(this).data('payment-id');
            if (!paymentId) {
                return;
            }
            const $modal = $('#reallocate-modal');
            const $body = $('#reallocate-sales');
            $body.empty();
            $('#reallocate-reason').val('').closest('.form-group').removeClass('has-error');
            $('#reallocate-error').hide().text('');
            $('#reallocate-help').text(<?= json_encode(lang('Accounts.change_allocation_help')) ?>);
            $('#reallocate-payment-id').val(paymentId);
            $('#reallocate-customer-id').val(customerId);
            $('#reallocate-submit').prop('disabled', false);
            $.getJSON('<?= site_url('accounts/reallocatePayment') ?>/' + paymentId, function(response) {
                if (!response.success) {
                    $.notify({ message: response.message }, { type: 'danger' });
                    return;
                }
                $('#reallocate-payment-meta').text(
                    'Payment #' + response.payment_id + ' / ' + response.payment_type +
                    ' — ' + <?= json_encode(lang('Accounts.amount_paid')) ?> + ': ' + response.payment_amount
                );
                $('#reallocate-required-total').text(response.payment_amount);
                (response.sales || []).forEach(function(sale, index) {
                    $body.append(
                        '<div class="row" style="margin-bottom:6px;">' +
                        '<div class="col-xs-6">Sale #' + sale.sale_id +
                        ' (max ' + sale.max_amount + ')</div>' +
                        '<div class="col-xs-4">' +
                        '<input type="hidden" name="allocations[' + index + '][sale_id]" value="' + sale.sale_id + '">' +
                        '<input type="number" step="0.01" min="0" max="' + sale.max_amount + '" ' +
                        'class="form-control input-sm reallocate-amount" ' +
                        'name="allocations[' + index + '][amount]" value="' + (sale.current_amount || 0) + '">' +
                        '</div></div>'
                    );
                });
                $modal.modal('show');
            });
        });

        $('#reallocate-form').on('submit', function(e) {
            e.preventDefault();
            const paymentId = $('#reallocate-payment-id').val();
            const reason = $.trim($('#reallocate-reason').val() || '');
            const $submit = $('#reallocate-submit');
            if (!reason) {
                $('#reallocate-reason').closest('.form-group').addClass('has-error');
                $('#reallocate-error').text(<?= json_encode(lang('Accounts.error_reallocate_reason_required')) ?>).show();
                return;
            }
            if ($submit.prop('disabled')) {
                return;
            }
            $submit.prop('disabled', true);
            $('#reallocate-error').hide().text('');
            $('#reallocate-reason').closest('.form-group').removeClass('has-error');
            $.post('<?= site_url('accounts/reallocatePayment') ?>/' + paymentId, $(this).serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    $('#reallocate-modal').modal('hide');
                    if (window.accountsViewApi && typeof window.accountsViewApi.refreshAll === 'function') {
                        window.accountsViewApi.refreshAll();
                    } else {
                        window.location.reload();
                    }
                    return;
                }
                $submit.prop('disabled', false);
                $('#reallocate-error').text(response.message || <?= json_encode(lang('Accounts.error_reallocate_failed')) ?>).show();
            }, 'json').fail(function() {
                $submit.prop('disabled', false);
                $('#reallocate-error').text(<?= json_encode(lang('Accounts.error_reallocate_failed')) ?>).show();
            });
        });

        $('#apply-credit-form, #refund-credit-form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            ensureIdempotency($form);
            $.post($form.attr('action'), $form.serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });

        $('#create-invoice-form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            $.post($form.attr('action'), $form.serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success && response.consolidated_invoice_id) {
                    window.location = '<?= site_url('accounts/invoice') ?>/' + response.consolidated_invoice_id;
                }
            }, 'json');
        });

        function updateSelectedTotal() {
            let total = 0;
            $('input[name="sale_ids[]"]:checked').each(function() {
                total += parseFloat($(this).data('outstanding')) || 0;
            });
            $('#selected-total').text(total.toFixed(<?= totals_decimals() ?>));
        }

        $(document).on('change', 'input[name="sale_ids[]"]', updateSelectedTotal);
        updateSelectedTotal();

        window.accountsViewApi = AccountsViewLazy.init({
            customerId: customerId,
            perPage: <?= (int) $per_page ?>,
            urls: {
                outstanding: <?= json_encode(site_url('accounts/outstanding/' . (int) $customer_id)) ?>,
                invoices: <?= json_encode(site_url('accounts/invoices/' . (int) $customer_id)) ?>,
                ledger: <?= json_encode(site_url('accounts/ledger/' . (int) $customer_id)) ?>,
                transactions: <?= json_encode(site_url('accounts/transactions/' . (int) $customer_id)) ?>,
                activity: <?= json_encode(site_url('accounts/activity/' . (int) $customer_id)) ?>
            },
            labels: {
                showing: <?= json_encode(lang('Accounts.showing_rows')) ?>,
                of: <?= json_encode(lang('Accounts.pagination_of')) ?>,
                loading: <?= json_encode(lang('Accounts.loading_records')) ?>,
                loadMore: <?= json_encode(lang('Accounts.load_more')) ?>,
                error: <?= json_encode(lang('Accounts.load_error')) ?>,
                voidPayment: <?= json_encode(lang('Accounts.void_payment')) ?>,
                changeAllocation: <?= json_encode(lang('Accounts.change_allocation')) ?>,
                noOutstanding: <?= json_encode(lang('Accounts.no_outstanding_sales')) ?>,
                noOutstandingFiltered: <?= json_encode(lang('Accounts.no_outstanding_sales_filtered')) ?>,
                noCi: <?= json_encode(lang('Accounts.no_consolidated_invoices')) ?>,
                noCiFiltered: <?= json_encode(lang('Accounts.no_consolidated_invoices_filtered')) ?>,
                noLedger: <?= json_encode(lang('Accounts.no_ledger_records')) ?>,
                noLedgerFiltered: <?= json_encode(lang('Accounts.no_ledger_records_filtered')) ?>,
                noTx: <?= json_encode(lang('Accounts.no_account_transactions')) ?>,
                noTxFiltered: <?= json_encode(lang('Accounts.no_account_transactions_filtered')) ?>,
                noActivity: <?= json_encode(lang('Accounts.no_account_activity')) ?>,
                noActivityFiltered: <?= json_encode(lang('Accounts.no_account_activity_filtered')) ?>
            }
        });
    });
</script>
<style>
    .account-filter-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        align-items: flex-end;
        margin-bottom: 12px;
    }
    .account-filter-field {
        flex: 1 1 160px;
        min-width: 140px;
        max-width: 240px;
    }
    .account-filter-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 3px;
    }
    .account-filter-actions {
        flex: 1 1 180px;
        min-width: 160px;
        padding-bottom: 1px;
    }
    .section-pager { margin-top: 8px; }
    .section-load-more { margin-top: 8px; }
    .section-loading, .section-error { margin: 8px 0; }
    .section-error { color: #a94442; }
    @media (max-width: 767px) {
        .account-filter-field,
        .account-filter-actions {
            max-width: none;
            flex: 1 1 100%;
        }
    }
</style>

<div id="title_bar" class="btn-toolbar" style="margin-top: 0; margin-bottom: 10px;">
    <?= anchor('accounts', '<span class="glyphicon glyphicon-arrow-left">&nbsp;</span>' . lang('Module.accounts'), ['class' => 'btn btn-info btn-sm']) ?>
    <h3 class="pull-right" style="margin: 0; line-height: 30px;"><?= esc($person_info->first_name . ' ' . $person_info->last_name) ?></h3>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="panel panel-primary">
            <div class="panel-heading"><?= lang('Accounts.outstanding_receivables') ?></div>
            <div class="panel-body">
                <h2 id="account-outstanding-balance"><?= to_currency($outstanding_balance) ?></h2>
                <p><?= lang('Accounts.number_outstanding_sales') ?>: <span id="account-outstanding-count"><?= count($outstanding_sales_all) ?></span></p>
                <p><?= lang('Accounts.number_open_invoices') ?>: <span id="account-open-invoice-count"><?= (int) $open_invoice_count ?></span></p>
                <p><?= lang('Accounts.open_invoice_balance') ?>: <strong id="account-open-invoice-balance"><?= to_currency($open_invoice_balance ?? 0) ?></strong></p>
                <p><?= lang('Accounts.customer_credit') ?>: <strong id="account-customer-credit"><?= to_currency($customer_credit ?? 0) ?></strong></p>
            </div>
        </div>
    </div>

    <?php if ($can_pay): ?>
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading"><?= lang('Accounts.add_payment') ?></div>
                <div class="panel-body">
                    <?= form_open('accounts/payment/' . $customer_id, ['id' => 'customer-payment-form', 'class' => 'payment-form form-horizontal']) ?>
                        <div class="form-group form-group-sm">
                            <?= form_label(lang('Accounts.payment_type'), 'payment_type', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?= form_dropdown('payment_type', $payment_options, '', 'class="form-control input-sm" id="payment_type"') ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm">
                            <?= form_label(lang('Sales.amount_tendered'), 'payment_amount', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?= form_input(['name' => 'payment_amount', 'id' => 'payment_amount', 'class' => 'form-control input-sm', 'required' => 'required']) ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm">
                            <?= form_label(lang('Accounts.apply_to'), 'apply_to', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?php
                                $applyOptions = ['' => lang('Accounts.apply_to_select')];
                                if ($hasPayableSales) {
                                    $applyOptions['sale'] = lang('Accounts.apply_to_sale');
                                    $applyOptions['sales'] = lang('Accounts.apply_to_multiple_sales');
                                }
                                if ($hasOpenInvoices) {
                                    $applyOptions['consolidated_invoice'] = lang('Accounts.apply_to_consolidated_invoice');
                                }
                                ?>
                                <?= form_dropdown(
                                    'apply_to',
                                    $applyOptions,
                                    $defaultApplyTo,
                                    'class="form-control input-sm" id="apply_to" required'
                                ) ?>
                                <?php if (!$hasPayableSales && $hasOpenInvoices): ?>
                                    <p class="help-block" style="margin-bottom:0;">
                                        <?= lang('Accounts.apply_to_ci_only_help') ?>
                                    </p>
                                <?php elseif ($hasPayableSales && !$hasOpenInvoices): ?>
                                    <p class="help-block" style="margin-bottom:0;">
                                        <?= lang('Accounts.apply_to_sales_only_help') ?>
                                    </p>
                                <?php elseif (!$hasPayableSales && !$hasOpenInvoices): ?>
                                    <p class="help-block text-danger" style="margin-bottom:0;">
                                        <?= lang('Accounts.no_payment_targets') ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm apply-sale-group" style="display:none;">
                            <?= form_label(lang('Accounts.apply_to_sale'), 'sale_id', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?php
                                $saleOptions = ['' => lang('Accounts.apply_to_select_sale')];
                                foreach ($payable_sales as $sale) {
                                    $saleOptions[(int) $sale['sale_id']] = '#' . $sale['sale_id']
                                        . ' / ' . ($sale['invoice_number'] ?? '')
                                        . ' — ' . to_currency($sale['outstanding']);
                                }
                                ?>
                                <?= form_dropdown('sale_id', $saleOptions, $defaultSaleId, 'class="form-control input-sm" id="sale_id"') ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm apply-sales-group" style="display:none;">
                            <?= form_label(lang('Accounts.apply_to_multiple_sales'), 'allocations', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-8">
                                <?php if (!$hasPayableSales): ?>
                                    <p class="help-block"><?= lang('Accounts.no_outstanding_sales') ?></p>
                                <?php endif; ?>
                                <?php foreach ($payable_sales as $index => $sale): ?>
                                    <div class="row" style="margin-bottom:4px;">
                                        <div class="col-xs-6">
                                            <input type="hidden" name="allocations[<?= $index ?>][sale_id]" value="<?= (int) $sale['sale_id'] ?>">
                                            Sale #<?= (int) $sale['sale_id'] ?>
                                            <?php if (!empty($sale['invoice_number'])): ?>
                                                / <?= esc($sale['invoice_number']) ?>
                                            <?php endif; ?>
                                            (<?= to_currency($sale['outstanding']) ?>)
                                        </div>
                                        <div class="col-xs-4">
                                            <input type="number" step="0.01" min="0" class="form-control input-sm"
                                                   name="allocations[<?= $index ?>][amount]"
                                                   placeholder="0">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm apply-ci-group" style="display:none;">
                            <?= form_label(lang('Accounts.apply_to_consolidated_invoice'), 'consolidated_invoice_id', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?php
                                $ciOptions = ['' => lang('Accounts.apply_to_select_ci')];
                                $defaultCiId = count($openInvoices) === 1
                                    ? (int) $openInvoices[0]['consolidated_invoice_id']
                                    : '';
                                foreach ($openInvoices as $invoice) {
                                    $ciOptions[(int) $invoice['consolidated_invoice_id']] =
                                        $invoice['invoice_number'] . ' — ' . to_currency($invoice['balance']);
                                }
                                ?>
                                <?= form_dropdown(
                                    'consolidated_invoice_id',
                                    $ciOptions,
                                    $defaultCiId,
                                    'class="form-control input-sm" id="consolidated_invoice_id"'
                                ) ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm reference-group" style="display:none;">
                            <?= form_label(lang('Sales.reference_code'), 'reference_code', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?= form_input(['name' => 'reference_code', 'id' => 'reference_code', 'class' => 'form-control input-sm']) ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm">
                            <?= form_label(lang('Accounts.comment'), 'comment', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?= form_input(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control input-sm']) ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm">
                            <div class="col-xs-offset-3 col-xs-5">
                                <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounts.add_payment') ?></button>
                            </div>
                        </div>
                    <?= form_close() ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="panel panel-default account-section" id="section-outstanding">
    <div class="panel-heading"><?= lang('Accounts.outstanding_sales') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'prefix'              => 'outstanding',
            'filters'             => $account_filters['outstanding'],
            'search_name'         => 'outstanding_search',
            'search_placeholder'  => lang('Accounts.outstanding_search_placeholder'),
            'selects'             => [
                'outstanding_status' => [
                    'label'   => lang('Accounts.status'),
                    'key'     => 'status',
                    'choices' => [
                        ''                => lang('Accounts.filter_all'),
                        'unpaid'          => lang('Accounts.filter_unpaid'),
                        'partially_paid'  => lang('Accounts.partially_paid'),
                    ],
                ],
            ],
        ]) ?>
        <p class="section-meta text-muted"></p>
        <div class="section-loading" style="display:none;"><?= lang('Accounts.loading_records') ?></div>
        <div class="section-error" style="display:none;"></div>
        <div class="section-empty" style="display:none;"></div>
        <div class="section-table-wrap" style="display:none;">
            <table class="table table-striped table-hover">
                <thead>
                <tr>
                    <th><?= lang('Common.id') ?></th>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Sales.invoice_number') ?></th>
                    <th class="text-right"><?= lang('Common.total') ?></th>
                    <th class="text-right"><?= lang('Accounts.amount_paid') ?></th>
                    <th class="text-right"><?= lang('Accounts.balance_due') ?></th>
                </tr>
                </thead>
                <tbody class="section-body"></tbody>
            </table>
        </div>
        <div class="section-pager"></div>
        <button type="button" class="btn btn-default btn-sm section-load-more" style="display:none;"><?= lang('Accounts.load_more') ?></button>
    </div>
</div>

<?php if ($can_consolidate): ?>
    <div class="panel panel-default">
        <div class="panel-heading"><?= lang('Accounts.create_consolidated_invoice') ?></div>
        <div class="panel-body">
            <?php if ($eligible_sales === []): ?>
                <p><?= lang('Accounts.no_outstanding_sales') ?></p>
            <?php else: ?>
                <?= form_open('accounts/createInvoice/' . $customer_id, ['id' => 'create-invoice-form', 'class' => 'form-horizontal']) ?>
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th><?= lang('Accounts.select_sales') ?></th>
                            <th><?= lang('Common.id') ?></th>
                            <th><?= lang('Reports.date') ?></th>
                            <th class="text-right"><?= lang('Accounts.balance_due') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eligible_sales as $sale): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="sale_ids[]" value="<?= (int) $sale['sale_id'] ?>" data-outstanding="<?= esc($sale['outstanding']) ?>">
                                </td>
                                <td><?= (int) $sale['sale_id'] ?></td>
                                <td><?= esc(to_datetime(strtotime($sale['sale_time']))) ?></td>
                                <td class="text-right"><?= to_currency($sale['outstanding']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><?= lang('Accounts.selected_total') ?>: <strong id="selected-total">0</strong></p>
                    <div class="form-group form-group-sm">
                        <?= form_label(lang('Accounts.due_date'), 'due_date', ['class' => 'control-label col-xs-2']) ?>
                        <div class="col-xs-3">
                            <?= form_input(['name' => 'due_date', 'id' => 'due_date', 'class' => 'form-control input-sm', 'type' => 'date']) ?>
                        </div>
                    </div>
                    <div class="form-group form-group-sm">
                        <?= form_label(lang('Accounts.comment'), 'ci_comment', ['class' => 'control-label col-xs-2']) ?>
                        <div class="col-xs-5">
                            <?= form_input(['name' => 'comment', 'id' => 'ci_comment', 'class' => 'form-control input-sm']) ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounts.create_consolidated_invoice') ?></button>
                <?= form_close() ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="panel panel-default account-section" id="section-ci">
    <div class="panel-heading"><?= lang('Accounts.consolidated_invoices') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'prefix'              => 'ci',
            'filters'             => $account_filters['ci'],
            'search_name'         => 'ci_search',
            'search_placeholder'  => lang('Accounts.ci_search_placeholder'),
            'selects'             => [
                'ci_status' => [
                    'label'   => lang('Accounts.status'),
                    'key'     => 'status',
                    'choices' => [
                        ''               => lang('Accounts.filter_all'),
                        'paid'           => lang('Accounts.paid'),
                        'partially_paid' => lang('Accounts.partially_paid'),
                        'unpaid'         => lang('Accounts.filter_unpaid'),
                    ],
                ],
                'ci_balance' => [
                    'label'   => lang('Accounts.balance'),
                    'key'     => 'balance',
                    'choices' => [
                        ''             => lang('Accounts.filter_all'),
                        'with_balance' => lang('Accounts.filter_with_balance'),
                        'fully_paid'   => lang('Accounts.filter_fully_paid'),
                    ],
                ],
            ],
        ]) ?>
        <p class="section-meta text-muted"></p>
        <div class="section-loading" style="display:none;"><?= lang('Accounts.loading_records') ?></div>
        <div class="section-error" style="display:none;"></div>
        <div class="section-empty" style="display:none;"></div>
        <div class="section-table-wrap" style="display:none;">
            <table class="table table-striped table-hover">
                <thead>
                <tr>
                    <th><?= lang('Accounts.consolidated_invoice_number') ?></th>
                    <th><?= lang('Accounts.invoice_date') ?></th>
                    <th><?= lang('Accounts.status') ?></th>
                    <th class="text-right"><?= lang('Accounts.invoice_total') ?></th>
                    <th class="text-right"><?= lang('Accounts.total_paid') ?></th>
                    <th class="text-right"><?= lang('Accounts.balance') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody class="section-body"></tbody>
            </table>
        </div>
        <div class="section-pager"></div>
        <button type="button" class="btn btn-default btn-sm section-load-more" style="display:none;"><?= lang('Accounts.load_more') ?></button>
    </div>
</div>

<div class="panel panel-default account-section" id="section-ledger">
    <div class="panel-heading"><?= lang('Accounts.ledger') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'prefix'              => 'ledger',
            'filters'             => $account_filters['ledger'],
            'search_name'         => 'ledger_search',
            'search_placeholder'  => lang('Accounts.ledger_search_placeholder'),
            'selects'             => [
                'ledger_status' => [
                    'label'   => lang('Accounts.status'),
                    'key'     => 'status',
                    'choices' => [
                        ''               => lang('Accounts.filter_all'),
                        'paid'           => lang('Accounts.paid'),
                        'partially_paid' => lang('Accounts.partially_paid'),
                        'unpaid'         => lang('Accounts.filter_unpaid'),
                    ],
                ],
            ],
        ]) ?>
        <p class="section-meta text-muted"></p>
        <div class="section-loading" style="display:none;"><?= lang('Accounts.loading_records') ?></div>
        <div class="section-error" style="display:none;"></div>
        <div class="section-empty" style="display:none;"></div>
        <div class="section-table-wrap" style="display:none;">
            <table class="table table-striped table-hover" id="ledger-table">
                <thead>
                <tr>
                    <th></th>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th class="text-right"><?= lang('Accounts.sale_total') ?></th>
                    <th class="text-right"><?= lang('Accounts.payments_applied') ?></th>
                    <th class="text-right"><?= lang('Accounts.credits_returns') ?></th>
                    <th class="text-right"><?= lang('Accounts.balance') ?></th>
                    <th><?= lang('Accounts.status') ?></th>
                </tr>
                </thead>
                <tbody class="section-body"></tbody>
            </table>
        </div>
        <div class="section-pager"></div>
        <button type="button" class="btn btn-default btn-sm section-load-more" style="display:none;"><?= lang('Accounts.load_more') ?></button>

        <?php if (($customer_credit ?? 0) > 0 && ($can_credit_apply || $can_refund)): ?>
            <hr>
            <h4><?= lang('Accounts.customer_credit') ?>: <?= to_currency($customer_credit) ?></h4>
            <?php if ($can_credit_apply): ?>
                <?= form_open('accounts/applyCredit', ['id' => 'apply-credit-form', 'class' => 'form-inline', 'style' => 'margin-bottom:10px;']) ?>
                    <input type="hidden" name="customer_id" value="<?= (int) $customer_id ?>">
                    <input type="hidden" name="idempotency_key" class="idempotency-key" value="">
                    <select name="sale_id" id="apply-credit-sale" class="form-control input-sm" required>
                        <option value=""><?= lang('Accounts.apply_to_sale') ?></option>
                        <?php foreach ($outstanding_sales_all as $sale): ?>
                            <option value="<?= (int) $sale['sale_id'] ?>" data-outstanding="<?= (float) $sale['outstanding'] ?>">
                                Sale #<?= (int) $sale['sale_id'] ?> (<?= to_currency($sale['outstanding']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" min="0.01" name="amount" id="apply-credit-amount" class="form-control input-sm"
                           placeholder="<?= lang('Accounts.apply_credit') ?>"
                           max="<?= (float) ($customer_credit ?? 0) ?>" required>
                    <button type="submit" class="btn btn-sm btn-primary"><?= lang('Accounts.apply_credit') ?></button>
                    <small class="help-block" style="display:inline; margin-left:8px;">
                        <?= lang('Accounts.apply_credit_help') ?>
                    </small>
                <?= form_close() ?>
                <script>
                (function() {
                    const credit = <?= json_encode((float) ($customer_credit ?? 0)) ?>;
                    $('#apply-credit-sale').on('change', function() {
                        const outstanding = parseFloat($(this).find(':selected').data('outstanding')) || 0;
                        const maxApply = Math.min(credit, outstanding);
                        $('#apply-credit-amount').attr('max', maxApply).val(maxApply > 0 ? maxApply.toFixed(<?= totals_decimals() ?>) : '');
                    });
                })();
                </script>
            <?php endif; ?>
            <?php if ($can_refund): ?>
                <?= form_open('accounts/refundCredit', ['id' => 'refund-credit-form', 'class' => 'form-inline']) ?>
                    <input type="hidden" name="customer_id" value="<?= (int) $customer_id ?>">
                    <input type="hidden" name="idempotency_key" class="idempotency-key" value="">
                    <?= form_dropdown('payment_type', $payment_options, '', 'class="form-control input-sm"') ?>
                    <input type="number" step="0.01" name="amount" class="form-control input-sm" placeholder="<?= lang('Accounts.refund_credit') ?>" required>
                    <button type="submit" class="btn btn-sm btn-warning"><?= lang('Accounts.refund_credit') ?></button>
                <?= form_close() ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default account-section" id="section-tx">
    <div class="panel-heading"><?= lang('Accounts.account_transactions') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'prefix'              => 'transaction',
            'filters'             => $account_filters['transaction'],
            'search_name'         => 'transaction_search',
            'search_placeholder'  => lang('Accounts.transaction_search_placeholder'),
            'selects'             => [
                'transaction_type' => [
                    'label'   => lang('Accounts.filter_type'),
                    'key'     => 'type',
                    'choices' => $typeChoices,
                ],
                'transaction_side' => [
                    'label'   => lang('Accounts.filter_debit_credit'),
                    'key'     => 'side',
                    'choices' => [
                        ''       => lang('Accounts.filter_all'),
                        'debit'  => lang('Accounts.debit'),
                        'credit' => lang('Accounts.credit'),
                    ],
                ],
            ],
        ]) ?>
        <p class="section-meta text-muted"></p>
        <div class="section-loading" style="display:none;"><?= lang('Accounts.loading_records') ?></div>
        <div class="section-error" style="display:none;"></div>
        <div class="section-empty" style="display:none;"></div>
        <div class="section-table-wrap" style="display:none;">
            <table class="table table-striped table-hover">
                <thead>
                <tr>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th class="text-right"><?= lang('Accounts.debit') ?></th>
                    <th class="text-right"><?= lang('Accounts.credit') ?></th>
                    <th class="text-right"><?= lang('Accounts.balance') ?></th>
                </tr>
                </thead>
                <tbody class="section-body"></tbody>
            </table>
        </div>
        <div class="section-pager"></div>
        <button type="button" class="btn btn-default btn-sm section-load-more" style="display:none;"><?= lang('Accounts.load_more') ?></button>
    </div>
</div>

<div class="panel panel-default account-section" id="section-activity">
    <div class="panel-heading"><?= lang('Accounts.account_activity') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'prefix'              => 'activity',
            'filters'             => $account_filters['activity'],
            'search_name'         => 'activity_search',
            'search_placeholder'  => lang('Accounts.activity_search_placeholder'),
            'selects'             => [
                'activity_type' => [
                    'label'   => lang('Accounts.filter_type'),
                    'key'     => 'type',
                    'choices' => $typeChoices,
                ],
                'activity_voided' => [
                    'label'   => lang('Accounts.filter_voided'),
                    'key'     => 'voided',
                    'choices' => [
                        ''        => lang('Accounts.filter_all'),
                        'include' => lang('Accounts.filter_include_voided'),
                        'exclude' => lang('Accounts.filter_exclude_voided'),
                    ],
                ],
            ],
        ]) ?>
        <p class="section-meta text-muted"></p>
        <div class="section-loading" style="display:none;"><?= lang('Accounts.loading_records') ?></div>
        <div class="section-error" style="display:none;"></div>
        <div class="section-empty" style="display:none;"></div>
        <div class="section-table-wrap" style="display:none;">
            <table class="table table-striped table-hover">
                <thead>
                <tr>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th><?= lang('Common.comments') ?></th>
                </tr>
                </thead>
                <tbody class="section-body"></tbody>
            </table>
        </div>
        <div class="section-pager"></div>
        <button type="button" class="btn btn-default btn-sm section-load-more" style="display:none;"><?= lang('Accounts.load_more') ?></button>
    </div>
</div>

<div class="modal fade" id="reallocate-modal" tabindex="-1" role="dialog" aria-labelledby="reallocate-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="reallocate-form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="reallocate-title"><?= lang('Accounts.change_allocation') ?></h4>
                </div>
                <div class="modal-body">
                    <p id="reallocate-help" class="help-block"></p>
                    <p id="reallocate-payment-meta"></p>
                    <p><?= lang('Accounts.selected_total') ?>: <strong id="reallocate-required-total">0</strong>
                        <small>(<?= lang('Accounts.change_allocation_must_match') ?>)</small>
                    </p>
                    <div id="reallocate-error" class="alert alert-danger" style="display:none;"></div>
                    <input type="hidden" id="reallocate-payment-id" name="payment_id" value="">
                    <input type="hidden" id="reallocate-customer-id" name="customer_id" value="">
                    <div id="reallocate-sales"></div>
                    <div class="form-group" style="margin-top:12px;">
                        <label for="reallocate-reason"><?= lang('Accounts.reallocate_reason') ?> <span class="text-danger">*</span></label>
                        <textarea id="reallocate-reason" name="reallocate_reason" class="form-control" rows="3" required
                                  placeholder="<?= esc(lang('Accounts.reallocate_reason_placeholder'), 'attr') ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.close') ?></button>
                    <button type="submit" class="btn btn-primary" id="reallocate-submit"><?= lang('Accounts.change_allocation') ?></button>
                </div>
            </form>
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

