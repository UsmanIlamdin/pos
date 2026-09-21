<?php

namespace Config;

/**
 * Docker overlay for Config\Optimize — keeps opensourcepos/ source untouched.
 *
 * Config caching MUST stay disabled for OSPOS under Docker:
 * - App::$baseURL is built from HTTP_HOST at construct time. Caching freezes it
 *   (often as https://localhost/ from the Apache healthcheck), which then breaks
 *   CSP connect-src 'self' when you browse via APP_DOMAIN (e.g. ghazi-pos.local).
 * - OSPOS embeds Cache\FileHandler in Config\OSPOS; var_export of that graph
 *   throws "circular references" and returns HTTP 500 on routes like /reports.
 *
 * Locator cache remains enabled (safe; no request-host dependency).
 * ci4-cache-guard.php still strips any leftover poisoned FactoriesCache files.
 *
 * @see https://codeigniter.com/user_guide/concepts/factories.html#config-caching
 */
class Optimize
{
    public bool $configCacheEnabled = false;

    public bool $locatorCacheEnabled = true;
}
