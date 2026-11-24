<?php

declare(strict_types=1);

/**
 * Entry type named by plugin.json `class`.
 *
 * The loader constructs the class and calls boot() once per request while the
 * plugin is enabled. Register routes, hooks, and studio contributions there.
 * Everything a plugin may call on the host is a method of PluginApi.
 */
interface SneezePlugin
{
    public function boot(PluginApi $api): void;
}
