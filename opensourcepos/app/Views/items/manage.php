<?php
/**
 * @var string $controller_name
 * @var string $table_headers
 * @var array $filters
 * @var array $stock_locations
 * @var int $stock_location
 * @var array $config
 * @var string|null $start_date
 * @var string|null $end_date
 * @var array $selected_filters
 */

use App\Models\Employee;
?>

<?= view('partial/header') ?>

<style>
    #table_holder .fixed-table-container thead th,
    #table_holder .fixed-table-header thead th {
        overflow: visible;
    }

    #table_holder thead th .th-inner {
        white-space: nowrap;
    }

    #table_holder thead th .attribute-header-filter-wrap {
        display: inline-block;
        vertical-align: middle;
        margin-left: 6px;
        max-width: 160px;
    }

    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select {
        width: auto !important;
    }

    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select > .btn,
    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select > .btn:hover,
    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select > .btn:focus,
    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select > .btn:active {
        outline: none !important;
        box-shadow: none !important;
    }

    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select:not(.open) > .btn,
    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select:not(.open) > .btn:hover,
    #table_holder thead th .attribute-header-filter-wrap .bootstrap-select:not(.open) > .btn:focus {
        background-color: #fff !important;
        border-color: #ccc !important;
        color: #333 !important;
    }
</style>

<script type="text/javascript">
    $(document).ready(function() {
        $('#generate_barcodes').click(function() {
            window.open(
                'index.php/items/generateBarcodes/' + table_support.selected_ids().join(':'),
                '_blank'
            );
        });

        // Load the preset daterange picker
        <?= view('partial/daterangepicker') ?>
        // Set the beginning of time as starting date
        $('#daterangepicker').data('daterangepicker').setStartDate("<?= date($config['dateformat'], mktime(0, 0, 0, 01, 01, 2010)) ?>");
        // Update the hidden inputs with the selected dates before submitting the search data
        start_date = "<?= date('Y-m-d', mktime(0, 0, 0, 01, 01, 2010)) ?>";

        // Override dates from server if provided
        <?php if (isset($start_date) && $start_date): ?>
        start_date = "<?= esc($start_date) ?>";
        <?php endif; ?>
        <?php if (isset($end_date) && $end_date): ?>
        end_date = "<?= esc($end_date) ?>";
        <?php endif; ?>

        <?php
        echo view('partial/bootstrap_tables_locale');
        $employee = model(Employee::class);
        ?>

        $('#daterangepicker').on('apply.daterangepicker', function(ev, picker) {
            table_support.refresh();
        });

        $('#filters').on('hidden.bs.select', function(e) {
            table_support.refresh();
        });

        $('#stock_location').on('change', function(e) {
            table_support.refresh();
        });

        // Attribute column filters (DROPDOWN / CHECKBOX) — values keyed by definition id
        let attribute_column_filters = {};

        /**
         * Fully close header selectpickers.
         * With data-container="body", the menu is moved into a .bs-container on body —
         * removing only the "open" class leaves that menu visible and the btn :focus style stuck.
         */
        const close_attribute_header_filters = function() {
            $('.attribute-header-filter').each(function() {
                const picker = $(this).data('selectpicker');
                if (!picker) {
                    return;
                }

                picker.$newElement.removeClass('open show');
                picker.$button
                    .removeClass('open show active')
                    .attr('aria-expanded', 'false')
                    .blur();

                if (picker.$bsContainer && picker.$bsContainer.length) {
                    picker.$bsContainer.removeClass('open show').detach();
                }

                // Restore menu under the picker for the next open
                if (picker.$menu && picker.$menu.length && !picker.$newElement.find('.dropdown-menu').length) {
                    picker.$newElement.append(picker.$menu);
                }
            });

            // Orphaned body containers from destroyed/rebuilt header pickers
            $('body > .bs-container').remove();
        };

        const init_attribute_header_filters = function() {
            const tableOptions = $('#table').bootstrapTable('getOptions');
            if (!tableOptions || !tableOptions.columns) {
                return;
            }

            const columns = [].concat.apply([], tableOptions.columns);

            columns.forEach(function(col) {
                if (!col || !col.headerFilter || !col.headerFilter.options) {
                    return;
                }

                const field = String(col.field);
                // Only the visible sticky/fixed header — avoid duplicate pickers on the hidden clone
                const $ths = $('#table_holder .fixed-table-header th[data-field="' + field + '"]');
                const $targetThs = $ths.length
                    ? $ths
                    : $('#table_holder th[data-field="' + field + '"]');

                $targetThs.each(function() {
                    const $th = $(this);
                    if ($th.find('.attribute-header-filter-wrap').length) {
                        return;
                    }

                    const $inner = $th.find('.th-inner').first();
                    if (!$inner.length) {
                        return;
                    }

                    const $wrap = $('<span class="attribute-header-filter-wrap"></span>');
                    const $select = $('<select></select>')
                        .addClass('selectpicker show-menu-arrow attribute-header-filter')
                        .attr({
                            'data-style': 'btn-default btn-sm',
                            'data-width': 'fit',
                            'data-none-selected-text': <?= json_encode(lang('Common.none_selected_text')) ?>,
                            'data-container': 'body'
                        });

                    $select.append($('<option></option>').attr('value', '').text(''));
                    $.each(col.headerFilter.options, function(value, label) {
                        $select.append($('<option></option>').attr('value', value).text(label));
                    });

                    if (attribute_column_filters[field] !== undefined) {
                        $select.val(attribute_column_filters[field]);
                    }

                    $wrap.append($select);
                    $inner.append($wrap);

                    $select.selectpicker();

                    // Prevent column sort when clicking the picker button only
                    $wrap.on('click', function(e) {
                        e.stopPropagation();
                    });

                    $select.on('changed.bs.select', function() {
                        const val = $(this).val();
                        if (val === '' || val === null) {
                            delete attribute_column_filters[field];
                        } else {
                            attribute_column_filters[field] = val;
                        }

                        close_attribute_header_filters();

                        setTimeout(function() {
                            table_support.refresh();
                        }, 0);
                    });
                });
            });
        };

        // Outside click / Escape closes menu + clears stuck button styles
        $(document).on('mousedown.attributeHeaderFilter', function(e) {
            const $t = $(e.target);
            if ($t.closest('.attribute-header-filter-wrap, .bs-container').length) {
                return;
            }
            close_attribute_header_filters();
        });

        // Option click in the body-attached menu (covers same-value re-click too)
        $(document).on('click.attributeHeaderFilterOption', 'body > .bs-container li a', function() {
            setTimeout(close_attribute_header_filters, 0);
        });

        $(document).on('keydown.attributeHeaderFilter', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                close_attribute_header_filters();
            }
        });

        table_support.init({
            employee_id: <?= $employee->get_logged_in_employee_info()->person_id ?>,
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'items.item_id',
            filterControl: false,
            queryParams: function() {
                return $.extend(arguments[0], {
                    "start_date": start_date,
                    "end_date": end_date,
                    "stock_location": $("#stock_location").val(),
                    "filters": $("#filters").val(),
                    "filter": JSON.stringify(attribute_column_filters)
                });
            },
            onPostHeader: function() {
                close_attribute_header_filters();
                init_attribute_header_filters();
            },
            onLoadSuccess: function(response) {
                $('a.rollover').imgPreview({
                    imgCSS: {
                        width: 200
                    },
                    distanceFromCursor: {
                        top: 10,
                        left: -210
                    }
                });
                init_attribute_header_filters();
            }
        });
    });
