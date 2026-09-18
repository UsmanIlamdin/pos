<?php

/**
 * Docker-only CI4 factories-cache guard.
 *
 * OpenSourcePOS Config\OSPOS stores a Cache FileHandler instance. When CI4
 * config caching is enabled, that object is var_exported into
 * writable/cache/FactoriesCache_config as FileHandler::__set_state(...).
 * FileHandler does not implement __set_state(), so the *next* request fatals
 * during Boot::loadConfigCache() — before logging — as HTTP 500 + empty JSON.
 *
 * This runs as auto_prepend_file (see docker/php/php.ini) and removes a
 * poisoned factories cache *before* CodeIgniter boots. Application source
 * under opensourcepos/ is left unchanged.
 */
$factoriesCache = '/app/writable/cache/FactoriesCache_config';

if (!is_file($factoriesCache)) {
    return;
}

$snippet = @file_get_contents($factoriesCache, false, null, 0, 65536);
if ($snippet === false) {
    return;
}

if (str_contains($snippet, 'Cache\\Handlers\\FileHandler::__set_state')
    || str_contains($snippet, 'Cache\Handlers\FileHandler::__set_state')) {
    @unlink($factoriesCache);
}
