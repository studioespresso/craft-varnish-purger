<?php

namespace studioespresso\varnish\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use studioespresso\varnish\Varnish;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Renders ESI fragments. It's an action request, which the plugin never tags and the VCL never caches, so a
 * fragment is rendered fresh for every page view.
 */
class EsiController extends Controller
{
    protected array|bool|int $allowAnonymous = ['render'];

    public function actionRender(string $data): Response
    {
        $fragment = Varnish::getInstance()->esi->decode($data);
        if ($fragment === null) {
            throw new BadRequestHttpException('Invalid ESI fragment.');
        }
        $this->response->setNoCacheHeaders();
        $sites = Craft::$app->getSites();
        if ($fragment['s'] !== null && ($site = $sites->getSiteById($fragment['s']))) {
            $sites->setCurrentSite($site);
        }
        try {
            return $this->asRaw($this->getView()->renderTemplate($fragment['t'], $fragment['v'], View::TEMPLATE_MODE_SITE));
        } catch (Throwable $e) {
            // Varnish inserts whatever comes back into the page: an empty fragment beats an error page in the middle
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }
            Craft::error("ESI fragment {$fragment['t']} failed: {$e->getMessage()}", __METHOD__);
            return $this->asRaw('')->setStatusCode(500);
        }
    }
}
