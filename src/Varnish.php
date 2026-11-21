<?php

namespace studioespresso\varnish;

use CraftCms\Cms\Element\Events\ElementCachesInvalidated;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Search\Jobs\UpdateSearchIndex;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use studioespresso\varnish\Console\Commands\CheckCommand;
use studioespresso\varnish\Console\Commands\PurgeCommand;
use studioespresso\varnish\Services\Esi;
use studioespresso\varnish\Services\PageTags;
use studioespresso\varnish\Services\Purger;
use studioespresso\varnish\Support\TagHelper;
use studioespresso\varnish\Twig\VarnishVariable;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Tags front-end pages with Craft's element cache tags and bans those tags in Varnish when Craft invalidates them.
 *
 * @method Settings getSettings()
 */
class Varnish extends Plugin
{
    public bool $hasCpSettings = true;

    protected array $commands = [
        CheckCommand::class,
        PurgeCommand::class,
    ];

    public Esi $esi { get => app(Esi::class); }

    public PageTags $pageTags { get => app(PageTags::class); }

    public Purger $purger { get => app(Purger::class); }

    public function boot(): void
    {
        $this->app->singleton(Settings::class, fn () => $this->getSettings());

        $this->pageTags->listen();

        // Craft fires this on save, delete, restore and move, including Matrix owners.
        Event::listen(ElementCachesInvalidated::class, fn (ElementCachesInvalidated $e) => $this->purger->queue($e));

        Event::listen(JobProcessed::class, function (JobProcessed $e) {
            if ($e->job->resolveName() === UpdateSearchIndex::class) {
                try {
                    $job = unserialize($e->job->payload()['data']['command']);
                } catch (Throwable) {
                    $job = null;
                }
                if ($job instanceof UpdateSearchIndex) {
                    $this->purger->queueSearchIndexed($job);
                }
            }
            // Ban after every job: a `queue:work` worker never ends its request
            $this->purger->flush();
        });
        Event::listen(JobExceptionOccurred::class, fn () => $this->purger->flush());
        // After the response is sent (web) or the command finished (console)
        $this->app->terminating(fn () => $this->purger->flush());
        // Fatal errors skip terminating callbacks, but may have saved something first
        register_shutdown_function(fn () => $this->purger->flush());

        CraftVariable::macro('varnish', fn () => new VarnishVariable);
    }

    protected function getCacheOptions(): array
    {
        return [
            'varnish' => [
                'label' => t('Varnish cache', category: 'varnish'),
                'action' => fn () => $this->purger->ban([TagHelper::TAG_ALL]),
            ],
        ];
    }

    protected static function createSettings(): ?PluginSettings
    {
        return new Settings;
    }

    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        // config/craft/varnish.php wins over the CP value, so lock the table when it sets the servers.
        $overridden = array_key_exists('purgeUrls', (array) config('craft.varnish', []));

        return Ui::make([
            Field::make(t('Varnish servers', category: 'varnish'))
                ->instructions(t('Every Varnish server that caches this site. Saving content sends a BAN request to each of them, so use their internal addresses (e.g. `varnish` or `10.0.0.11`), not the public site URL. The port is usually 6081 (Linux packages) or 80 (Docker); leave it empty for 80. Hosts can be environment variables, like `$VARNISH_HOST`.', category: 'varnish'))
                ->warning($overridden ? t('This is being overridden by the `purgeUrls` setting in `config/craft/varnish.php`.', category: 'varnish') : null)
                // Not `purgeUrls`: the table edits host/port rows, Settings::setServers() turns them into URLs.
                ->control(Table::make('servers')
                    ->columns([
                        'host' => ['heading' => t('Host', category: 'varnish'), 'type' => 'singleline', 'placeholder' => 'varnish', 'code' => true, 'width' => 85],
                        'port' => ['heading' => t('Port', category: 'varnish'), 'control' => Number::make('port')->min(1)->max(65535), 'width' => 15],
                    ])
                    // New rows assume a Linux-package install (port 6081); an empty port still means 80, like ddev/Docker.
                    ->defaultValues(['port' => 6081])
                    ->allowAdd(! $overridden)
                    ->allowDelete(! $overridden)
                    ->allowReorder(! $overridden)
                    ->addRowLabel(t('Add a server', category: 'varnish'))
                    ->value($this->getSettings()->getServers())
                    ->mode($overridden ? ControlMode::ReadOnly : ControlMode::Editable)),
        ]);
    }
}
