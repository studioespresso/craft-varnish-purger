<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\InvalidateElementCachesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\PlainText;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\queue\jobs\UpdateSearchIndex;
use craft\web\Response;
use craft\web\View;
use studioespresso\varnish\services\PageTags;
use studioespresso\varnish\services\Purger;
use studioespresso\varnish\Varnish;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * Two channels: News entries relate to Topics through a section-limited `related` field,
 * and to a Labels category through a `labels` categories field.
 */
class TaggingTest extends Unit
{
    private Varnish $plugin;
    private Section $news;
    private Section $topics;
    private Entry $topic;
    private Entry $article;
    private CategoryGroup $labels;
    private Category $label;

    protected function _before(): void
    {
        $this->plugin = Varnish::getInstance();
        $this->plugin->pageTags->track();

        $this->topics = $this->createSection('topics');
        $related = new Entries([
            'name' => 'Related',
            'handle' => 'related',
            'sources' => ["section:{$this->topics->uid}"],
        ]);
        Craft::$app->getFields()->saveField($related);

        $this->labels = new CategoryGroup(['name' => 'Labels', 'handle' => 'labels']);
        $this->labels->setSiteSettings(array_map(
            fn(int $siteId) => new CategoryGroup_SiteSettings(['siteId' => $siteId, 'hasUrls' => false]),
            array_combine(Craft::$app->getSites()->getAllSiteIds(), Craft::$app->getSites()->getAllSiteIds()),
        ));
        $this->labels->setFieldLayout(new FieldLayout(['type' => Category::class]));
        $this->assertTrue(Craft::$app->getCategories()->saveGroup($this->labels), implode(', ', $this->labels->getFirstErrors()));
        $labelsField = new Categories([
            'name' => 'Labels',
            'handle' => 'labels',
            'source' => "group:{$this->labels->uid}",
        ]);
        Craft::$app->getFields()->saveField($labelsField);

        $summary = new PlainText(['name' => 'Summary', 'handle' => 'summary', 'translationMethod' => 'site']);
        Craft::$app->getFields()->saveField($summary);

        $images = new Assets(['name' => 'Images', 'handle' => 'images', 'sources' => '*']);
        Craft::$app->getFields()->saveField($images);

        $this->news = $this->createSection('news', [$related, $labelsField, $summary, $images]);

        $this->label = new Category(['groupId' => $this->labels->id, 'title' => 'Label']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->label));
        $this->topic = $this->createEntry($this->topics, 'Topic');
        $this->article = $this->createEntry($this->news, 'Article', [
            'related' => [$this->topic->id],
            'labels' => [$this->label->id],
        ]);
        $this->plugin->purger->flush();
    }

    public function testListingPageIsTaggedWithSectionAndElements(): void
    {
        $tags = $this->render('_list');

        $this->assertContains('all', $tags);
        $this->assertContains("e:s:{$this->news->id}", $tags);
        $this->assertContains((string)$this->article->id, $tags);
        $this->assertNotContains('e:any', $tags);
    }

    public function testRelatedEntryIsTaggedAndScopedToFieldSources(): void
    {
        $tags = $this->render('_related', ['entry' => $this->article]);

        $this->assertContains((string)$this->topic->id, $tags);
        $this->assertContains("e:s:{$this->topics->id}", $tags);
        // Without the plugin's scoping, Craft tags relation queries `Entry::*`: any entry save would purge this page.
        $this->assertNotContains('e:any', $tags);
    }

    public function testRelatedCategoryIsScopedToItsGroup(): void
    {
        $tags = $this->render('_labels', ['entry' => $this->article]);

        $this->assertContains((string)$this->label->id, $tags);
        $this->assertContains("c:g:{$this->labels->id}", $tags);
        $this->assertNotContains('c:any', $tags);
    }

    public function testAssetsFieldIsScopedToItsOwner(): void
    {
        $tags = $this->render('_images', ['entry' => $this->article]);

        // Editing the field saves the article; any asset save would purge it with Craft's catch-all `Asset::*`.
        $this->assertContains((string)$this->article->id, $tags);
        $this->assertNotContains('a:any', $tags);
    }

    public function testStructureQueriesAreScopedToTheirSection(): void
    {
        $pages = $this->createSection('pages', [], Section::TYPE_STRUCTURE);
        $parent = $this->createEntry($pages, 'Parent');
        $child = $this->createEntry($pages, 'Child', [], $parent);

        $this->assertContains("e:s:{$pages->id}", $this->render('_ancestors', ['entry' => $child]));
        $this->assertNotContains('e:any', $this->render('_ancestors', ['entry' => $child]));
        $this->assertNotContains('e:any', $this->render('_children', ['entry' => $parent]));
    }

    public function testSearchPageIsTaggedForSearchIndexUpdates(): void
    {
        $tags = $this->render('_search');

        $this->assertContains('e:search', $tags);
        // still purged by any entry save, since any new entry could match
        $this->assertContains('e:any', $tags);
    }

    public function testSectionSearchKeepsItsSectionScope(): void
    {
        $tags = $this->render('_newsSearch');

        $this->assertContains("e:s:{$this->news->id}", $tags);
        $this->assertContains('e:search', $tags);
        $this->assertNotContains('e:any', $tags);
    }

    public function testFinishedSearchIndexJobBansSearchPages(): void
    {
        $this->plugin->purger->queueSearchIndexed($this->searchJob($this->article));

        $this->assertSame(['e:search'], $this->plugin->purger->getPending()[$this->article->siteId] ?? []);
    }

    public function testDraftSearchIndexJobIsIgnored(): void
    {
        $draft = Craft::$app->getDrafts()->createDraft($this->article);
        $this->plugin->purger->queueSearchIndexed($this->searchJob($draft));

        $this->assertSame([], $this->plugin->purger->getPending());
    }

    public function testQueueJobsFlushTheirBans(): void
    {
        // A `queue/listen` worker never ends its request, so bans go out after each job
        Craft::$app->getElements()->saveElement($this->topic);
        $this->assertNotSame([], $this->plugin->purger->getPending());
        Craft::$app->getQueue()->trigger(Queue::EVENT_AFTER_EXEC, new ExecEvent(['job' => $this->searchJob($this->article)]));

        $this->assertSame([], $this->plugin->purger->getPending());
    }

    public function testResponseGetsTagHeaders(): void
    {
        $this->render('_list');
        $response = $this->prepare(new Response());

        $this->assertStringContainsString("e:s:{$this->news->id}", (string)$response->getHeaders()->get(PageTags::HEADER));
        $this->assertSame((string)Craft::$app->getSites()->getCurrentSite()->id, $response->getHeaders()->get(PageTags::SITE_HEADER));
    }

    public function testPrivateResponseIsNotTagged(): void
    {
        $this->render('_list');
        $response = new Response();
        $response->getHeaders()->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        $this->assertNull($this->prepare($response)->getHeaders()->get(PageTags::HEADER));
    }

    public function testErrorResponseIsNotTagged(): void
    {
        $this->render('_list');
        $response = new Response();
        $response->setStatusCode(404);

        $this->assertNull($this->prepare($response)->getHeaders()->get(PageTags::HEADER));
    }

    public function testOversizedTagHeaderIsNotSent(): void
    {
        $this->render('_list');
        $settings = $this->plugin->getSettings();
        $max = $settings->maxTagsHeaderLength;
        $settings->maxTagsHeaderLength = 10;
        try {
            $this->assertNull($this->prepare(new Response())->getHeaders()->get(PageTags::HEADER));
        } finally {
            $settings->maxTagsHeaderLength = $max;
        }
    }

    public function testEsiTagFromCacheBlockAsksForEsiProcessing(): void
    {
        // e.g. an `<esi:include>` served from a {% cache %} block, which skips craft.varnish.include()
        $response = new Response();
        $response->content = '<p>Hi</p><esi:include src="/actions/varnish/esi/render?data=x" />';

        $this->assertSame('content="ESI/1.0"', $this->prepare($response)->getHeaders()->get('Surrogate-Control'));
    }

    public function testSavingAnEntryBansItsTags(): void
    {
        Craft::$app->getElements()->saveElement($this->topic);

        $bans = array_merge(...array_values($this->plugin->purger->getPending()));
        $this->assertContains((string)$this->topic->id, $bans);
        $this->assertContains("e:s:{$this->topics->id}", $bans);
        $this->assertContains('e:any', $bans);
        $this->assertNotContains("e:s:{$this->news->id}", $bans);
    }

    public function testTranslatableChangeOnlyBansThatSite(): void
    {
        $this->article->setFieldValue('summary', 'Only on this site');
        Craft::$app->getElements()->saveElement($this->article);

        $pending = $this->plugin->purger->getPending();
        $this->assertArrayNotHasKey(Purger::ALL_SITES, $pending);
        $this->assertContains((string)$this->article->id, $pending[$this->article->siteId] ?? []);
    }

    public function testTranslatableTitleOnlyBansThatSite(): void
    {
        $this->article->title = 'Renamed on this site';
        Craft::$app->getElements()->saveElement($this->article);

        $this->assertSame([$this->article->siteId], array_keys($this->plugin->purger->getPending()));
    }

    public function testSlugChangeBansAllSites(): void
    {
        // Other sites link to this version (language switchers), so a new URL must reach them too.
        $this->article->slug = 'new-slug-on-this-site';
        Craft::$app->getElements()->saveElement($this->article);

        $this->assertSame([Purger::ALL_SITES], array_keys($this->plugin->purger->getPending()));
    }

    public function testSharedChangeBansAllSites(): void
    {
        // Relation fields are shared across sites by default, so the other sites' pages change too.
        $this->article->setFieldValue('related', []);
        Craft::$app->getElements()->saveElement($this->article);

        $this->assertSame([Purger::ALL_SITES], array_keys($this->plugin->purger->getPending()));
    }

    public function testNewElementBansAllSites(): void
    {
        $this->createEntry($this->news, 'Brand new');

        $this->assertSame([Purger::ALL_SITES], array_keys($this->plugin->purger->getPending()));
    }

    public function testNeoStructureRebuildSkipsTypeWideBan(): void
    {
        require_once dirname(__DIR__) . '/_support/stubs/NeoBlock.php';
        $typeWide = new InvalidateElementCachesEvent(['tags' => ['element::benf\\neo\\elements\\Block']]);

        // Neo rebuilds an owner's block structure on every save; Craft then invalidates the whole block type
        $this->plugin->purger->startStructureChange(new \benf\neo\elements\Block());
        $this->plugin->purger->queue($typeWide);
        $this->assertSame([], $this->plugin->purger->getPending());

        // Any other type-wide invalidation (e.g. a block type change) still bans
        $this->plugin->purger->queue($typeWide);
        $this->assertNotSame([], $this->plugin->purger->getPending());
    }

    public function testDraftSavesAreIgnored(): void
    {
        Craft::$app->getDrafts()->createDraft($this->topic);

        $this->assertSame([], $this->plugin->purger->getPending());
    }

    private function searchJob(Entry $entry): UpdateSearchIndex
    {
        return new UpdateSearchIndex(['elementType' => Entry::class, 'elementId' => $entry->id, 'siteId' => $entry->siteId]);
    }

    private function prepare(Response $response): Response
    {
        $this->plugin->pageTags->prepareResponse($response);
        return $response;
    }

    private function render(string $template, array $variables = []): array
    {
        Craft::$app->getView()->renderPageTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        return $this->plugin->pageTags->getTags();
    }

    private function createSection(string $handle, array $fields = [], string $sectionType = Section::TYPE_CHANNEL): Section
    {
        $type = new EntryType(['name' => ucfirst($handle), 'handle' => $handle]);
        $layout = new FieldLayout(['type' => Entry::class]);
        if ($fields) {
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
            $tab->setElements(array_map(fn($field) => new CustomField($field), $fields));
            $layout->setTabs([$tab]);
        }
        $type->setFieldLayout($layout);
        Craft::$app->getEntries()->saveEntryType($type);

        $section = new Section([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => $sectionType,
            'entryTypes' => [$type],
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                    'hasUrls' => false,
                ]),
            ],
        ]);
        $this->assertTrue(Craft::$app->getEntries()->saveSection($section), implode(', ', $section->getFirstErrors()));
        return $section;
    }

    private function createEntry(Section $section, string $title, array $fields = [], ?Entry $parent = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $title,
        ]);
        $entry->setFieldValues($fields);
        if ($parent) {
            $entry->setParentId($parent->id);
        }
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(', ', $entry->getFirstErrors()));
        return $entry;
    }
}
