<?php
/**
 * @var object $person_info
 * @var int $customer_id
 * @var float $outstanding_balance
 * @var array $outstanding_sales
 * @var array $payable_sales
 * @var array $eligible_sales
 * @var array $consolidated_invoices
 * @var array $invoices_page
 * @var int $open_invoice_count
 * @var array $transactions
 * @var array $transactions_page
 * @var array $activity
 * @var array $activity_page
 * @var array $payment_options
 * @var array $reference_types
 * @var bool $can_pay
 * @var bool $can_consolidate
 * @var bool $can_cancel
 * @var array $status_labels
 * @var int $per_page
 */

$openInvoices = $open_invoices ?? array_values(array_filter(
    $consolidated_invoices,
    static fn ($i) => !in_array((int) $i['status'], [CI_STATUS_CANCELLED, CI_STATUS_PAID], true)
));
$defaultSaleId = count($payable_sales) === 1 ? (int) $payable_sales[0]['sale_id'] : '';
$account_filters = $account_filters ?? [
    'outstanding' => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'active' => false],
    'ci'          => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'balance' => '', 'page' => 1, 'active' => false],
    'ledger'      => ['search' => '', 'from' => '', 'to' => '', 'status' => '', 'page' => 1, 'active' => false],
    'transaction' => ['search' => '', 'from' => '', 'to' => '', 'type' => '', 'side' => '', 'page' => 1, 'active' => false],
    'activity'    => ['search' => '', 'from' => '', 'to' => '', 'type' => '', 'voided' => '', 'page' => 1, 'active' => false],
];
$outstanding_sales_all = $outstanding_sales_all ?? $outstanding_sales;
$viewBase = site_url('accounts/view/' . (int) $customer_id);
$filterParamMap = [
    'outstanding' => ['search' => 'outstanding_search', 'from' => 'outstanding_from', 'to' => 'outstanding_to', 'status' => 'outstanding_status'],
    'ci'          => ['search' => 'ci_search', 'from' => 'ci_from', 'to' => 'ci_to', 'status' => 'ci_status', 'balance' => 'ci_balance', 'page' => 'ci_page'],
    'ledger'      => ['search' => 'ledger_search', 'from' => 'ledger_from', 'to' => 'ledger_to', 'status' => 'ledger_status', 'page' => 'ledger_page'],
    'transaction' => ['search' => 'transaction_search', 'from' => 'transaction_from', 'to' => 'transaction_to', 'type' => 'transaction_type', 'side' => 'transaction_side', 'page' => 'transaction_page'],
    'activity'    => ['search' => 'activity_search', 'from' => 'activity_from', 'to' => 'activity_to', 'type' => 'activity_type', 'voided' => 'activity_voided', 'page' => 'activity_page'],
];
$buildFilterQuery = static function (?string $except = null) use ($account_filters, $filterParamMap): array {
    $query = [];
    foreach ($filterParamMap as $section => $fields) {
        if ($section === $except) {
            continue;
        }
        foreach ($fields as $key => $name) {
            $value = $account_filters[$section][$key] ?? '';
            if ($value === '' || $value === null || $key === 'active') {
                continue;
            }
            if ($key === 'page' && (int) $value <= 1) {
                continue;
            }
            $query[$name] = $value;
        }
    }

    return $query;
};
$filterResetUrl = static function (string $except) use ($viewBase, $buildFilterQuery): string {
    $query = $buildFilterQuery($except);

    return $query === [] ? $viewBase : $viewBase . '?' . http_build_query($query);
};
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
            if (applyTo === 'sale') {
                $('.apply-sale-group').show();
            } else if (applyTo === 'sales') {
                $('.apply-sales-group').show();
            } else if (applyTo === 'consolidated_invoice') {
                $('.apply-ci-group').show();
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

        $(document).on('click', '.void-payment', function() {
            const paymentId = $(this).data('payment-id');
            if (!paymentId || !confirm('<?= lang('Accounts.void_payment') ?>?')) {
                return;
            }
            $.post('<?= site_url('accounts/voidPayment') ?>/' + paymentId, {
                void_reason: '',
                idempotency_key: uuid()
            }, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });

        $(document).on('click', '.reallocate-payment', function() {
            const paymentId = $(this).data('payment-id');
            if (!paymentId) {
                return;
            }
            const $modal = $('#reallocate-modal');
            const $body = $('#reallocate-sales');
            $body.empty();
            $('#reallocate-help').text(<?= json_encode(lang('Accounts.change_allocation_help')) ?>);
            $('#reallocate-payment-id').val(paymentId);
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
            $.post('<?= site_url('accounts/reallocatePayment') ?>/' + paymentId, $(this).serialize(), function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
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
    .account-pager {
        margin-top: 8px;
    }
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
                <h2><?= to_currency($outstanding_balance) ?></h2>
                <p><?= lang('Accounts.number_outstanding_sales') ?>: <?= count($outstanding_sales_all) ?></p>
                <p><?= lang('Accounts.number_open_invoices') ?>: <?= (int) $open_invoice_count ?></p>
                <p><?= lang('Accounts.customer_credit') ?>: <strong><?= to_currency($customer_credit ?? 0) ?></strong></p>
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
                                $applyOptions = [
                                    'sale'                  => lang('Accounts.apply_to_sale'),
                                    'sales'                 => lang('Accounts.apply_to_multiple_sales'),
                                    'consolidated_invoice'  => lang('Accounts.apply_to_consolidated_invoice'),
                                ];
                                ?>
                                <?= form_dropdown('apply_to', $applyOptions, $defaultSaleId !== '' ? 'sale' : '', 'class="form-control input-sm" id="apply_to" required') ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm apply-sale-group" style="display:none;">
                            <?= form_label(lang('Accounts.apply_to_sale'), 'sale_id', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-5">
                                <?php
                                $saleOptions = ['' => ''];
                                foreach ($payable_sales as $sale) {
                                    $saleOptions[(int) $sale['sale_id']] = '#' . $sale['sale_id'] . ' — ' . to_currency($sale['outstanding']);
                                }
                                ?>
                                <?= form_dropdown('sale_id', $saleOptions, $defaultSaleId, 'class="form-control input-sm" id="sale_id"') ?>
                            </div>
                        </div>
                        <div class="form-group form-group-sm apply-sales-group" style="display:none;">
                            <?= form_label(lang('Accounts.apply_to_multiple_sales'), 'allocations', ['class' => 'control-label col-xs-3']) ?>
                            <div class="col-xs-8">
                                <?php foreach ($payable_sales as $index => $sale): ?>
                                    <div class="row" style="margin-bottom:4px;">
                                        <div class="col-xs-6">
                                            <input type="hidden" name="allocations[<?= $index ?>][sale_id]" value="<?= (int) $sale['sale_id'] ?>">
                                            Sale #<?= (int) $sale['sale_id'] ?> (<?= to_currency($sale['outstanding']) ?>)
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
                                $ciOptions = ['' => ''];
                                foreach ($openInvoices as $invoice) {
                                    $ciOptions[(int) $invoice['consolidated_invoice_id']] =
                                        $invoice['invoice_number'] . ' — ' . to_currency($invoice['balance']);
                                }
                                ?>
                                <?= form_dropdown('consolidated_invoice_id', $ciOptions, '', 'class="form-control input-sm" id="consolidated_invoice_id"') ?>
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

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.outstanding_sales') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'action'              => $viewBase,
            'prefix'              => 'outstanding',
            'filters'             => $account_filters['outstanding'],
            'all_filters'         => $account_filters,
            'search_name'         => 'outstanding_search',
            'search_placeholder'  => lang('Accounts.outstanding_search_placeholder'),
            'reset_url'           => $filterResetUrl('outstanding'),
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
        <?php if ($outstanding_sales === []): ?>
            <p><?= !empty($account_filters['outstanding']['active'])
                ? lang('Accounts.no_outstanding_sales_filtered')
                : lang('Accounts.no_outstanding_sales') ?></p>
        <?php else: ?>
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
                <tbody>
                <?php foreach ($outstanding_sales as $sale): ?>
                    <tr>
                        <td><?= (int) $sale['sale_id'] ?></td>
                        <td><?= esc(to_datetime(strtotime($sale['sale_time']))) ?></td>
                        <td><?= esc($sale['invoice_number'] ?? '') ?></td>
                        <td class="text-right"><?= to_currency($sale['total']) ?></td>
                        <td class="text-right"><?= to_currency($sale['paid']) ?></td>
                        <td class="text-right"><?= to_currency($sale['outstanding']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.consolidated_invoices') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'action'              => $viewBase,
            'prefix'              => 'ci',
            'filters'             => $account_filters['ci'],
            'all_filters'         => $account_filters,
            'search_name'         => 'ci_search',
            'search_placeholder'  => lang('Accounts.ci_search_placeholder'),
            'reset_url'           => $filterResetUrl('ci'),
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
        <p id="ci-meta">
            <?= lang('Accounts.showing_rows') ?>
            <?= $invoices_page['total'] ? (($invoices_page['page'] - 1) * $invoices_page['per_page'] + 1) : 0 ?>
            –<?= min($invoices_page['page'] * $invoices_page['per_page'], $invoices_page['total']) ?>
            <?= lang('Accounts.pagination_of') ?> <?= (int) $invoices_page['total'] ?>
        </p>
        <?php if ($consolidated_invoices === []): ?>
            <p><?= !empty($account_filters['ci']['active'])
                ? lang('Accounts.no_consolidated_invoices_filtered')
                : lang('Accounts.no_consolidated_invoices') ?></p>
        <?php else: ?>
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
                <tbody id="ci-body">
                <?php foreach ($consolidated_invoices as $invoice): ?>
                    <tr>
                        <td><?= esc($invoice['invoice_number']) ?></td>
                        <td><?= esc($invoice['invoice_date']) ?></td>
                        <td><?= esc($status_labels[(int) $invoice['status']] ?? $invoice['status']) ?></td>
                        <td class="text-right"><?= to_currency($invoice['total_amount']) ?></td>
                        <td class="text-right"><?= to_currency($invoice['paid']) ?></td>
                        <td class="text-right"><?= to_currency($invoice['balance']) ?></td>
                        <td><?= anchor('accounts/invoice/' . $invoice['consolidated_invoice_id'], lang('Accounts.view_account')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= view('accounts/partials/section_pager', [
                'page_data'  => $invoices_page,
                'page_param' => 'ci_page',
                'query'      => $buildFilterQuery(),
                'base_url'   => $viewBase,
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.ledger') ?></div>
    <div class="panel-body">
        <?php
        $ledger = $ledger ?? [];
        $ledger_page = $ledger_page ?? ['total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 1];
        $can_void = $can_void ?? false;
        $can_reallocate = $can_reallocate ?? false;
        $can_credit_apply = $can_credit_apply ?? false;
        $can_refund = $can_refund ?? false;
        $customer_credit = $customer_credit ?? 0;
        ?>
        <?= view('accounts/partials/section_filters', [
            'action'              => $viewBase,
            'prefix'              => 'ledger',
            'filters'             => $account_filters['ledger'],
            'all_filters'         => $account_filters,
            'search_name'         => 'ledger_search',
            'search_placeholder'  => lang('Accounts.ledger_search_placeholder'),
            'reset_url'           => $filterResetUrl('ledger'),
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
        <p id="ledger-meta">
            <?= lang('Accounts.showing_rows') ?>
            <?= $ledger_page['total'] ? (($ledger_page['page'] - 1) * $ledger_page['per_page'] + 1) : 0 ?>
            –<?= min($ledger_page['page'] * $ledger_page['per_page'], $ledger_page['total']) ?>
            <?= lang('Accounts.pagination_of') ?> <?= (int) $ledger_page['total'] ?>
        </p>
        <?php if ($ledger === []): ?>
            <p><?= !empty($account_filters['ledger']['active'])
                ? lang('Accounts.no_ledger_records_filtered')
                : lang('Accounts.no_ledger_records') ?></p>
        <?php else: ?>
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
                <tbody id="ledger-body">
                <?php foreach ($ledger as $row): ?>
                    <tr class="ledger-parent" data-sale-id="<?= (int) $row['sale_id'] ?>">
                        <td><button type="button" class="btn btn-xs btn-default ledger-toggle">+</button></td>
                        <td><?= esc(to_datetime(strtotime((string) $row['date']))) ?></td>
                        <td><?= esc($row['reference']) ?></td>
                        <td class="text-right"><?= to_currency($row['sale_total']) ?></td>
                        <td class="text-right"><?= to_currency($row['payments']) ?></td>
                        <td class="text-right"><?= to_currency($row['credits']) ?></td>
                        <td class="text-right"><?= to_currency($row['balance']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                    </tr>
                    <?php foreach ($row['children'] ?? [] as $child): ?>
                        <tr class="ledger-child" data-parent="<?= (int) $row['sale_id'] ?>" style="display:none; background:#f9f9f9;">
                            <td></td>
                            <td><?= esc(to_datetime(strtotime((string) ($child['date'] ?? '')))) ?></td>
                            <td colspan="2">
                                <?= esc($child['reference'] ?? '') ?>
                                <small>(<?= esc($child['source'] ?? '') ?>)</small>
                            </td>
                            <td class="text-right" colspan="2"><?= to_currency($child['amount'] ?? $child['credit'] ?? 0) ?></td>
                            <td colspan="2">
                                <?php if (!empty($child['can_void']) && $can_void && ($child['source'] ?? '') === 'account'): ?>
                                    <button type="button" class="btn btn-xs btn-danger void-payment" data-payment-id="<?= (int) ($child['payment_id'] ?? 0) ?>"><?= lang('Accounts.void_payment') ?></button>
                                <?php endif; ?>
                                <?php if (!empty($child['can_reallocate']) && $can_reallocate && ($child['source'] ?? '') === 'account'): ?>
                                    <button type="button" class="btn btn-xs btn-warning reallocate-payment" data-payment-id="<?= (int) ($child['payment_id'] ?? 0) ?>" title="<?= esc(lang('Accounts.change_allocation_help')) ?>"><?= lang('Accounts.change_allocation') ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= view('accounts/partials/section_pager', [
                'page_data'  => $ledger_page,
                'page_param' => 'ledger_page',
                'query'      => $buildFilterQuery(),
                'base_url'   => $viewBase,
            ]) ?>
        <?php endif; ?>

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

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.account_transactions') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'action'              => $viewBase,
            'prefix'              => 'transaction',
            'filters'             => $account_filters['transaction'],
            'all_filters'         => $account_filters,
            'search_name'         => 'transaction_search',
            'search_placeholder'  => lang('Accounts.transaction_search_placeholder'),
            'reset_url'           => $filterResetUrl('transaction'),
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
        <p id="tx-meta">
            <?= lang('Accounts.showing_rows') ?>
            <?= $transactions_page['total'] ? (($transactions_page['page'] - 1) * $transactions_page['per_page'] + 1) : 0 ?>
            –<?= min($transactions_page['page'] * $transactions_page['per_page'], $transactions_page['total']) ?>
            <?= lang('Accounts.pagination_of') ?> <?= (int) $transactions_page['total'] ?>
        </p>
        <?php if ($transactions === []): ?>
            <p><?= !empty($account_filters['transaction']['active'])
                ? lang('Accounts.no_account_transactions_filtered')
                : lang('Accounts.no_account_transactions') ?></p>
        <?php else: ?>
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
                <tbody id="tx-body">
                <?php foreach ($transactions as $row): ?>
                    <tr>
                        <td><?= esc(to_datetime(strtotime((string) $row['date']))) ?></td>
                        <td>
                            <?= esc($row['reference']) ?>
                            <?php if (!empty($row['allocated_to'])): ?>
                                <br><small><?= esc($row['allocated_to']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($row['memo'])): ?>
                                <br><small><?= esc($row['memo']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= $row['debit'] ? to_currency($row['debit']) : '' ?></td>
                        <td class="text-right"><?= $row['credit'] ? to_currency($row['credit']) : '' ?></td>
                        <td class="text-right"><?= to_currency($row['balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= view('accounts/partials/section_pager', [
                'page_data'  => $transactions_page,
                'page_param' => 'transaction_page',
                'query'      => $buildFilterQuery(),
                'base_url'   => $viewBase,
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><?= lang('Accounts.account_activity') ?></div>
    <div class="panel-body">
        <?= view('accounts/partials/section_filters', [
            'action'              => $viewBase,
            'prefix'              => 'activity',
            'filters'             => $account_filters['activity'],
            'all_filters'         => $account_filters,
            'search_name'         => 'activity_search',
            'search_placeholder'  => lang('Accounts.activity_search_placeholder'),
            'reset_url'           => $filterResetUrl('activity'),
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
        <p id="activity-meta">
            <?= lang('Accounts.showing_rows') ?>
            <?= $activity_page['total'] ? (($activity_page['page'] - 1) * $activity_page['per_page'] + 1) : 0 ?>
            –<?= min($activity_page['page'] * $activity_page['per_page'], $activity_page['total']) ?>
            <?= lang('Accounts.pagination_of') ?> <?= (int) $activity_page['total'] ?>
        </p>
        <?php if ($activity === []): ?>
            <p><?= !empty($account_filters['activity']['active'])
                ? lang('Accounts.no_account_activity_filtered')
                : lang('Accounts.no_account_activity') ?></p>
        <?php else: ?>
            <table class="table table-striped table-hover">
                <thead>
                <tr>
                    <th><?= lang('Reports.date') ?></th>
                    <th><?= lang('Accounts.reference') ?></th>
                    <th><?= lang('Common.comments') ?></th>
                </tr>
                </thead>
                <tbody id="activity-body">
                <?php foreach ($activity as $row): ?>
                    <tr>
                        <td><?= esc($row['date']) ?></td>
                        <td><?= esc($row['reference']) ?></td>
                        <td><?= esc($row['memo'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?= view('accounts/partials/section_pager', [
                'page_data'  => $activity_page,
                'page_param' => 'activity_page',
                'query'      => $buildFilterQuery(),
                'base_url'   => $viewBase,
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<?= view('partial/footer') ?>

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
                    <input type="hidden" id="reallocate-payment-id" value="">
                    <div id="reallocate-sales"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?= lang('Common.close') ?></button>
                    <button type="submit" class="btn btn-primary"><?= lang('Accounts.change_allocation') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
