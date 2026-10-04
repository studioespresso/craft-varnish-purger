<?php

namespace studioespresso\varnish\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use studioespresso\varnish\helpers\TagHelper;
use studioespresso\varnish\Varnish;
use yii\console\ExitCode;

/**
 * Purges pages from Varnish.
 */
class PurgeController extends Controller
{
    /**
     * @var string Comma-separated header tags to purge, e.g. `12,e:s:3` (element 12, entries of section 3).
     * Defaults to every page.
     */
    public string $tags = '';

    /**
     * @var string Only purge pages of this site (handle or ID).
     */
    public string $site = '';

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'tags', 'site'];
    }

    /**
     * Purges every page, or only the given tags and/or site: `php craft varnish/purge --tags=12 --site=default`.
     */
    public function actionIndex(): int
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', $this->tags)))) ?: [TagHelper::TAG_ALL];

        $siteId = null;
        if ($this->site !== '') {
            $sites = Craft::$app->getSites();
            $site = ctype_digit($this->site) ? $sites->getSiteById((int)$this->site) : $sites->getSiteByHandle($this->site);
            if (!$site) {
                $this->stderr("Unknown site: {$this->site}\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $siteId = $site->id;
        }

        if (!Varnish::getInstance()->getSettings()->getResolvedPurgeUrls()) {
            $this->stderr("No Varnish servers configured (Settings → Plugins → Varnish, or config/varnish.php).\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout(sprintf("Purging %s%s…\n", implode(', ', $tags), $siteId !== null ? " on site {$this->site}" : ''));
        $ok = true;
        foreach (Varnish::getInstance()->purger->ban($tags, $siteId) as $result) {
            $answer = trim(($result['status'] ?? 'no response') . ' ' . $result['message']);
            if ($result['ok']) {
                $this->stdout("✓ {$result['url']}: $answer\n", Console::FG_GREEN);
            } else {
                $ok = false;
                $this->stderr("✗ {$result['url']}: $answer\n", Console::FG_RED);
            }
        }
        if (!$ok) {
            $this->stderr("Run `php craft varnish/check` for hints.\n");
        }
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
