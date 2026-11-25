<?php

declare(strict_types=1);

/**
 * Core hook catalog and dispatcher.
 *
 * PluginHooks::catalog() is the contract a future documentation page should
 * render. Plugins may also emit their own dotted names through PluginApi::apply()
 * and PluginApi::do(). Those names are not part of this catalog.
 *
 * A listener that throws is recorded and skipped. The value passed in is kept.
 */
class PluginHooks
{
    public const COMPILE_CONTEXT = 'compile.context';
    public const COMPILE_BLOCKS = 'compile.blocks';
    public const COMPILE_ASSETS = 'compile.assets';
    public const COMPILE_HTML = 'compile.html';
    public const BLOCK_HTML = 'block.html';
    public const PUBLISH_BEFORE = 'publish.before';
    public const PUBLISH_AFTER = 'publish.after';

    /**
     * @var array<string, list<array{priority: int, seq: int, plugin: string, kind: string, fn: callable}>>
     */
    private static array $listeners = [];

    private static int $seq = 0;

    /** @var array<string, string> */
    private static array $errors = [];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::COMPILE_CONTEXT,
            self::COMPILE_BLOCKS,
            self::COMPILE_ASSETS,
            self::COMPILE_HTML,
            self::BLOCK_HTML,
            self::PUBLISH_BEFORE,
            self::PUBLISH_AFTER,
        ];
    }

    /**
     * @return list<array{name: string, kind: string, summary: string, arguments: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'name' => self::COMPILE_CONTEXT,
                'kind' => 'filter',
                'summary' => 'Template context before a page, post, or post index is rendered.',
                'arguments' => 'value: array context; context: {page: array, preview: bool}',
            ],
            [
                'name' => self::COMPILE_BLOCKS,
                'kind' => 'filter',
                'summary' => 'Block tree of a page before it is rendered. Not fired for posts.',
                'arguments' => 'value: list block tree; context: {page: array, preview: bool}',
            ],
            [
                'name' => self::COMPILE_ASSETS,
                'kind' => 'filter',
                'summary' => 'Collected CSS and JS after TypeScript and Tailwind run, before they are injected.',
                'arguments' => 'value: {css: string, jsHead: string, jsFoot: string}; context: {page: array, preview: bool, html: string}',
            ],
            [
                'name' => self::COMPILE_HTML,
                'kind' => 'filter',
                'summary' => 'Final HTML of a page, post, or post index, including preview chrome when preview is true.',
                'arguments' => 'value: string html; context: {page: array, preview: bool}',
            ],
            [
                'name' => self::BLOCK_HTML,
                'kind' => 'filter',
                'summary' => 'HTML of one block before preview selection chrome is wrapped around it.',
                'arguments' => 'value: string html; context: {preview: bool, block: {id: int|string, slug: string, slot: string}}',
            ],
            [
                'name' => self::PUBLISH_BEFORE,
                'kind' => 'action',
                'summary' => 'Runs before a page is compiled and written.',
                'arguments' => 'context: {page_id: int, user_id: int|null}',
            ],
            [
                'name' => self::PUBLISH_AFTER,
                'kind' => 'action',
                'summary' => 'Runs after a page file is written.',
                'arguments' => 'context: {page_id: int, user_id: int|null, result: array}',
            ],
        ];
    }

    public static function isCore(string $hook): bool
    {
        return in_array($hook, self::names(), true);
    }

    /**
     * @param 'filter'|'action' $kind
     */
    public static function listen(string $hook, callable $listener, int $priority, string $pluginId, string $kind): void
    {
        self::$listeners[$hook][] = [
            'priority' => $priority,
            'seq' => self::$seq++,
            'plugin' => $pluginId,
            'kind' => $kind,
            'fn' => $listener,
        ];
    }

    public static function filter(string $hook, mixed $value, array $context = []): mixed
    {
        foreach (self::ordered($hook) as $listener) {
            try {
                if ($listener['kind'] === 'action') {
                    ($listener['fn'])($context);
                    continue;
                }
                $value = ($listener['fn'])($value, $context);
            } catch (Throwable $e) {
                self::record($listener['plugin'], $e);
            }
        }
        return $value;
    }

    public static function action(string $hook, array $context = []): void
    {
        foreach (self::ordered($hook) as $listener) {
            try {
                if ($listener['kind'] === 'filter') {
                    ($listener['fn'])(null, $context);
                    continue;
                }
                ($listener['fn'])($context);
            } catch (Throwable $e) {
                self::record($listener['plugin'], $e);
            }
        }
    }

    public static function forget(string $pluginId): void
    {
        foreach (self::$listeners as $hook => $list) {
            self::$listeners[$hook] = array_values(array_filter(
                $list,
                static fn (array $listener): bool => $listener['plugin'] !== $pluginId
            ));
        }
        unset(self::$errors[$pluginId]);
    }

    /**
     * @return array<string, string> plugin id => latest listener error
     */
    public static function errors(): array
    {
        return self::$errors;
    }

    public static function reset(): void
    {
        self::$listeners = [];
        self::$seq = 0;
        self::$errors = [];
    }

    /**
     * @return list<array{priority: int, seq: int, plugin: string, kind: string, fn: callable}>
     */
    private static function ordered(string $hook): array
    {
        $list = self::$listeners[$hook] ?? [];
        usort($list, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
        });
        return $list;
    }

    private static function record(string $pluginId, Throwable $e): void
    {
        self::$errors[$pluginId] = $e->getMessage();
    }
}
