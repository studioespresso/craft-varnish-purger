<?php

namespace studioespresso\varnish\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use studioespresso\varnish\Varnish;
use yii\console\ExitCode;

/**
 * Checks that every configured Varnish server accepts BAN requests from this server.
 */
class CheckController extends Controller
{
    /**
     * Sends a harmless test ban (a tag no page carries) to every configured server and shows each answer.
     * Run it after a deploy: `php craft varnish/check`.
     */
    public function actionIndex(): int
    {
        $settings = Varnish::getInstance()->getSettings();
        if (!$settings->getResolvedPurgeUrls()) {
            $this->stderr("No Varnish servers configured (Settings → Plugins → Varnish, or config/varnish.php).\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $ok = true;
        foreach (Varnish::getInstance()->purger->ban(['varnish-check']) as $result) {
            $answer = trim(($result['status'] ?? 'no response') . ' ' . $result['message']);
            if ($result['ok'] && str_starts_with($result['message'], 'Ban added')) {
                $this->stdout("✓ {$result['url']}: $answer\n", Console::FG_GREEN);
                continue;
            }
            if ($result['ok']) {
                // 200, but not the plugin VCL's "Ban added": something else (another VCL, a host's own purge
                // handler) answered, and it may not ban by tag at all.
                $ok = false;
                $this->stderr("? {$result['url']}: $answer\n", Console::FG_YELLOW);
                $this->stderr("  Varnish accepted the request, but not with the plugin's VCL (which answers \"Ban added\"). Check that the plugin's VCL is the active one (`varnishadm vcl.list`) and what this ban did (`varnishadm ban.list`).\n");
                continue;
            }
            $ok = false;
            $this->stderr("✗ {$result['url']}: $answer\n", Console::FG_RED);
            if ($result['status'] === 403) {
                $this->stderr("  Varnish refused the BAN: add the IP shown above to `acl purge` in the VCL (and make sure this server reaches Varnish directly, not through a proxy).\n");
            } elseif ($result['status'] === null && str_contains($result['message'], 'HTTP/0.9')) {
                $this->stderr("  This looks like Varnish's admin port (varnishadm), which doesn't speak HTTP. Use Varnish's HTTP address instead, usually the same host on port 80 or 6081.\n");
            } elseif ($result['status'] === null) {
                $this->stderr("  No answer: check the address and port (Varnish's HTTP port, not the admin port), and that this server can reach it.\n");
            }
        }
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
