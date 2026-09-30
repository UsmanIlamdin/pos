<?php
/**
 * GET pagination that preserves current filter query params.
 *
 * @var array $page_data
 * @var string $page_param
 * @var array $query
 * @var string $base_url
 */

$pageData = $page_data ?? ['page' => 1, 'total_pages' => 1, 'total' => 0];
$totalPages = (int) ($pageData['total_pages'] ?? 1);
$current = (int) ($pageData['page'] ?? 1);
if ($totalPages <= 1) {
    return;
}
$query = $query ?? [];
unset($query[$page_param]);
?>
<div class="account-pager">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php
        $params = $query;
        if ($i > 1) {
            $params[$page_param] = $i;
        }
        $href = $base_url . ($params !== [] ? ('?' . http_build_query($params)) : '');
        ?>
        <a href="<?= esc($href) ?>" class="btn btn-xs <?= $i === $current ? 'btn-primary' : 'btn-default' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
