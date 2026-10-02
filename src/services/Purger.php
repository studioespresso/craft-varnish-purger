<?php

namespace studioespresso\varnish\services;

use Craft;
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
    /** @var array<string, true> header tags to ban at the end of the request */
    private array $pending = [];

    public function queue(InvalidateElementCachesEvent $e): void
    {
        // Live pages never depend on drafts/revisions; skipping them avoids a ban on every autosave.
        if ($e->element && ElementHelper::isDraftOrRevision($e->element)) {
            return;
        }
        foreach ($e->tags as $tag) {
            $this->pending[TagHelper::headerTag($tag)] = true;
        }
    }

    /**
     * @return string[] header tags queued for banning
     */
    public function getPending(): array
    {
        // PHP turns numeric string keys into ints; cast them back.
        return array_map('strval', array_keys($this->pending));
    }

    // ponytail: bans synchronously at the end of the request; move to a queue job if Varnish is slow/remote.
    public function flush(): void
    {
        $this->ban($this->getPending());
        $this->pending = [];
    }

    /**
     * Sends a BAN for the given header tags to every configured Varnish instance.
     *
     * @param string[] $tags
     */
    public function ban(array $tags): void
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
                $requests[] = [$url, $client->requestAsync('BAN', $url, ['headers' => ['X-Cache-Tags-Ban' => implode('|', $chunk)]])];
            }
        }
        foreach (Utils::settle(array_column($requests, 1))->wait() as $i => $result) {
            if ($result['state'] === 'rejected') {
                Craft::error("Varnish ban failed for {$requests[$i][0]}: {$result['reason']->getMessage()}", __METHOD__);
            }
        }
        Craft::info('Varnish banned: ' . implode(' ', $tags), __METHOD__);
    }
}
