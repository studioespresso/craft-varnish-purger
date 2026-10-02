<?php

namespace studioespresso\varnish\helpers;

use craft\base\ElementInterface;

class TagHelper
{
    /** Tag every page carries (Craft invalidates it via invalidateAllCaches()). */
    public const TAG_ALL = 'all';

    /** Short names for core element types (keyed by refHandle, or lowercase class name); others keep that name. */
    private const TYPES = [
        'entry' => 'e',
        'category' => 'c',
        'asset' => 'a',
        'user' => 'u',
        'tag' => 't',
        'globalset' => 'g',
        'block' => 'b',
        'address' => 'ad',
        // Craft Commerce
        'product' => 'p',
        'variant' => 'v',
    ];

    /** Short names for the scopes Craft uses in element cache tags. */
    private const SCOPES = [
        'section' => 's',
        'entryType' => 't',
        'field' => 'f',
        'group' => 'g',
        'owner' => 'o',
        'volume' => 'v',
        // Craft Commerce
        'productType' => 'pt',
        'product' => 'p',
        '*' => 'any',
    ];

    /**
     * Shortens a Craft cache tag for the header. The same mapping is used for tagging and banning, so it only has to
     * be deterministic; a collision can only over-purge. E.g. `element` → `all`, `element::12` → `12`,
     * `element::craft\elements\Entry` → `e`, `element::craft\elements\Entry::section:3` → `e:s:3`.
     */
    public static function headerTag(string $tag): string
    {
        $parts = explode('::', $tag, 3);
        if ($parts[0] === 'element' && count($parts) > 1 && str_contains($parts[1], '\\')) {
            $class = $parts[1];
            $tag = (is_subclass_of($class, ElementInterface::class) ? $class::refHandle() : null)
                ?? strtolower(substr(strrchr($class, '\\'), 1));
            $tag = self::TYPES[$tag] ?? $tag;
            if (isset($parts[2])) {
                [$scope, $id] = explode(':', $parts[2], 2) + [1 => null];
                $tag .= ':' . (self::SCOPES[$scope] ?? $scope) . ($id !== null ? ":$id" : '');
            }
        } elseif ($tag === 'element') {
            $tag = self::TAG_ALL;
        } else {
            $tag = preg_replace('/^element::/', '', $tag);
        }
        // Anything else (e.g. tags from other plugins) is squeezed into the charset the VCL accepts.
        return preg_replace('/[^A-Za-z0-9:._-]/', '_', $tag);
    }
}
