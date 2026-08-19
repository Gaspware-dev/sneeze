<?php

declare(strict_types=1);

class CmsAdapter
{
    public function sync(array $source): array
    {
        $adapter = $source['adapter'] ?? 'http_json';
        if ($adapter !== 'http_json') {
            Response::error('Unknown CMS adapter: ' . $adapter);
        }
        return $this->syncHttpJson($source);
    }

    private function syncHttpJson(array $source): array
    {
        $config = $source['config'] ?? [];
        $url = trim((string) ($config['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            Response::error('A valid http(s) URL is required');
        }

        $payload = $this->fetchJson($url, is_array($config['headers'] ?? null) ? $config['headers'] : []);
        $list = $this->pluck($payload, (string) ($config['list_path'] ?? ''));
        if (!is_array($list)) {
            Response::error('CMS response is not a list — set list_path (e.g. data.items)');
        }
        if ($list !== [] && !array_is_list($list)) {
            $list = [$list];
        }

        $idPath = (string) ($config['id_path'] ?? 'id');
        $mapping = is_array($config['mapping'] ?? null) ? $config['mapping'] : [];
        $count = 0;

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $externalId = $this->pluck($item, $idPath);
            if ($externalId === null || $externalId === '') {
                $externalId = (string) $index;
            }
            $mapped = $mapping === [] ? $item : [];
            foreach ($mapping as $field => $path) {
                $mapped[(string) $field] = $this->pluck($item, (string) $path);
            }
            CmsRepository::upsertEntry((int) $source['id'], (string) $externalId, $mapped);
            $count++;
        }

        $applied = CmsRepository::applyBindings((int) $source['id']);
        CmsRepository::markSynced((int) $source['id'], null);

        return [
            'ok' => true,
            'fetched' => $count,
            'applied' => $applied,
            'entries' => CmsRepository::entries((int) $source['id']),
        ];
    }

    private function fetchJson(string $url, array $headers): mixed
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }
        $headerLines[] = 'Accept: application/json';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headerLines),
                'timeout' => 15,
                'follow_location' => 1,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            Response::error('Failed to fetch CMS URL');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Response::error('CMS response was not JSON');
        }
        return $decoded;
    }

    private function pluck(mixed $data, string $path): mixed
    {
        if ($path === '') {
            return $data;
        }
        $cur = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
