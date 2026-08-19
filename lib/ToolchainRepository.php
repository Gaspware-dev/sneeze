<?php

declare(strict_types=1);

class ToolchainRepository
{
    public static function settings(): array
    {
        self::ensureRow();
        $row = Database::pdo()->query('SELECT * FROM toolchain WHERE id = 1')->fetch();
        $tokens = DesignRepository::tokens();
        return [
            'typescript' => (int) ($row['typescript_enabled'] ?? 1) === 1,
            'tailwind' => (int) ($row['tailwind_enabled'] ?? 1) === 1,
            'tailwind_config_ts' => (string) ($row['tailwind_config_ts'] ?? self::defaultConfigTs($tokens)),
            'tokens' => $tokens,
            'engines' => [
                'esbuild' => NodeToolchain::esbuild() !== null,
                'tailwindcss' => NodeToolchain::tailwind() !== null,
            ],
        ];
    }

    public static function save(array $body): array
    {
        self::ensureRow();
        $ts = array_key_exists('typescript', $body) ? (!empty($body['typescript']) ? 1 : 0) : null;
        $tw = array_key_exists('tailwind', $body) ? (!empty($body['tailwind']) ? 1 : 0) : null;
        $config = $body['tailwind_config_ts'] ?? null;

        $current = self::settings();
        $tsVal = $ts ?? ($current['typescript'] ? 1 : 0);
        $twVal = $tw ?? ($current['tailwind'] ? 1 : 0);
        $cfgVal = is_string($config) ? $config : $current['tailwind_config_ts'];

        Database::pdo()->prepare(
            'UPDATE toolchain SET typescript_enabled = ?, tailwind_enabled = ?, tailwind_config_ts = ? WHERE id = 1'
        )->execute([$tsVal, $twVal, $cfgVal]);

        return self::settings();
    }

    public static function defaultConfigTs(array $tokens): string
    {
        $colors = [];
        $colorNode = is_array($tokens['color'] ?? null) ? $tokens['color'] : [];
        foreach ($colorNode as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            $colors[] = '      "' . $name . '": "var(--color-' . $name . ')",';
        }

        $colorBlock = implode("\n", $colors);
        return <<<TS
import type { Config } from "tailwindcss";

export default {
  content: ["./content.html"],
  theme: {
    extend: {
      colors: {
{$colorBlock}
      },
      fontFamily: {
        display: ["var(--font-display)"],
        body: ["var(--font-body)"],
      },
      borderRadius: {
        md: "var(--radius-md)",
      },
      maxWidth: {
        wrap: "var(--width-wrap)",
      },
    },
  },
} satisfies Config;
TS;
    }

    public static function ensure(): void
    {
        self::ensureRow();
    }

    private static function ensureRow(): void
    {
        Database::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS toolchain (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    typescript_enabled INTEGER NOT NULL DEFAULT 1,
    tailwind_enabled INTEGER NOT NULL DEFAULT 1,
    tailwind_config_ts TEXT NOT NULL DEFAULT ''
)
SQL);
        $exists = (int) Database::pdo()->query('SELECT COUNT(*) FROM toolchain')->fetchColumn();
        if ($exists === 0) {
            Database::pdo()->prepare(
                'INSERT INTO toolchain (id, typescript_enabled, tailwind_enabled, tailwind_config_ts) VALUES (1, 1, 1, ?)'
            )->execute([self::defaultConfigTs(Seed::tokens())]);
        }
    }
}
