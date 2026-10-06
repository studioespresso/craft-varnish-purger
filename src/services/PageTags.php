<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\events\DefineValueEvent;
use craft\fields\BaseRelationField;
use craft\helpers\ArrayHelper;
use craft\web\View;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;
use yii\base\Component;
use yii\base\Event;
use yii\web\Response;

/**
 * Collects Craft's own element cache tags (the ones behind `{% cache %}`) while the page template renders, and sends
 * them to Varnish in an `X-Cache-Tags` header.
 *
 * Craft collects `element::{id}` for every element the template outputs (including related elements), tags for
 * every element query (e.g. `element::craft\elements\Entry::section:3`), and the tags of any `{% cache %}` block
 * served from cache.
 */
class PageTags extends Component
{
    public const HEADER = 'X-Cache-Tags';
    /** Site the page belongs to, so a ban can be limited to one site. */
    public const SITE_HEADER = 'X-Cache-Site';
    /** Craft cache tag (relative to the element type) added to search queries. */
    public const SEARCH_TAG = 'search';

    /** @var string[] Craft tags collected for the current page */
    private array $tags = [];
    private ?int $ttl = null;
    private bool $tracking = false;

    /**
     * Starts collecting tags for the page being rendered. Called by the plugin for front-end GET requests;
     * tests call it directly from a console request.
     */
    public function track(): void
    {
        if ($this->tracking) {
            return;
        }
        $this->tracking = true;

        // Collect only while the page template renders: the route lookup (an unscoped `uri` query) runs before
        // this, and would otherwise tag every page with `Entry::*`, which every entry save invalidates.
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            Craft::$app->getElements()->startCollectingCacheInfo();
        });
        Event::on(View::class, View::EVENT_AFTER_RENDER_PAGE_TEMPLATE, function() {
            [$dependency, $this->ttl] = Craft::$app->getElements()->stopCollectingCacheInfo();
            $this->tags = $dependency->tags ?? [];
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_DEFINE_CACHE_TAGS, function(DefineValueEvent $e) {
            $this->scopeRelationQuery($e);
            $this->scopeStructureQuery($e);
            $this->scopeNeoOwnerQuery($e);
            $this->tagSearchQuery($e);
        });

        Craft::$app->getResponse()->on(Response::EVENT_AFTER_PREPARE, fn(Event $e) => $this->prepareResponse($e->sender));
    }

    /**
     * Adds the tag headers to a page response that Varnish may cache.
     */
    public function prepareResponse(Response $response): void
    {
        $headers = $response->getHeaders();
        // An `<esi:include>` can also come from a `{% cache %}` block, which skips craft.varnish.include()
        if (is_string($response->content) && str_contains($response->content, '<esi:include')) {
            $headers->set('Surrogate-Control', 'content="ESI/1.0"');
        }

        $tags = $this->getTags();
        if ($response->getStatusCode() !== 200 || !$tags) {
            return;
        }
        // Private pages (e.g. a `csrfInput()` without asyncCsrfInputs, or a started PHP session) must never be shared
        $cacheControl = implode(',', [(string)$headers->get('Cache-Control'), ...preg_grep('/^Cache-Control:/i', headers_list())]);
        if (preg_match('/no-store|private/i', $cacheControl)) {
            return;
        }
        $header = implode(' ', $tags);
        $max = Varnish::getInstance()->getSettings()->maxTagsHeaderLength;
        if (strlen($header) > $max) {
            $request = Craft::$app->getRequest();
            $url = $request->getIsConsoleRequest() ? 'page' : $request->getUrl();
            Craft::warning(sprintf('Not caching %s in Varnish: its %s header would be %d bytes (maxTagsHeaderLength: %d).', $url, self::HEADER, strlen($header), $max), __METHOD__);
            return;
        }
        $headers->set(self::HEADER, $header);
        $headers->set(self::SITE_HEADER, (string)Craft::$app->getSites()->getCurrentSite()->id);
        // Craft shortens this for expiring entries; otherwise it's the cacheDuration config setting.
        if ($this->ttl) {
            $headers->set('X-Cache-Ttl', (string)$this->ttl);
        }
    }

    /**
     * Header tags for the last rendered page template (empty if nothing was collected).
     *
     * @return string[]
     */
    public function getTags(): array
    {
        if (!$this->tags) {
            return [];
        }
        return array_values(array_unique([TagHelper::TAG_ALL, ...array_map(TagHelper::headerTag(...), $this->tags)]));
    }

    /**
     * Search results also depend on Craft's search index, which web requests update in a queue job after the save
     * (and after its purge). Tag search queries `{type}:search` so [[Purger::queueSearchIndexed()]] can purge them
     * once the index is up to date.
     */
    private function tagSearchQuery(DefineValueEvent $e): void
    {
        if (!$e->sender instanceof ElementQuery || !$e->sender->search) {
            return;
        }
        // An empty value would get Craft's catch-all `*` tag; keep it, since any new element could match.
        $e->value = [...($e->value ?: ['*']), self::SEARCH_TAG];
    }

    /**
     * Craft filters relation field queries with a join, so they carry no section or group and get the catch-all
     * `*` tag: every entry (or category) save would purge every page using such a field. Scope them to the field's
     * sources instead; a source element saving its relations is covered by its own `element::{id}` tag.
     */
    private function scopeRelationQuery(DefineValueEvent $e): void
    {
        $query = $e->sender;
        if ($e->value || !($query instanceof EntryQuery || $query instanceof CategoryQuery || $query instanceof AssetQuery) || !$query->eagerLoadSourceElement) {
            return;
        }
        $handle = substr((string)strrchr(':' . $query->eagerLoadHandle, ':'), 1);
        $field = $query->eagerLoadSourceElement->getFieldLayout()?->getFieldByHandle($handle);
        if (!$field instanceof BaseRelationField) {
            return;
        }
        // Assets fields usually allow every volume, and sites tend to have one main volume, so a volume scope would
        // still purge on every upload. Tag the element that owns the field instead: editing its relations saves it,
        // and every asset the page shows has its own tag.
        // ponytail: misses a related asset the page doesn't show changing state (a disabled one enabled, or a count
        // after deleting one) until the TTL; scope to the field's volumes if that matters.
        if ($query instanceof AssetQuery) {
            if ($query->eagerLoadSourceElement->id) {
                $e->value = ["element::{$query->eagerLoadSourceElement->id}"];
            }
            return;
        }
        // Entries fields list several `sources`; Categories fields have a single `source`.
        $sources = $field->allowMultipleSources ? $field->sources : [$field->source];
        if (!is_array($sources)) {
            return;
        }
        $tags = [];
        foreach ($sources as $source) {
            $tag = match (true) {
                $query instanceof EntryQuery && str_starts_with((string)$source, 'section:') =>
                    ($section = Craft::$app->getEntries()->getSectionByUid(substr($source, 8))) ? "section:$section->id" : null,
                $query instanceof CategoryQuery && str_starts_with((string)$source, 'group:') =>
                    ($group = Craft::$app->getCategories()->getGroupByUid(substr($source, 6))) ? "group:$group->id" : null,
                default => null,
            };
            // ponytail: only plain section/group sources are scoped; anything else (singles, custom sources) keeps `*`.
            if (!$tag) {
                return;
            }
            $tags[] = $tag;
        }
        $e->value = $tags;
    }

    /**
     * Structure queries (`entry.ancestors`, `.children`, `.siblings`, Neo's nested blocks) filter on a structure, not a
     * section, so Craft gives them the catch-all `*` tag too. Scope them to what owns the structure, which is what
     * a save or move in it invalidates: the entry section, the category group, or (Neo) the block field and owner.
     */
    private function scopeStructureQuery(DefineValueEvent $e): void
    {
        $query = $e->sender;
        if ($e->value || !$query instanceof ElementQuery) {
            return;
        }
        $relative = $query->descendantOf ?? $query->ancestorOf ?? $query->siblingOf ?? $query->prevSiblingOf
            ?? $query->nextSiblingOf ?? $query->positionedBefore ?? $query->positionedAfter;
        if ($relative === null) {
            return;
        }
        // Neo keeps one structure per field and owner; saving one of its blocks invalidates `field-owner:{field}-{owner}`
        // (matched by name: Neo isn't a dependency)
        if ($relative instanceof NestedElementInterface && is_a($relative, 'benf\neo\elements\Block')) {
            $e->value = [sprintf('field-owner:%s-%s', $relative->getField()?->id, $relative->getPrimaryOwnerId())];
            return;
        }
        $structureId = $query->structureId ?: ($relative instanceof ElementInterface ? $relative->structureId : null);
        if (!is_numeric($structureId)) {
            return;
        }
        $tag = match (true) {
            $query instanceof EntryQuery =>
                ($section = ArrayHelper::firstWhere(Craft::$app->getEntries()->getAllSections(), 'structureId', $structureId)) ? "section:$section->id" : null,
            $query instanceof CategoryQuery =>
                ($group = ArrayHelper::firstWhere(Craft::$app->getCategories()->getAllGroups(), 'structureId', $structureId)) ? "group:$group->id" : null,
            default => null,
        };
        if ($tag) {
            $e->value = [$tag];
        }
    }

    /**
     * Neo's block query only scopes to an owner by `primaryOwnerId`. A Neo field value (`entry.contentBuilder`)
     * filters on `ownerId`, so it falls back to `field:{id}`, which a block save in that field on *any* entry
     * invalidates. Use the `field-owner` tag Neo invalidates for that owner's blocks instead.
     */
    private function scopeNeoOwnerQuery(DefineValueEvent $e): void
    {
        $query = $e->sender;
        // (matched by name: Neo isn't a dependency)
        if (!is_a($query, 'benf\neo\elements\db\BlockQuery') || $query->primaryOwnerId || !$query->ownerId || !$query->fieldId) {
            return;
        }
        if (array_diff((array)$e->value, array_map(fn($id) => "field:$id", (array)$query->fieldId))) {
            return;
        }
        $tags = [];
        foreach ((array)$query->fieldId as $fieldId) {
            foreach ((array)$query->ownerId as $ownerId) {
                $tags[] = "field-owner:$fieldId-$ownerId";
            }
        }
        $e->value = $tags;
    }
}
