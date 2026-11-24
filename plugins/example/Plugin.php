<?php

declare(strict_types=1);

/**
 * Specimen plugin. It stays disabled until an admin enables it.
 * Copy this folder to data/plugins/example or use it as the shape of a new addon.
 */
class ExamplePlugin implements SneezePlugin
{
    public function boot(PluginApi $api): void
    {
        $api->sideTab([
            'id' => 'example',
            'label' => 'Example',
            'html' => '<p class="muted">Reference plugin. Open page: <strong id="example-page">…</strong></p>'
                . '<button type="button" class="btn btn--ghost btn--sm" id="example-ping">Ping</button>'
                . '<pre id="example-out"></pre>',
            'script' => 'assets/panel.js',
        ]);

        $api->route('GET', 'ping', function () use ($api): array {
            return [
                'ok' => true,
                'plugin' => $api->id(),
                'note' => (string) $api->option('note', ''),
            ];
        });

        $api->route('POST', 'echo', function (array $body): array {
            return ['echo' => (string) ($body['message'] ?? '')];
        });

        $api->route('GET', 'admin-ping', function (): array {
            return ['ok' => true];
        }, ['admin' => true]);

        $api->filter(PluginHooks::COMPILE_HTML, function (mixed $html, array $context) use ($api): mixed {
            $note = trim((string) $api->option('note', ''));
            if ($note === '' || !empty($context['preview']) || !is_string($html)) {
                return $html;
            }
            $safe = str_replace(['--', '<', '>'], '', $note);
            $comment = '<!-- example:' . $safe . ' -->';
            if (preg_match('/<\/body>/i', $html)) {
                return preg_replace('/<\/body>/i', $comment . '</body>', $html, 1) ?? $html;
            }
            return $html . $comment;
        });
    }
}
