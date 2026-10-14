<?php

namespace studioespresso\varnish\console;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use studioespresso\varnish\Varnish;

class CheckCommand extends Command
{
    use CraftCommand;

    protected $signature = 'varnish:check';

    protected $description = 'Sends a harmless test ban (a tag no page carries) to every configured Varnish server and shows each answer.';

    protected $aliases = ['varnish/check'];

    public function handle(): int
    {
        $plugin = Varnish::getInstance();
        if (! $plugin->getSettings()->getResolvedPurgeUrls()) {
            $this->components->error('No Varnish servers configured (Settings → Plugins → Varnish, or config/craft/varnish.php).');

            return self::INVALID;
        }

        $ok = true;
        foreach ($plugin->purger->ban(['varnish-check']) as $result) {
            $answer = trim(($result['status'] ?? 'no response').' '.$result['message']);
            if ($result['ok'] && str_starts_with($result['message'], 'Ban added')) {
                $this->line("<fg=green>✓ {$result['url']}: $answer</>");

                continue;
            }
            $ok = false;
            if ($result['ok']) {
                // 200, but not the plugin VCL's "Ban added": something else (another VCL, a host's own purge
                // handler) answered, and it may not ban by tag at all.
                $this->line("<fg=yellow>? {$result['url']}: $answer</>");
                $this->line('  Varnish accepted the request, but not with the plugin\'s VCL (which answers "Ban added"). Check that the plugin\'s VCL is the active one (`varnishadm vcl.list`) and what this ban did (`varnishadm ban.list`).');

                continue;
            }
            $this->line("<fg=red>✗ {$result['url']}: $answer</>");
            if ($result['status'] === 403) {
                $this->line('  Varnish refused the BAN: add the IP shown above to `acl purge` in the VCL (and make sure this server reaches Varnish directly, not through a proxy).');
            } elseif ($result['status'] === null && str_contains($result['message'], 'HTTP/0.9')) {
                $this->line('  This looks like Varnish\'s admin port (varnishadm), which doesn\'t speak HTTP. Use Varnish\'s HTTP address instead, usually the same host on port 80 or 6081.');
            } elseif ($result['status'] === null) {
                $this->line('  No answer: check the address and port (Varnish\'s HTTP port, not the admin port), and that this server can reach it.');
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
