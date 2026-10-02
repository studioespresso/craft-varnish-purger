<?php

namespace studioespresso\varnish\controllers;

use craft\web\Controller;
use craft\web\View;
use studioespresso\varnish\Varnish;
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
        return $this->asRaw($this->getView()->renderTemplate($fragment['t'], $fragment['v'], View::TEMPLATE_MODE_SITE));
    }
}
