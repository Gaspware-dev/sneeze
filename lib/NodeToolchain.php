<?php

declare(strict_types=1);

class NodeToolchain
{
    public static function esbuild(): ?array
    {
        $bin = self::findBinary('esbuild');
        return $bin !== null ? [$bin] : null;
    }

    public static function tailwind(): ?array
    {
        foreach (['tailwindcss', 'tailwind'] as $name) {
            $bin = self::findBinary($name);
            if ($bin !== null) {
                return [$bin];
            }
        }
        return null;
    }

    /**
     * @param list<string> $command
     * @return array{ok: bool, stdout: string, stderr: string}
     */
    public static function run(array $command, string $cwd, ?string $stdin = null, int $timeout = 30): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($command, $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'stdout' => '', 'stderr' => 'Unable to start process'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $start = time();
        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['ok' => false, 'stdout' => $stdout, 'stderr' => 'Timed out'];
            }
            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['ok' => $code === 0, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function findBinary(string $name): ?string
    {
        $candidates = [];
        try {
            $install = Config::installDir();
            $data = Config::dataDir();
            $candidates[] = $install . '/node_modules/.bin/' . $name;
            $candidates[] = $data . '/toolchain/node_modules/.bin/' . $name;
        } catch (Throwable) {
            // Config may not be initialized in unit tests of helpers
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        }

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
