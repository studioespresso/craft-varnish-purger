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
     * Connect to a specific IP for a purge URL's hostname, like an /etc/hosts entry just for purging:
     * `['www.example.com' => '203.0.113.10']`. Use it when the hostname must be in the URL (shared hosting routes on
     * it, and HTTPS needs it for the certificate) but DNS points elsewhere, e.g. to Cloudflare. Values may be
     * environment variables.
     *
     * @var array<string, string>
     */
    public array $resolve = [];

    /**
     * Only ban cached pages of these hostnames, e.g. `['jan.example.com', 'www.example.com']`. Needed when other sites
     * (another Craft install, another customer) share the same Varnish: without it, a ban for element 12 also drops
     * their pages tagged 12, and "clear Varnish cache" empties theirs too. List every hostname this install's pages
     * are cached under, aliases and www-variants included: pages under a missing hostname are never purged.
     * Empty (default): bans match pages of every hostname. Values may be environment variables.
     *
     * @var string[]
     */
    public array $hostnames = [];

    /**
     * Longest `X-Cache-Tags` header (in bytes) a page may send. Pages with more tags aren't tagged, so Varnish doesn't
     * cache them (a warning is logged): a header over Varnish's `http_resp_hdr_len` (8 KB by default), or over the
     * response header buffer of a proxy in between (nginx: 4 or 8 KB), would fail the whole response.
     */
    public int $maxTagsHeaderLength = 4000;

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
     * cURL `CURLOPT_RESOLVE` entries (`host:port:ip`) for a purge URL, from [[resolve]].
     *
     * @return string[]
     */
    public function getCurlResolveFor(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $ip = $host !== null ? trim((string)App::parseEnv((string)($this->resolve[$host] ?? ''))) : '';
        if (!$ip) {
            return [];
        }
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        return ["$host:$port:$ip"];
    }

    /**
     * @return string[] [[hostnames]] with environment variables resolved (which may hold several, separated by commas
     * or spaces), lowercased, without ports or a trailing dot. Anything else the VCL would refuse is dropped.
     */
    public function getResolvedHostnames(): array
    {
        $hosts = [];
        foreach ($this->hostnames as $value) {
            foreach (preg_split('/[\s,]+/', (string)App::parseEnv((string)$value), -1, PREG_SPLIT_NO_EMPTY) as $host) {
                $host = rtrim(strtolower((string)preg_replace('/:\d+$/', '', $host)), '.');
                if (preg_match('/^[a-z0-9.-]+$/', $host)) {
                    $hosts[] = $host;
                }
            }
        }
        return array_values(array_unique($hosts));
    }

    /**
     * @return string[] [[purgeUrls]] with environment variables resolved; empty ones (an unset variable) are dropped
     */
    public function getResolvedPurgeUrls(): array
    {
        return array_values(array_filter(array_map(fn($url) => trim((string)App::parseEnv((string)$url)), $this->purgeUrls)));
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
