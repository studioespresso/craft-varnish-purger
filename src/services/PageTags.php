<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\events\DefineValueEvent;
use craft\fields\BaseRelationField;
use craft\web\View;
use studioespresso\varnish\helpers\TagHelper;
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

        Event::on(ElementQuery::class, ElementQuery::EVENT_DEFINE_CACHE_TAGS, fn(DefineValueEvent $e) => $this->scopeRelationQuery($e));

        Craft::$app->getResponse()->on(Response::EVENT_AFTER_PREPARE, function(Event $e) {
            /** @var Response $response */
            $response = $e->sender;
            $tags = $this->getTags();
            if ($response->getStatusCode() !== 200 || !$tags) {
                return;
            }
            $response->getHeaders()->set(self::HEADER, implode(' ', $tags));
            // Craft shortens this for expiring entries; otherwise it's the cacheDuration config setting.
            if ($this->ttl) {
                $response->getHeaders()->set('X-Cache-Ttl', (string)$this->ttl);
            }
        });
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
     * Craft filters relation field queries with a join, so they carry no section and get the catch-all
     * `Entry::*` tag: every entry save would purge every page using a relation field. Scope them to the
     * field's sources instead; a source element saving its relations is covered by its own `element::{id}` tag.
     */
    private function scopeRelationQuery(DefineValueEvent $e): void
    {
        $query = $e->sender;
        if ($e->value || !$query instanceof EntryQuery || !$query->eagerLoadSourceElement) {
            return;
        }
        $handle = substr((string)strrchr(':' . $query->eagerLoadHandle, ':'), 1);
        $field = $query->eagerLoadSourceElement->getFieldLayout()?->getFieldByHandle($handle);
        if (!$field instanceof BaseRelationField || !is_array($field->sources)) {
            return;
        }
        $tags = [];
        foreach ($field->sources as $source) {
            $section = str_starts_with($source, 'section:') ? Craft::$app->getEntries()->getSectionByUid(substr($source, 8)) : null;
            // ponytail: only plain section sources are scoped; anything else (singles, custom sources, other element types) keeps `*`.
            if (!$section) {
                return;
            }
            $tags[] = "section:$section->id";
        }
        $e->value = $tags;
    }
}