</script>

<?= view('partial/table_filter_persistence', ['additional_params' => ['stock_location']]) ?>

<div id="title_bar" class="btn-toolbar print_hide">
    <button class="btn btn-info btn-sm pull-right modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/csvImport" ?>" title="<?= lang('Items.import_items_csv') ?>">
        <span class="glyphicon glyphicon-import">&nbsp;</span><?= lang('Common.import_csv') ?>
    </button>

    <button class="btn btn-info btn-sm pull-right modal-dlg" data-btn-new="<?= lang('Common.new') ?>" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/view" ?>" title="<?= lang(ucfirst($controller_name) . '.new') ?>">
        <span class="glyphicon glyphicon-tag">&nbsp;</span><?= lang(ucfirst($controller_name) . '.new') ?>
    </button>
</div>

<div id="toolbar">
    <div class="pull-left form-inline" role="toolbar">
        <button id="delete" class="btn btn-default btn-sm print_hide">
            <span class="glyphicon glyphicon-trash">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
        <button id="bulk_edit" class="btn btn-default btn-sm modal-dlg print_hide" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "items/bulkEdit" ?>" title="<?= lang('Items.edit_multiple_items') ?>">
            <span class="glyphicon glyphicon-edit">&nbsp;</span><?= lang('Items.bulk_edit') ?>
        </button>
        <button id="generate_barcodes" class="btn btn-default btn-sm print_hide" data-href="<?= "$controller_name/generateBarcodes" ?>" title="<?= lang('Items.generate_barcodes') ?>">
            <span class="glyphicon glyphicon-barcode">&nbsp;</span><?= lang('Items.generate_barcodes') ?>
        </button>
        <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control input-sm', 'id' => 'daterangepicker']) ?>
        <?= form_multiselect('filters[]', $filters, $selected_filters ?? [], [
            'id'                        => 'filters',
            'class'                     => 'selectpicker show-menu-arrow',
            'data-none-selected-text'   => lang('Common.none_selected_text'),
            'data-selected-text-format' => 'count > 1',
            'data-style'                => 'btn-default btn-sm',
            'data-width'                => 'fit'
        ]) ?>
        <?php
        if (count($stock_locations) > 1) {
            echo form_dropdown(
                'stock_location',
                $stock_locations,
                $stock_location,
                [
                    'id'         => 'stock_location',
                    'class'      => 'selectpicker show-menu-arrow',
                    'data-style' => 'btn-default btn-sm',
                    'data-width' => 'fit'
                ]
            );
        }
        ?>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
