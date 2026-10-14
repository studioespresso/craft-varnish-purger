<?php

namespace studioespresso\varnish\services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\AssetQuery;
use CraftCms\Cms\Element\Queries\EntryQuery;
use CraftCms\Cms\Element\Queries\Events\ElementQueryCacheTagsResolving;
use CraftCms\Cms\Field\BaseRelationField;
use CraftCms\Cms\Http\ResponseHeaders;
use CraftCms\Cms\Support\Facades\ElementCaches;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\View\Events\PageTemplateRendered;
use CraftCms\Cms\View\Events\PageTemplateRendering;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collects Craft's own element cache tags (the ones behind `{% cache %}`) while the page template renders, and sends
 * them to Varnish in an `X-Cache-Tags` header.
 *
 * Craft collects `element::{id}` for every element the template outputs (including related elements), tags for
 * every element query (e.g. `element::craft\elements\Entry::section:3`), and the tags of any `{% cache %}` block
 * served from cache.
 */
class PageTags
{
    public const HEADER = 'X-Cache-Tags';

    /** Site the page belongs to, so a ban can be limited to one site. */
    public const SITE_HEADER = 'X-Cache-Site';

    /** Craft cache tag (relative to the element type) added to search queries. */
    public const SEARCH_TAG = 'search';

    /** @var string[] Craft tags collected for the current page */
    private array $tags = [];

    private ?int $ttl = null;

    /** Forced on by [[track()]]; otherwise decided per request, see [[isTracking()]] */
    private bool $tracking = false;

    /** Whether we started the collection for the page template being rendered */
    private bool $collecting = false;

    /**
     * Registers the listeners. Whether a page gets tagged is decided when it renders: Craft resolves the site,
     * tokens and previews in middleware, after plugins boot.
     */
    public function listen(): void
    {
        // Collect only while the page template renders: the route lookup (an unscoped `uri` query) runs before
        // this, and would otherwise tag every page with `Entry::*`, which every entry save invalidates.
        Event::listen(PageTemplateRendering::class, function () {
            if (! $this->collecting && $this->isTracking()) {
                $this->collecting = true;
                ElementCaches::startCollectingCacheInfo();
            }
        });
        Event::listen(PageTemplateRendered::class, function () {
            if ($this->collecting) {
                $this->collecting = false;
                [$dependency, $this->ttl] = ElementCaches::stopCollectingCacheInfo();
                $this->tags = $dependency->tags ?? [];
            }
        });

        Event::listen(ElementQueryCacheTagsResolving::class, function (ElementQueryCacheTagsResolving $e) {
            if (! $this->isTracking()) {
                return;
            }
            $this->scopeRelationQuery($e);
            $this->scopeStructureQuery($e);
            $this->tagSearchQuery($e);
        });

        Event::listen(RequestHandled::class, fn (RequestHandled $e) => $this->prepareResponse($e->response));
    }

    /**
     * Tags pages rendered in this process regardless of the request; for tests.
     */
    public function track(): void
    {
        $this->tracking = true;
    }

    /**
     * Front-end GET page requests: not CP, action, preview or token requests.
     */
    public function isTracking(): bool
    {
        if ($this->tracking) {
            return true;
        }
        if (app()->runningInConsole()) {
            return false;
        }
        $request = request();

        return $request->isSiteRequest()
            && $request->isMethod('GET')
            && ! $request->isActionRequest()
            && ! $request->isPreview()
            // Tokens can route to other content (shared drafts, custom token routes) under a normal URL
            && ! $request->getHadToken();
    }

    /**
     * Adds the tag headers to a page response that Varnish may cache.
     */
    public function prepareResponse(Response $response): void
    {
        $headers = $response->headers;
        // An `<esi:include>` can come from craft.varnish.include() or from a `{% cache %}` block around one
        $content = $response->getContent();
        if (is_string($content) && str_contains($content, '<esi:include')) {
            $headers->set('Surrogate-Control', 'content="ESI/1.0"');
        }

        $tags = $this->getTags();
        if ($response->getStatusCode() !== 200 || ! $tags) {
            return;
        }
        // Private pages (e.g. a `csrfInput()` without asyncCsrfInputs) must never be shared. Symfony's default for
        // a response that doesn't set Cache-Control (`no-cache, private`) doesn't count.
        $private = $headers->get('Cache-Control') !== 'no-cache, private'
            && ($headers->hasCacheControlDirective('no-store') || $headers->hasCacheControlDirective('private'));
        if ($private || app(ResponseHeaders::class)->noCache) {
            return;
        }
        $header = implode(' ', $tags);
        $max = Varnish::getInstance()->getSettings()->maxTagsHeaderLength;
        if (strlen($header) > $max) {
            $url = app()->runningInConsole() ? 'page' : request()->getRequestUri();
            Log::warning(sprintf('Not caching %s in Varnish: its %s header would be %d bytes (maxTagsHeaderLength: %d).', $url, self::HEADER, strlen($header), $max));

            return;
        }
        $headers->set(self::HEADER, $header);
        $headers->set(self::SITE_HEADER, (string) Sites::getCurrentSite()->id);
        // Explicitly public, or Symfony adds `private` and Varnish's built-in VCL won't cache the page. The VCL
        // tells browsers `no-cache` for cached pages.
        $headers->addCacheControlDirective('public');
        // Shorter for entries with an expiry date
        if ($this->ttl) {
            $headers->set('X-Cache-Ttl', (string) $this->ttl);
        }
    }

