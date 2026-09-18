<?php

namespace Config;

/**
 * Docker overlay for Config\Optimize — keeps opensourcepos/ source untouched.
 *
 * Config caching stays ENABLED (same intent as upstream CI4 Optimize defaults).
 * Poisoned FactoriesCache files are stripped by docker/php/ci4-cache-guard.php
 * before boot so OSPOS's embedded FileHandler cannot crash the next request.
 *
 * @see https://codeigniter.com/user_guide/concepts/factories.html#config-caching
 */
class Optimize
{
    public bool $configCacheEnabled = true;

    public bool $locatorCacheEnabled = true;
}
