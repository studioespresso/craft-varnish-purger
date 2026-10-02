<?php

namespace studioespresso\varnish;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\queue\jobs\UpdateSearchIndex;
use craft\services\Elements;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\models\Settings;
use studioespresso\varnish\services\Esi;
use studioespresso\varnish\services\PageTags;
use studioespresso\varnish\services\Purger;
use studioespresso\varnish\variables\VarnishVariable;
use yii\base\Application;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * Tags front-end pages with Craft's element cache tags and bans those tags in Varnish when Craft invalidates them.
 *
 * @property-read Esi $esi
 * @property-read PageTags $pageTags
 * @property-read Purger $purger
 * @method Settings getSettings()
 */
class Varnish extends Plugin
{
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'esi' => Esi::class,
            'pageTags' => PageTags::class,
            'purger' => Purger::class,
        ]);

        $request = Craft::$app->getRequest();
        if (
            !$request->getIsConsoleRequest() &&
            $request->getIsSiteRequest() &&
            $request->getIsGet() &&
            !$request->getIsActionRequest() &&
            !$request->getIsPreview()
        ) {
            $this->pageTags->track();
        }

        // Craft fires this on save, delete, restore and move, including Matrix owners.
        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, fn(InvalidateElementCachesEvent $e) => $this->purger->queue($e));
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, function(ExecEvent $e) {
            if ($e->job instanceof UpdateSearchIndex) {
                $this->purger->queueSearchIndexed($e->job);
            }
        });
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->purger->flush());

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, fn(Event $e) => $e->sender->set('varnish', VarnishVariable::class));

        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS, function(RegisterCacheOptionsEvent $e) {
            $e->options[] = [
                'key' => 'varnish',
                'label' => 'Varnish cache',
                'action' => fn() => $this->purger->ban([TagHelper::TAG_ALL]),
            ];
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('varnish/_settings', [
            'settings' => $this->getSettings(),
            // config/varnish.php wins over the CP value, so lock the table when it sets the servers.
            'overridden' => array_key_exists('purgeUrls', Craft::$app->getConfig()->getConfigFromFile($this->handle)),
        ]);
    }
}
