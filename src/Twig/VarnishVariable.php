<?php

namespace studioespresso\varnish\Twig;

use studioespresso\varnish\Services\Esi;
use Twig\Markup;

/**
 * `craft.varnish` in templates.
 */
class VarnishVariable
{
    /**
     * Includes a template as an ESI fragment behind Varnish, or inline elsewhere:
     * `{{ craft.varnish.include('_esi/now') }}`
     *
     * @param  array<string, mixed>  $variables
     */
    public function include(string $template, array $variables = []): Markup
    {
        return app(Esi::class)->include($template, $variables);
    }
}
