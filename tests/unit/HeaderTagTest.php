<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use studioespresso\varnish\helpers\TagHelper;

class HeaderTagTest extends Unit
{
    /**
     * @dataProvider tags
     */
    public function testHeaderTag(string $craftTag, string $headerTag): void
    {
        $this->assertSame($headerTag, TagHelper::headerTag($craftTag));
    }

    public static function tags(): array
    {
        return [
            ['element', 'all'],
            ['element::12', '12'],
            ['element::craft\elements\Entry', 'e'],
            ['element::craft\elements\Entry::section:3', 'e:s:3'],
            ['element::craft\elements\Entry::entryType:4', 'e:t:4'],
            ['element::craft\elements\Entry::field:6', 'e:f:6'],
            ['element::craft\elements\Entry::*', 'e:any'],
            ['element::craft\elements\Entry::drafts', 'e:drafts'],
            ['element::craft\elements\Asset::volume:2', 'a:v:2'],
            ['element::craft\elements\Category::group:1', 'c:g:1'],
            ['element::craft\elements\User::group:2', 'u:g:2'],
            ['element::craft\elements\Tag::group:3', 't:g:3'],
            ['element::craft\elements\GlobalSet', 'g'],
            ['element::craft\elements\Address::owner:5', 'ad:o:5'],
            // Commerce maps the same whether or not it's installed (its refHandles match its class names)
            ['element::craft\commerce\elements\Product::productType:2', 'p:pt:2'],
            ['element::craft\commerce\elements\Variant::product:7', 'v:p:7'],
            // Anything else is squeezed into the charset the VCL accepts
            ['my-plugin::thing\x*', 'my-plugin::thing_x_'],
        ];
    }
}
