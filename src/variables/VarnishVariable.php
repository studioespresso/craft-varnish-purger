<?php

namespace studioespresso\varnish\variables;

use studioespresso\varnish\Varnish;
use Twig\Markup;

/**
 * `craft.varnish` in templates.
 */
class VarnishVariable
{
    /**
     * Includes a template as an ESI fragment behind Varnish, or inline elsewhere:
     * `{{ craft.varnish.include('_esi/now') }}`
     */
    public function include(string $template, array $variables = []): Markup
    {
        return Varnish::getInstance()->esi->include($template, $variables);
    }
}
