<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\web\View;
use studioespresso\varnish\Varnish;

/**
 * Two channels: News entries relate to Topics through a section-limited `related` field.
 */
class TaggingTest extends Unit
{
    private Varnish $plugin;
    private Section $news;
    private Section $topics;
    private Entry $topic;
    private Entry $article;

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
        $this->news = $this->createSection('news', $related);

        $this->topic = $this->createEntry($this->topics, 'Topic');
        $this->article = $this->createEntry($this->news, 'Article', ['related' => [$this->topic->id]]);
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

    public function testSavingAnEntryBansItsTags(): void
    {
        Craft::$app->getElements()->saveElement($this->topic);

        $bans = $this->plugin->purger->getPending();
        $this->assertContains((string)$this->topic->id, $bans);
        $this->assertContains("e:s:{$this->topics->id}", $bans);
        $this->assertContains('e:any', $bans);
        $this->assertNotContains("e:s:{$this->news->id}", $bans);
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

    private function createSection(string $handle, ?Entries $field = null): Section
    {
        $type = new EntryType(['name' => ucfirst($handle), 'handle' => $handle]);
        $layout = new FieldLayout(['type' => Entry::class]);
        if ($field) {
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
            $tab->setElements([new CustomField($field)]);
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
