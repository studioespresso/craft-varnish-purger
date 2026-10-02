<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\base\ElementInterface;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\ElementHelper;
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
     * Sends a BAN for the given header tags to every configured Varnish instance.
     *
     * @param string[] $tags
     * @param int|null $siteId only ban pages of this site; null bans them on every site
     */
    public function ban(array $tags, ?int $siteId = null): void
    {
        if (!$tags) {
            return;
        }
        $urls = Varnish::getInstance()->getSettings()->getResolvedPurgeUrls();
        if (!$urls) {
            Craft::warning('Varnish ban skipped: no servers configured (Settings → Plugins → Varnish, or config/varnish.php).', __METHOD__);
            return;
        }
        // Sent in parallel, so an unreachable server costs one timeout, not one per server and chunk.
        $client = Craft::createGuzzleClient(['connect_timeout' => 2, 'timeout' => 5]);
        $requests = [];
        foreach ($urls as $url) {
            // Chunked to stay under Varnish's request header limit.
            foreach (array_chunk($tags, 100) as $chunk) {
                $headers = ['X-Cache-Tags-Ban' => implode('|', $chunk)];
                if ($siteId !== null) {
                    $headers[PageTags::SITE_HEADER . '-Ban'] = (string)$siteId;
                }
                $requests[] = [$url, $client->requestAsync('BAN', $url, ['headers' => $headers])];
            }
        }
        foreach (Utils::settle(array_column($requests, 1))->wait() as $i => $result) {
            if ($result['state'] === 'rejected') {
                Craft::error("Varnish ban failed for {$requests[$i][0]}: {$result['reason']->getMessage()}", __METHOD__);
            }
        }
        Craft::info('Varnish banned' . ($siteId !== null ? " on site $siteId" : '') . ': ' . implode(' ', $tags), __METHOD__);
    }
}
