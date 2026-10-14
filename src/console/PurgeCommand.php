<?php

namespace studioespresso\varnish\console;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Command;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;

class PurgeCommand extends Command
{
    use CraftCommand;

    protected $signature = 'varnish:purge
        {--tags= : Comma-separated header tags to purge, e.g. `12,e:s:3` (element 12, entries of section 3). Defaults to every page.}
        {--site= : Only purge pages of this site (handle or ID)}';

    protected $description = 'Purges every page from Varnish, or only the given tags and/or site.';

    protected $aliases = ['varnish/purge'];

    public function handle(): int
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tags'))))) ?: [TagHelper::TAG_ALL];

        $siteId = null;
        $siteOption = (string) $this->option('site');
        if ($siteOption !== '') {
            $site = ctype_digit($siteOption) ? Sites::getSiteById((int) $siteOption) : Sites::getSiteByHandle($siteOption);
            if (! $site) {
                $this->components->error("Unknown site: $siteOption");

                return self::INVALID;
            }
            $siteId = $site->id;
        }

        $plugin = Varnish::getInstance();
        if (! $plugin->getSettings()->getResolvedPurgeUrls()) {
            $this->components->error('No Varnish servers configured (Settings → Plugins → Varnish, or config/craft/varnish.php).');

            return self::INVALID;
        }

        $this->line(sprintf('Purging %s%s…', implode(', ', $tags), $siteId !== null ? " on site $siteOption" : ''));
        $ok = true;
        foreach ($plugin->purger->ban($tags, $siteId) as $result) {
            $answer = trim(($result['status'] ?? 'no response').' '.$result['message']);
            if ($result['ok']) {
                $this->line("<fg=green>✓ {$result['url']}: $answer</>");
            } else {
                $ok = false;
                $this->line("<fg=red>✗ {$result['url']}: $answer</>");
            }
        }
        if (! $ok) {
            $this->line('Run `php craft varnish/check` for hints.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
