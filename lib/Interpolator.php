<?php

declare(strict_types=1);

class Interpolator
{
    public function render(string $template, array $data): string
    {
        return $this->parse($template, $data);
    }

    private function parse(string $template, array $data): string
    {
        $out = '';
        $len = strlen($template);
        $i = 0;

        while ($i < $len) {
            $open = strpos($template, '{{', $i);
            if ($open === false) {
                $out .= substr($template, $i);
                break;
            }

            $out .= substr($template, $i, $open - $i);

            if (substr($template, $open, 3) === '{{{') {
                $close = strpos($template, '}}}', $open + 3);
                if ($close === false) {
                    $out .= substr($template, $open);
                    break;
                }
                $key = trim(substr($template, $open + 3, $close - $open - 3));
                $out .= (string) $this->lookup($data, $key);
                $i = $close + 3;
                continue;
            }

            $close = strpos($template, '}}', $open + 2);
            if ($close === false) {
                $out .= substr($template, $open);
                break;
            }

            $tag = trim(substr($template, $open + 2, $close - $open - 2));
            $next = $close + 2;

            if (str_starts_with($tag, '#if ')) {
                $key = trim(substr($tag, 4));
                [$inner, $next] = $this->captureBlock($template, $next, '/if');
                if ($this->truthy($this->lookup($data, $key))) {
                    $out .= $this->parse($inner, $data);
                }
                $i = $next;
                continue;
            }

            if (str_starts_with($tag, '#unless ')) {
                $key = trim(substr($tag, 8));
                [$inner, $next] = $this->captureBlock($template, $next, '/unless');
                if (!$this->truthy($this->lookup($data, $key))) {
                    $out .= $this->parse($inner, $data);
                }
                $i = $next;
                continue;
            }

            if (str_starts_with($tag, '#each ')) {
                $key = trim(substr($tag, 6));
                [$inner, $next] = $this->captureBlock($template, $next, '/each');
                $list = $this->lookup($data, $key);
                if (is_array($list)) {
                    $index = 0;
                    foreach ($list as $item) {
                        $out .= $this->parse($inner, $this->itemContext($data, $item, $index));
                        $index++;
                    }
                }
                $i = $next;
                continue;
            }

            if (str_starts_with($tag, 'slot:')) {
                $name = trim(substr($tag, 5));
                $slots = $data['_slots'] ?? [];
                $out .= (string) ($slots[$name] ?? '');
                $i = $next;
                continue;
            }

            if (str_starts_with($tag, '/') || str_starts_with($tag, '#')) {
                $i = $next;
                continue;
            }

            $val = $this->lookup($data, $tag);
            if (is_array($val) || is_object($val)) {
                $val = '';
            }
            $out .= htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $i = $next;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function captureBlock(string $template, int $i, string $closer): array
    {
        $start = $i;
        $len = strlen($template);
        $stack = [$closer];

        while ($i < $len) {
            $open = strpos($template, '{{', $i);
            if ($open === false) {
                break;
            }

            if (substr($template, $open, 3) === '{{{') {
                $close = strpos($template, '}}}', $open + 3);
                $i = $close === false ? $len : $close + 3;
                continue;
            }

            $close = strpos($template, '}}', $open + 2);
            if ($close === false) {
                break;
            }

            $tag = trim(substr($template, $open + 2, $close - $open - 2));
            $after = $close + 2;

            if (str_starts_with($tag, '#if ')) {
                $stack[] = '/if';
            } elseif (str_starts_with($tag, '#unless ')) {
                $stack[] = '/unless';
            } elseif (str_starts_with($tag, '#each ')) {
                $stack[] = '/each';
            } elseif ($tag === $stack[array_key_last($stack)]) {
                array_pop($stack);
                if ($stack === []) {
                    return [substr($template, $start, $open - $start), $after];
                }
            }

            $i = $after;
        }

        return [substr($template, $start), $len];
    }

    private function itemContext(array $parent, mixed $item, int $index): array
    {
        if (is_array($item)) {
            $child = array_merge($parent, $item);
        } else {
            $child = $parent;
            $child['this'] = $item;
        }
        $child['index'] = $index;
        $child['_parent'] = $parent;
        return $child;
    }

    public function lookup(array $data, string $key): mixed
    {
        $key = trim($key);
        if ($key === '' || $key === 'this') {
            return $data['this'] ?? '';
        }

        $parts = explode('.', $key);
        $cur = $data;
        foreach ($parts as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            return null;
        }
        return $cur;
    }

    public function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === '0') {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }
}
