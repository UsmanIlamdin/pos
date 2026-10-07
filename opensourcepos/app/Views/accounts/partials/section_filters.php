<?php
/**
 * Shared account-view filter toolbar (AJAX-friendly).
 *
 * @var string $prefix
 * @var array $filters
 * @var string $search_name
 * @var string $search_placeholder
 * @var array<string, array<string,mixed>> $selects
 */

$filters = $filters ?? [];
$selects = $selects ?? [];
?>
<form class="account-filter-form" data-section="<?= esc($prefix) ?>" onsubmit="return false;">
    <div class="account-filter-toolbar">
        <div class="account-filter-field">
            <label><?= lang('Common.search') ?></label>
            <input type="text" name="<?= esc($search_name) ?>" class="form-control input-sm filter-search"
                   value="<?= esc((string) ($filters['search'] ?? '')) ?>"
                   placeholder="<?= esc($search_placeholder) ?>"
                   autocomplete="off">
        </div>
        <?php foreach ($selects as $selectName => $options): ?>
            <div class="account-filter-field">
                <label><?= esc($options['label'] ?? '') ?></label>
                <select name="<?= esc($selectName) ?>" class="form-control input-sm filter-select" data-filter-key="<?= esc($options['key'] ?? '') ?>">
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
            <input type="date" name="<?= esc($prefix) ?>_from" class="form-control input-sm filter-from"
                   value="<?= esc((string) ($filters['from'] ?? '')) ?>">
        </div>
        <div class="account-filter-field">
            <label><?= lang('Accounts.filter_to') ?></label>
            <input type="date" name="<?= esc($prefix) ?>_to" class="form-control input-sm filter-to"
                   value="<?= esc((string) ($filters['to'] ?? '')) ?>">
        </div>
        <div class="account-filter-actions">
            <button type="button" class="btn btn-primary btn-sm filter-apply"><?= lang('Accounts.filter_apply') ?></button>
            <button type="button" class="btn btn-default btn-sm filter-reset"><?= lang('Accounts.filter_reset') ?></button>
        </div>
    </div>
</form>
