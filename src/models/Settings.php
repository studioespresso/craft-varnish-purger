<?php

namespace studioespresso\varnish\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    /**
     * Varnish servers that receive BAN requests, e.g. `http://varnish` or `http://10.0.0.11:6081`.
     * Use internal addresses: the VCL refuses BANs relayed through a proxy. Environment variables (`$VARNISH_URL`)
     * are supported.
     *
     * @var string[]
     */
    public array $purgeUrls = [];

    /**
     * Rows for the CP table: `[['host' => 'varnish', 'port' => 80], …]`.
     *
     * @return array<array{host: string, port: int|string}>
     */
    public function getServers(): array
    {
        return array_map(function(string $url) {
            // Env var references can't be split into host and port; show them as-is.
            if (str_starts_with($url, '$')) {
                return ['host' => $url, 'port' => ''];
            }
            $parts = parse_url(str_contains($url, '://') ? $url : "http://$url");
            $scheme = $parts['scheme'] ?? 'http';
            return [
                'host' => ($scheme !== 'http' ? "$scheme://" : '') . ($parts['host'] ?? $url),
                'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80),
            ];
        }, $this->purgeUrls);
    }

    /**
     * Sets [[purgeUrls]] from CP table rows.
     */
    public function setServers(array $rows): void
    {
        $this->purgeUrls = [];
        foreach ($rows as $row) {
            $host = trim($row['host'] ?? '');
            if ($host === '') {
                continue;
            }
            $port = trim((string)($row['port'] ?? ''));
            if (str_starts_with($host, '$')) {
                $this->purgeUrls[] = $host;
                continue;
            }
            $url = rtrim(str_contains($host, '://') ? $host : "http://$host", '/');
            $defaultPort = str_starts_with($url, 'https://') ? '443' : '80';
            $this->purgeUrls[] = $url . ($port !== '' && $port !== $defaultPort ? ":$port" : '');
        }
    }

    /**
     * The CP table posts its rows as `purgeUrls` (`''` when empty), so Craft stores them under the real setting;
     * convert rows to URLs here. A config file passes plain URL strings, which go through untouched.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        $urls = is_array($values) ? ($values['purgeUrls'] ?? null) : null;
        if ($urls === '' || (is_array($urls) && array_filter($urls, 'is_array'))) {
            $this->setServers($urls ?: []);
            unset($values['purgeUrls']);
        }
        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @return string[] [[purgeUrls]] with environment variables resolved
     */
    public function getResolvedPurgeUrls(): array
    {
        return array_values(array_filter(array_map(fn(string $url) => App::parseEnv($url), $this->purgeUrls)));
    }

    protected function defineRules(): array
    {
        return [
            ['purgeUrls', 'validatePurgeUrls'],
        ];
    }

    public function validatePurgeUrls(string $attribute): void
    {
        foreach ($this->purgeUrls as $url) {
            $resolved = App::parseEnv($url);
            $port = parse_url((string)$resolved, PHP_URL_PORT);
            if (!filter_var($resolved, FILTER_VALIDATE_URL) || ($port !== null && ($port < 1 || $port > 65535))) {
                $this->addError($attribute, "“{$url}” isn’t a valid server address.");
            }
        }
    }
}
