<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
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
use craft\web\View;
use studioespresso\varnish\services\Purger;
use studioespresso\varnish\Varnish;

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

        $this->news = $this->createSection('news', $related, $labelsField, $summary);

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

    public function testDraftSavesAreIgnored(): void
    {
        Craft::$app->getDrafts()->createDraft($this->topic);

        $this->assertSame([], $this->plugin->purger->getPending());
    }

    private function render(string $template, array $variables = []): array
    {
        Craft::$app->getView()->renderPageTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        return $this->plugin->pageTags->getTags();
    }

    private function createSection(string $handle, Entries|Categories|PlainText ...$fields): Section
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
            'type' => Section::TYPE_CHANNEL,
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

    private function createEntry(Section $section, string $title, array $fields = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $title,
        ]);
        $entry->setFieldValues($fields);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(', ', $entry->getFirstErrors()));
        return $entry;
    }
}
