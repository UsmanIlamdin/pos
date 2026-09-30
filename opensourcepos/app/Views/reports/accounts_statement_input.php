<?php
/**
 * @var string $specific_input_name
 * @var array $specific_input_data
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<div id="page_title"><?= lang('Reports.report_input') ?></div>

<?= form_open('#', ['id' => 'item_form', 'class' => 'form-horizontal']) ?>
    <div class="form-group form-group-sm" id="report_specific_input_data">
        <?= form_label($specific_input_name, 'specific_input_name_label', ['class' => 'required control-label col-xs-2']) ?>
        <div class="col-xs-3">
            <?= form_dropdown('specific_input_data', $specific_input_data, '', 'id="specific_input_data" class="form-control selectpicker" data-live-search="true"') ?>
        </div>
    </div>

    <?php
    echo form_button([
        'name'    => 'generate_report',
        'id'      => 'generate_report',
        'content' => lang('Common.submit'),
        'class'   => 'btn btn-primary btn-sm'
    ]);
    ?>
<?= form_close() ?>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $("#generate_report").click(function() {
            // Empty dropdown = All Customers; use "all" in the URL path.
            const customerId = $('#specific_input_data').val() || 'all';
            window.location = [window.location.href.replace(/\/$/, ''), customerId].join('/');
        });
    });
</script>
