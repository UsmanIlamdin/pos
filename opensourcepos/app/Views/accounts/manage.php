<?php
/**
 * @var string $controller_name
 * @var string $table_headers
 * @var array $config
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= (int) $config['lines_per_page'] ?>,
            uniqueId: 'people.person_id'
        });
    });
</script>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
