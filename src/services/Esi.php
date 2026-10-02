<?php

namespace studioespresso\varnish\services;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Twig\Markup;
use yii\base\Component;

/**
 * Edge Side Includes: render part of a page in its own request, so it can differ from (or be cached apart from)
 * the page around it. Behind Varnish, include() outputs an `<esi:include>` tag; anywhere else (no Varnish, the
 * `novarnish.` host, console) it renders the template inline, so templates work the same either way.
 */
class Esi extends Component
{
    /** Request header the VCL sets on every request. */
    public const string CAPABILITY_HEADER = 'Surrogate-Capability';

    /**
     * @param string $template site template to render, e.g. `_esi/now`
     * @param array $variables scalar values only: the fragment is rendered in a separate request
     */
    public function include(string $template, array $variables = []): Markup
    {
        if (!$this->isEsiCapable()) {
            return new Markup(Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE), 'UTF-8');
        }

        // Ask Varnish to process ESI tags in this response
        Craft::$app->getResponse()->getHeaders()->set('Surrogate-Control', 'content="ESI/1.0"');

        return new Markup(sprintf('<esi:include src="%s" />', htmlspecialchars($this->fragmentPath($template, $variables))), 'UTF-8');
    }

    /**
     * Relative URL of the fragment action. Relative, because Varnish only fetches ESI includes over plain HTTP from
     * its own backend, on the same host (and so the same Craft site) as the page.
     */
    public function fragmentPath(string $template, array $variables = []): string
    {
        // Signed, so the action can't be used to render arbitrary templates with arbitrary variables
        $data = Craft::$app->getSecurity()->hashData(Json::encode(['t' => $template, 'v' => $variables]));
        $url = UrlHelper::actionUrl('varnish/esi/render', ['data' => $data]);
        $parts = parse_url($url);
        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @return array{t: string, v: array}|null the template and variables, or null if the data was tampered with
     */
    public function decode(string $data): ?array
    {
        $json = Craft::$app->getSecurity()->validateData($data);
        $decoded = $json !== false ? Json::decodeIfJson($json) : null;
        return is_array($decoded) && is_string($decoded['t'] ?? null) ? ['t' => $decoded['t'], 'v' => (array)($decoded['v'] ?? [])] : null;
    }

    private function isEsiCapable(): bool
    {
        $request = Craft::$app->getRequest();
        return !$request->getIsConsoleRequest()
            && str_contains((string)$request->getHeaders()->get(self::CAPABILITY_HEADER), 'ESI/1.0');
    }
}
