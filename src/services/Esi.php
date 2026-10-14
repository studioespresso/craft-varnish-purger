<?php

namespace studioespresso\varnish\services;

use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\View\TemplateMode;
use Twig\Markup;

use function CraftCms\Cms\template;

/**
 * Edge Side Includes: render part of a page in its own request, so it can differ from (or be cached apart from)
 * the page around it. Behind Varnish, include() outputs an `<esi:include>` tag; anywhere else (no Varnish, the
 * `novarnish.` host, console) it renders the template inline, so templates work the same either way.
 */
class Esi
{
    /** Request header the VCL sets on every request. */
    public const string CAPABILITY_HEADER = 'Surrogate-Capability';

    /**
     * @param  string  $template  site template to render, e.g. `_esi/now`
     * @param  array<string, mixed>  $variables  scalar values only: the fragment is rendered in a separate request
     */
    public function include(string $template, array $variables = []): Markup
    {
        if (! $this->isEsiCapable()) {
            return new Markup(template($template, $variables, TemplateMode::Site), 'UTF-8');
        }

        // PageTags adds `Surrogate-Control` to any response containing this tag, so Varnish processes it.
        return new Markup(sprintf('<esi:include src="%s" />', htmlspecialchars($this->fragmentPath($template, $variables))), 'UTF-8');
    }

    /**
     * Relative URL of the fragment action. Relative, because Varnish only fetches ESI includes over plain HTTP from
     * its own backend, on the same host (and so the same Craft site) as the page.
     *
     * @param  array<string, mixed>  $variables
     */
    public function fragmentPath(string $template, array $variables = []): string
    {
        // Signed, so the action can't be used to render arbitrary templates with arbitrary variables. Not encrypted:
        // the template name and variables are readable in the page source, so don't pass anything secret.
        // The site goes along because with path-based sites (/nl/…) the action URL may not resolve to it.
        $data = rtrim(strtr(base64_encode((string) json_encode(['t' => $template, 'v' => $variables, 's' => Sites::getCurrentSite()->id])), '+/', '-_'), '=');
        $url = Url::actionUrl('varnish/esi/render', ['data' => $data, 'signature' => self::sign($data)]);
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @return array{t: string, v: array<string, mixed>, s: int|null}|null the template, variables and site ID, or null if the data was tampered with
     */
    public function decode(string $data, string $signature): ?array
    {
        if (! hash_equals(self::sign($data), $signature)) {
            return null;
        }
        $decoded = json_decode((string) base64_decode(strtr($data, '-_', '+/')), true);

        return is_array($decoded) && is_string($decoded['t'] ?? null) ? ['t' => $decoded['t'], 'v' => (array) ($decoded['v'] ?? []), 's' => is_int($decoded['s'] ?? null) ? $decoded['s'] : null] : null;
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', 'varnish-esi|'.$data, (string) config('app.key'));
    }

    private function isEsiCapable(): bool
    {
        return ! app()->runningInConsole()
            && str_contains((string) request()->header(self::CAPABILITY_HEADER), 'ESI/1.0');
    }
}
