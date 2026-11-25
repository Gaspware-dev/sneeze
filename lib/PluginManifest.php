<?php

declare(strict_types=1);

/**
 * plugin.json schema. PluginManifest::describe() is the field list a future
 * documentation page should render. parse() accepts only those fields.
 */
class PluginManifest
{
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    /** @var list<string> */
    public const CAPABILITIES = ['site.read', 'site.write', 'studio', 'routes', 'compile'];

    /** @var list<string> */
    public const SETTING_TYPES = ['text', 'secret', 'number', 'checkbox', 'select'];

    /**
     * @var array<string, array{type: string, required: bool, summary: string}>
     */
    public const FIELDS = [
        'id' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'Stable id. Lowercase letters, digits, and hyphens. Must match the directory name.',
        ],
        'name' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'Name shown in the Plugins workspace.',
        ],
        'version' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'Plugin version string. Sneeze does not interpret it.',
        ],
        'description' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'One paragraph shown in the Plugins workspace. May be empty.',
        ],
        'api' => [
            'type' => 'integer',
            'required' => true,
            'summary' => 'Plugin API version this plugin was written for. Must equal PluginApi::VERSION.',
        ],
        'entry' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'PHP file inside the plugin directory. Loaded only when the plugin is enabled.',
        ],
        'class' => [
            'type' => 'string',
            'required' => true,
            'summary' => 'Class defined by entry. Must implement SneezePlugin.',
        ],
        'capabilities' => [
            'type' => 'list<string>',
            'required' => true,
            'summary' => 'Subset of site.read, site.write, studio, routes, compile. PluginApi refuses methods the list does not include.',
        ],
        'settings' => [
            'type' => 'list<{key, label, type}>',
            'required' => false,
            'summary' => 'Options the Plugins screen edits. type is text, secret, number, checkbox, or select. select requires options as strings or {value, label} pairs. Secret values are write-only in JSON.',
        ],
    ];

    /**
     * @return list<array{name: string, type: string, required: bool, summary: string}>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $meta) {
            $out[] = [
                'name' => $name,
                'type' => $meta['type'],
                'required' => $meta['required'],
                'summary' => $meta['summary'],
            ];
        }
        return $out;
    }

    /**
     * Read and validate plugin.json in a plugin directory.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   api: int,
     *   entry: string,
     *   class: string,
     *   capabilities: list<string>,
     *   settings: list<array{key: string, label: string, type: string}>
     * }
     */
    public static function parse(string $directory): array
    {
        return self::validate($directory, true);
    }

    /**
     * Validate a zip's contents before they are moved into data/plugins/{id}.
     * The folder name is not required to match the id yet.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   api: int,
     *   entry: string,
     *   class: string,
     *   capabilities: list<string>,
     *   settings: list<array{key: string, label: string, type: string}>
     * }
     */
    public static function validatePackage(string $directory): array
    {
        return self::validate($directory, false);
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   version: string,
     *   description: string,
     *   api: int,
     *   entry: string,
     *   class: string,
     *   capabilities: list<string>,
     *   settings: list<array{key: string, label: string, type: string}>
     * }
     */
    private static function validate(string $directory, bool $matchDirectory): array
    {
        $directory = rtrim($directory, '/');
        $path = $directory . '/plugin.json';
        if (!is_file($path)) {
            throw new PluginException('Missing plugin.json');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new PluginException('plugin.json is not a JSON object');
        }

        $unknown = array_diff(array_keys($data), array_keys(self::FIELDS));
        if ($unknown !== []) {
            throw new PluginException('Unknown plugin.json field: ' . implode(', ', $unknown));
        }
        foreach (self::FIELDS as $name => $meta) {
            if ($meta['required'] && !array_key_exists($name, $data)) {
                throw new PluginException('plugin.json is missing ' . $name);
            }
        }

        $id = $data['id'] ?? '';
        if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException('Plugin id must match ' . self::ID_PATTERN);
        }
        if ($matchDirectory && basename($directory) !== $id) {
            throw new PluginException('Plugin id must match its directory name');
        }

        $name = self::stringField($data, 'name', true);
        $version = self::stringField($data, 'version', true);
        $description = self::stringField($data, 'description', false);
        if (!is_int($data['api'] ?? null)) {
            throw new PluginException('plugin.json api must be an integer');
        }

        $entry = self::stringField($data, 'entry', true);
        self::assertInside($directory, $entry, 'entry');
        if (!str_ends_with(strtolower($entry), '.php')) {
            throw new PluginException('Plugin entry must be a PHP file');
        }
        if (!is_file($directory . '/' . $entry)) {
            throw new PluginException('Plugin entry does not exist');
        }

        $class = self::stringField($data, 'class', true);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            throw new PluginException('Plugin class name is invalid');
        }

        if (!is_array($data['capabilities'] ?? null) || !array_is_list($data['capabilities'])) {
            throw new PluginException('capabilities must be a list');
        }
        $capabilities = [];
        foreach ($data['capabilities'] as $capability) {
            if (!is_string($capability) || !in_array($capability, self::CAPABILITIES, true)) {
                throw new PluginException('Unknown capability: ' . (is_string($capability) ? $capability : gettype($capability)));
            }
            $capabilities[$capability] = $capability;
        }

        if (array_key_exists('settings', $data) && (!is_array($data['settings']) || !array_is_list($data['settings']))) {
            throw new PluginException('settings must be a list');
        }
        $settings = [];
        $seen = [];
        foreach ($data['settings'] ?? [] as $setting) {
            if (!is_array($setting)) {
                throw new PluginException('Each setting must be an object');
            }
            $key = $setting['key'] ?? '';
            $label = $setting['label'] ?? '';
            $type = $setting['type'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
                throw new PluginException('Setting key is invalid');
            }
            if (!is_string($label) || trim($label) === '') {
                throw new PluginException('Setting label is required');
            }
            if (!is_string($type) || !in_array($type, self::SETTING_TYPES, true)) {
                throw new PluginException('Setting type is invalid');
            }
            if (isset($seen[$key])) {
                throw new PluginException('Duplicate setting key: ' . $key);
            }
            $seen[$key] = true;
            $row = ['key' => $key, 'label' => $label, 'type' => $type];
            if ($type === 'select') {
                $row['options'] = self::selectOptions($setting);
            }
            $settings[] = $row;
        }

        return [
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'api' => $data['api'],
            'entry' => $entry,
            'class' => $class,
            'capabilities' => array_values($capabilities),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $setting
     * @return list<array{value: string, label: string}>
     */
    private static function selectOptions(array $setting): array
    {
        $options = $setting['options'] ?? null;
        if (!is_array($options) || !array_is_list($options) || $options === []) {
            throw new PluginException('Select setting requires options');
        }
        $out = [];
        $seen = [];
        foreach ($options as $option) {
            if (is_string($option)) {
                $value = $option;
                $label = $option;
            } elseif (is_array($option)) {
                $value = $option['value'] ?? '';
                $label = $option['label'] ?? '';
            } else {
                throw new PluginException('Select option is invalid');
            }
            if (!is_string($value) || $value === '' || !is_string($label) || trim($label) === '') {
                throw new PluginException('Select option is invalid');
            }
            if (isset($seen[$value])) {
                throw new PluginException('Duplicate select option: ' . $value);
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => trim($label)];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key, bool $nonEmpty): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || ($nonEmpty && trim($value) === '')) {
            throw new PluginException('plugin.json field ' . $key . ' must be a string');
        }
        return $nonEmpty ? trim($value) : $value;
    }

    private static function assertInside(string $directory, string $relative, string $label): void
    {
        $relative = str_replace('\\', '/', $relative);
        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $relative)
        ) {
            throw new PluginException($label . ' escapes the plugin directory');
        }
        $full = $directory . '/' . $relative;
        $parent = realpath(dirname($full));
        $root = realpath($directory);
        if ($root === false || $parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            throw new PluginException($label . ' escapes the plugin directory');
        }
    }
}