    /**
     * Header tags for the last rendered page template (empty if nothing was collected).
     *
     * @return string[]
     */
    public function getTags(): array
    {
        if (! $this->tags) {
            return [];
        }

        return array_values(array_unique([TagHelper::TAG_ALL, ...array_map(TagHelper::headerTag(...), $this->tags)]));
    }

    /**
     * Search results also depend on Craft's search index, which web requests update in a queue job after the save
     * (and after its purge). Tag search queries `{type}:search` so [[Purger::queueSearchIndexed()]] can purge them
     * once the index is up to date.
     */
    private function tagSearchQuery(ElementQueryCacheTagsResolving $e): void
    {
        if (! $e->query->search) {
            return;
        }
        // An empty value would get Craft's catch-all `*` tag; keep it, since any new element could match.
        $e->tags = [...($e->tags ?: ['*']), self::SEARCH_TAG];
    }

    /**
     * Craft filters relation field queries with a join, so they carry no section or group and get the catch-all
     * `*` tag: every entry save would purge every page using such a field. Scope them to the field's
     * sections instead; a source element saving its relations is covered by its own `element::{id}` tag.
     */
    private function scopeRelationQuery(ElementQueryCacheTagsResolving $e): void
    {
        $query = $e->query;
        if ($e->tags || ! ($query instanceof EntryQuery || $query instanceof AssetQuery) || ! $query->eagerLoadSourceElement) {
            return;
        }
        $handle = substr((string) strrchr(':'.$query->eagerLoadHandle, ':'), 1);
        $field = $query->eagerLoadSourceElement->getFieldLayout()?->getFieldByHandle($handle);
        if (! $field instanceof BaseRelationField) {
            return;
        }
        // Assets fields usually allow every volume, and sites tend to have one main volume, so a volume scope would
        // still purge on every upload. Tag the element that owns the field instead: editing its relations saves it,
        // and every asset the page shows has its own tag.
        // ponytail: misses a related asset the page doesn't show changing state (a disabled one enabled, or a count
        // after deleting one) until the TTL; scope to the field's volumes if that matters.
        if ($query instanceof AssetQuery) {
            if ($query->eagerLoadSourceElement->id) {
                $e->tags = ["element::{$query->eagerLoadSourceElement->id}"];
            }

            return;
        }
        $sources = $field->allowMultipleSources ? $field->sources : [$field->source];
        if (! is_array($sources)) {
            return;
        }
        $tags = [];
        foreach ($sources as $source) {
            $section = str_starts_with((string) $source, 'section:') ? Sections::getSectionByUid(substr($source, 8)) : null;
            $tag = $section ? "section:$section->id" : null;
            // ponytail: only plain section sources are scoped; anything else (singles, custom sources) keeps `*`.
            if (! $tag) {
                return;
            }
            $tags[] = $tag;
        }
        $e->tags = $tags;
    }

    /**
     * Structure queries (`entry.ancestors`, `.children`, `.siblings`) filter on a structure, not a section, so Craft
     * gives them the catch-all `*` tag too. Scope them to the section that owns the structure, which is what a save
     * or move in it invalidates.
     */
    private function scopeStructureQuery(ElementQueryCacheTagsResolving $e): void
    {
        $query = $e->query;
        if ($e->tags) {
            return;
        }
        $relative = $query->descendantOf ?? $query->ancestorOf ?? $query->siblingOf ?? $query->prevSiblingOf
            ?? $query->nextSiblingOf ?? $query->positionedBefore ?? $query->positionedAfter;
        if ($relative === null) {
            return;
        }
        $structureId = $query->structureId ?: ($relative instanceof ElementInterface ? $relative->structureId : null);
        if (! is_numeric($structureId)) {
            return;
        }
        if ($query instanceof EntryQuery && ($section = Sections::getAllSections()->firstWhere('structureId', (int) $structureId))) {
            $e->tags = ["section:$section->id"];
        }
    }
}
