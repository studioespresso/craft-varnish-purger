<?php

namespace studioespresso\varnish\controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use studioespresso\varnish\Varnish;
use Throwable;

use function CraftCms\Cms\template;

/**
 * Renders ESI fragments. It's an action request, which the plugin never tags and the VCL never caches, so a
 * fragment is rendered fresh for every page view.
 */
class EsiController
{
    public function render(Request $request): Response
    {
        $fragment = Varnish::getInstance()->esi->decode((string) $request->query('data'), (string) $request->query('signature'));
        abort_if($fragment === null, 400, 'Invalid ESI fragment.');

        if ($fragment['s'] !== null && ($site = Sites::getSiteById($fragment['s']))) {
            Sites::setCurrentSite($site);
        }
        try {
            return response(template($fragment['t'], $fragment['v'], TemplateMode::Site))->setNoCacheHeaders();
        } catch (Throwable $e) {
            // Varnish inserts whatever comes back into the page: an empty fragment beats an error page in the middle
            if (Cms::config()->devMode) {
                throw $e;
            }
            Log::error("ESI fragment {$fragment['t']} failed: {$e->getMessage()}");

            return response('', 500)->setNoCacheHeaders();
        }
    }
}
