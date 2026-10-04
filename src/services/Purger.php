<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
use craft\queue\jobs\UpdateSearchIndex;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Utils;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;
use Throwable;
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
        if ($e->element && self::isDraftOrRevision($e->element)) {
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
        // Saving a draft also updates its search index; live search results don't change
        try {
            $element = Craft::$app->getElements()->getElementById($job->elementId, $job->elementType, '*');
        } catch (Throwable) {
            $element = null;
        }
        if ($element && self::isDraftOrRevision($element)) {
            return;
        }
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
        // 'id' is dirty on a newly saved element
        if ((!$attributes && !$fields) || in_array('id', $attributes, true)) {
            return false;
        }
        // Applying a draft: Craft only reports the changes on the draft's own site, but it may have changed others
        if ($element instanceof Element && $element->updatingFromDerivative && $element->duplicateOf) {
            $otherSites = ['and', ['elementId' => $element->duplicateOf->id], ['not', ['siteId' => $element->siteId]]];
            if (
                (new Query())->from(Table::CHANGEDATTRIBUTES)->where($otherSites)->exists() ||
                (new Query())->from(Table::CHANGEDFIELDS)->where($otherSites)->exists()
            ) {
                return false;
            }
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

    /**
     * Bans the queued tags. Runs after each web request, console command and queue job; any exception is logged,
     * so a failed ban never turns a successful save into an error page.
     *
     * ponytail: bans synchronously at the end of the request; move to a queue job if Varnish is slow/remote.
     */
    public function flush(): void
    {
        $pending = $this->getPending();
        $this->pending = [];
        if (!$pending) {
            return;
        }
        try {
            $everywhere = $pending[self::ALL_SITES] ?? [];
            unset($pending[self::ALL_SITES]);
            $groups = [[$everywhere, null]];
            foreach ($pending as $siteId => $tags) {
                $groups[] = [array_values(array_diff($tags, $everywhere)), (int)$siteId];
            }
            $this->send($groups);
        } catch (Throwable $e) {
            Craft::error('Varnish ban failed: ' . $e->getMessage(), __METHOD__);
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
        return $this->send([[$tags, $siteId]]);
    }

    /**
     * @param array<array{0: string[], 1: int|null}> $groups tags and the site to limit them to (null: every site)
     * @return array<array{url: string, ok: bool, status: int|null, message: string}>
     */
    private function send(array $groups): array
    {
        $groups = array_filter($groups, fn(array $group) => $group[0]);
        if (!$groups) {
            return [];
        }
        $settings = Varnish::getInstance()->getSettings();
        $urls = $settings->getResolvedPurgeUrls();
        $hostnames = $settings->getResolvedHostnames();
        if (!$urls) {
            Craft::info('Varnish ban skipped: no servers configured (Settings → Plugins → Varnish, or config/varnish.php).', __METHOD__);
            return [];
        }
        // Sent in parallel, so an unreachable server costs one timeout, not one per server, site and chunk.
        // http_errors off: a refusal (e.g. 403) is an answer we want to log, not an exception.
        $client = Craft::createGuzzleClient(['connect_timeout' => 2, 'timeout' => 5, 'http_errors' => false]);
        $requests = [];
        $promises = [];
        foreach ($urls as $url) {
            $options = [];
            if ($resolve = $settings->getCurlResolveFor($url)) {
                // Connect to the configured IP, keeping the hostname for routing and the TLS certificate
                $options['curl'] = [CURLOPT_RESOLVE => $resolve];
            }
            foreach ($groups as [$tags, $siteId]) {
                // Chunked to stay under Varnish's request header limit.
                foreach (array_chunk($tags, 100) as $chunk) {
                    $options['headers'] = self::banHeaders($chunk, $siteId, $hostnames);
                    $requests[] = [$url, $chunk, $siteId];
                    try {
                        $promises[] = $client->requestAsync('BAN', $url, $options);
                    } catch (Throwable $e) {
                        // E.g. a malformed URL from an environment variable
                        $promises[] = Create::rejectionFor($e);
                    }
                }
            }
        }

        $results = [];
        foreach (Utils::settle($promises)->wait() as $i => $settled) {
            [$url, $tags, $siteId] = $requests[$i];
            if ($settled['state'] === 'rejected') {
                // No answer at all: DNS, connection refused, timeout…
                $reason = $settled['reason'];
                $result = ['url' => $url, 'ok' => false, 'status' => null, 'message' => $reason instanceof Throwable ? $reason->getMessage() : (string)$reason];
            } else {
                $response = $settled['value'];
                $status = $response->getStatusCode();
                // Our VCL puts the reason in the status line, e.g. "Forbidden: BAN from 10.1.2.3"
                $result = ['url' => $url, 'ok' => $status === 200, 'status' => $status, 'message' => $response->getReasonPhrase()];
            }
            $results[] = $result;
            $scope = $siteId !== null ? " on site $siteId" : '';
            $line = sprintf('%s → %s%s', $url, $result['status'] ?? 'no response', $result['message'] !== '' ? " {$result['message']}" : '');
            if ($result['ok']) {
                Craft::info("Varnish banned$scope: " . implode(' ', $tags) . " ($line)", __METHOD__);
            } else {
                Craft::error("Varnish ban failed$scope ($line) for tags: " . implode(' ', $tags), __METHOD__);
            }
        }
        return $results;
    }

    /**
     * Headers of one BAN request, as the example VCL reads them.
     *
     * @param string[] $tags
     * @param string[] $hostnames
     * @return array<string, string>
     */
    public static function banHeaders(array $tags, ?int $siteId, array $hostnames): array
    {
        $headers = ['X-Cache-Tags-Ban' => implode('|', $tags)];
        if ($siteId !== null) {
            $headers[PageTags::SITE_HEADER . '-Ban'] = (string)$siteId;
        }
        if ($hostnames) {
            // Only this install's pages, when other sites share the Varnish
            $headers['X-Cache-Hosts-Ban'] = implode('|', $hostnames);
        }
        return $headers;
    }

    /**
     * ElementHelper::isDraftOrRevision() walks up to the root owner, which throws for an orphaned nested element;
     * ban for those rather than fail the save.
     */
    private static function isDraftOrRevision(ElementInterface $element): bool
    {
        try {
            return ElementHelper::isDraftOrRevision($element);
        } catch (Throwable) {
            return false;
        }
    }
}
