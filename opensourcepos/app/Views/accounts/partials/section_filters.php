<?php
/**
 * Shared account-view filter toolbar.
 *
 * @var string $action
 * @var string $prefix
 * @var array $filters
 * @var array $all_filters
 * @var string $search_name
 * @var string $search_placeholder
 * @var array<string, array<string,string>> $selects  [name => [value => label]]
 * @var string $reset_url
 */

$filters = $filters ?? [];
$allFilters = $all_filters ?? [];
$selects = $selects ?? [];
$paramMap = [
    'outstanding' => [
        'search' => 'outstanding_search',
        'from'   => 'outstanding_from',
        'to'     => 'outstanding_to',
        'status' => 'outstanding_status',
    ],
    'ci' => [
        'search'  => 'ci_search',
        'from'    => 'ci_from',
        'to'      => 'ci_to',
        'status'  => 'ci_status',
        'balance' => 'ci_balance',
        'page'    => 'ci_page',
    ],
    'ledger' => [
        'search' => 'ledger_search',
        'from'   => 'ledger_from',
        'to'     => 'ledger_to',
        'status' => 'ledger_status',
        'page'   => 'ledger_page',
    ],
    'transaction' => [
        'search' => 'transaction_search',
        'from'   => 'transaction_from',
        'to'     => 'transaction_to',
        'type'   => 'transaction_type',
        'side'   => 'transaction_side',
        'page'   => 'transaction_page',
    ],
    'activity' => [
        'search' => 'activity_search',
        'from'   => 'activity_from',
        'to'     => 'activity_to',
        'type'   => 'activity_type',
        'voided' => 'activity_voided',
        'page'   => 'activity_page',
    ],
];
?>
<form method="get" action="<?= esc($action) ?>" class="account-filter-form">
    <?php foreach ($paramMap as $section => $fields): ?>
        <?php if ($section === $prefix) {
            continue;
        } ?>
        <?php foreach ($fields as $key => $name): ?>
            <?php
            $value = $allFilters[$section][$key] ?? '';
            if ($value === '' || $value === null || $key === 'active') {
                continue;
            }
            ?>
            <input type="hidden" name="<?= esc($name) ?>" value="<?= esc((string) $value) ?>">
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="account-filter-toolbar">
        <div class="account-filter-field">
            <label><?= lang('Common.search') ?></label>
            <input type="text" name="<?= esc($search_name) ?>" class="form-control input-sm"
                   value="<?= esc((string) ($filters['search'] ?? '')) ?>"
                   placeholder="<?= esc($search_placeholder) ?>">
        </div>
        <?php foreach ($selects as $selectName => $options): ?>
            <div class="account-filter-field">
                <label><?= esc($options['label'] ?? '') ?></label>
                <select name="<?= esc($selectName) ?>" class="form-control input-sm">
                    <?php foreach ($options['choices'] as $value => $label): ?>
                        <option value="<?= esc((string) $value) ?>" <?= ((string) ($filters[$options['key']] ?? '') === (string) $value) ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <div class="account-filter-field">
            <label><?= lang('Accounts.filter_from') ?></label>
            <input type="date" name="<?= esc($prefix) ?>_from" class="form-control input-sm"
                   value="<?= esc((string) ($filters['from'] ?? '')) ?>">
        </div>
        <div class="account-filter-field">
            <label><?= lang('Accounts.filter_to') ?></label>
            <input type="date" name="<?= esc($prefix) ?>_to" class="form-control input-sm"
                   value="<?= esc((string) ($filters['to'] ?? '')) ?>">
        </div>
        <div class="account-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounts.filter_apply') ?></button>
            <a href="<?= esc($reset_url) ?>" class="btn btn-default btn-sm"><?= lang('Accounts.filter_reset') ?></a>
        </div>
    </div>
</form>
