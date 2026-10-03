<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\base\ElementInterface;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
use craft\queue\jobs\UpdateSearchIndex;
use GuzzleHttp\Promise\Utils;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;
use yii\base\Component;

/**
 * Collects the tags Craft invalidates during a request and bans them in Varnish once, at the end of the request.
 */
class Purger extends Component
{
    /** Pending-ban key for tags that must be banned on every site. */
    public const ALL_SITES = '*';

    /** @var array<int|string, array<string, true>> header tags to ban, keyed by site ID or [[ALL_SITES]] */
    private array $pending = [];

    public function queue(InvalidateElementCachesEvent $e): void
    {
        // Live pages never depend on drafts/revisions; skipping them avoids a ban on every autosave.
        if ($e->element && ElementHelper::isDraftOrRevision($e->element)) {
            return;
        }
        $scope = $e->element && self::isSiteSpecificChange($e->element) ? $e->element->siteId : self::ALL_SITES;
        foreach ($e->tags as $tag) {
            $this->pending[$scope][TagHelper::headerTag($tag)] = true;
        }
    }

    /**
     * Purges search result pages once Craft's queued search-index update for an element has run.
     */
    public function queueSearchIndexed(UpdateSearchIndex $job): void
    {
        $scope = is_numeric($job->siteId) ? (int)$job->siteId : self::ALL_SITES;
        $this->pending[$scope][TagHelper::headerTag("element::$job->elementType::" . PageTags::SEARCH_TAG)] = true;
    }

    /**
     * Whether a save only changed content of the element's own site, so other sites' pages can stay cached.
     *
     * Craft only invalidates the saved site, but quietly propagates shared values (untranslatable fields, post
     * date, global status…) to the element's other sites. So only translatable content counts as per-site;
     * anything else bans all sites: new elements, slug/URI/status changes, saves without change info, and
     * deletes, restores or moves.
     *
     * ponytail: a page that queries another site's content (`.site('*')`) isn't purged by a site-scoped ban.
     */
    public static function isSiteSpecificChange(ElementInterface $element): bool
    {
        $attributes = $element->getDirtyAttributes();
        $fields = $element->getDirtyFields();
        if ((!$attributes && !$fields) || in_array('id', $attributes, true)) {
            return false;
        }
        foreach ($attributes as $attribute) {
            // Slug, URI and enabled status aren't site-specific here: other sites link to this version (a
            // language switcher via `entry.localized`), so those pages need the new URL, or to drop the link.
            if ($attribute !== 'title' || !$element->getIsTitleTranslatable()) {
                return false;
            }
        }
        $layout = $element->getFieldLayout();
        foreach ($fields as $handle) {
            if (!$layout?->getFieldByHandle($handle)?->getIsTranslatable($element)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int|string, string[]> header tags queued for banning, keyed by site ID or [[ALL_SITES]]
     */
    public function getPending(): array
    {
        // PHP turns numeric string keys into ints; cast them back.
        return array_map(fn(array $tags) => array_map('strval', array_keys($tags)), $this->pending);
    }

    // ponytail: bans synchronously at the end of the request; move to a queue job if Varnish is slow/remote.
    public function flush(): void
    {
        $pending = $this->getPending();
        $this->pending = [];
        $everywhere = $pending[self::ALL_SITES] ?? [];
        unset($pending[self::ALL_SITES]);
        $this->ban($everywhere);
        foreach ($pending as $siteId => $tags) {
            $this->ban(array_values(array_diff($tags, $everywhere)), (int)$siteId);
        }
    }

    /**
     * Sends a BAN for the given header tags to every configured Varnish instance, and logs each server's answer:
     * failures (refused, unreachable, error status) as errors, successes as info (visible with devMode on).
     *
     * @param string[] $tags
     * @param int|null $siteId only ban pages of this site; null bans them on every site
     * @return array<array{url: string, ok: bool, status: int|null, message: string}> one result per request
     */
    public function ban(array $tags, ?int $siteId = null): array
    {
        if (!$tags) {
            return [];
        }
        $settings = Varnish::getInstance()->getSettings();
        $urls = $settings->getResolvedPurgeUrls();
        $hostnames = $settings->getResolvedHostnames();
        if (!$urls) {
            Craft::warning('Varnish ban skipped: no servers configured (Settings → Plugins → Varnish, or config/varnish.php).', __METHOD__);
            return [];
        }
        // Sent in parallel, so an unreachable server costs one timeout, not one per server and chunk.
        // http_errors off: a refusal (e.g. 403) is an answer we want to log, not an exception.
        $client = Craft::createGuzzleClient(['connect_timeout' => 2, 'timeout' => 5, 'http_errors' => false]);
        $requests = [];
        foreach ($urls as $url) {
            // Chunked to stay under Varnish's request header limit.
            foreach (array_chunk($tags, 100) as $chunk) {
                $headers = ['X-Cache-Tags-Ban' => implode('|', $chunk)];
                if ($siteId !== null) {
                    $headers[PageTags::SITE_HEADER . '-Ban'] = (string)$siteId;
                }
                if ($hostnames) {
                    // Only this install's pages, when other sites share the Varnish
                    $headers['X-Cache-Hosts-Ban'] = implode('|', $hostnames);
                }
                $options = ['headers' => $headers];
                if ($resolve = $settings->getCurlResolveFor($url)) {
                    // Connect to the configured IP, keeping the hostname for routing and the TLS certificate
                    $options['curl'] = [CURLOPT_RESOLVE => $resolve];
                }
                $requests[] = [$url, $client->requestAsync('BAN', $url, $options)];
            }
        }

        $results = [];
        $scope = $siteId !== null ? " on site $siteId" : '';
        foreach (Utils::settle(array_column($requests, 1))->wait() as $i => $settled) {
            $url = $requests[$i][0];
            if ($settled['state'] === 'rejected') {
                // No answer at all: DNS, connection refused, timeout…
                $result = ['url' => $url, 'ok' => false, 'status' => null, 'message' => $settled['reason']->getMessage()];
            } else {
                $response = $settled['value'];
                $status = $response->getStatusCode();
                // Our VCL puts the reason in the status line, e.g. "Forbidden: BAN from 10.1.2.3"
                $result = ['url' => $url, 'ok' => $status === 200, 'status' => $status, 'message' => $response->getReasonPhrase()];
            }
            $results[] = $result;
            $line = sprintf('%s → %s%s', $url, $result['status'] ?? 'no response', $result['message'] !== '' ? " {$result['message']}" : '');
            if ($result['ok']) {
                Craft::info("Varnish banned$scope: " . implode(' ', $tags) . " ($line)", __METHOD__);
            } else {
                Craft::error("Varnish ban failed$scope ($line) for tags: " . implode(' ', $tags), __METHOD__);
            }
        }
        return $results;
    }
}
