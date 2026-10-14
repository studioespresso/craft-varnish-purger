<?php

namespace studioespresso\varnish\tests\Unit;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use studioespresso\varnish\helpers\TagHelper;

class HeaderTagTest extends TestCase
{
    /** @return array<array{string, string}> Craft tag, header tag */
    public static function tags(): array
    {
        return [
            ['element', 'all'],
            ['element::12', '12'],
            ['element::CraftCms\Cms\Entry\Elements\Entry', 'e'],
            ['element::CraftCms\Cms\Entry\Elements\Entry::section:3', 'e:s:3'],
            ['element::CraftCms\Cms\Entry\Elements\Entry::entryType:4', 'e:t:4'],
            ['element::CraftCms\Cms\Entry\Elements\Entry::field:6', 'e:f:6'],
            ['element::CraftCms\Cms\Entry\Elements\Entry::*', 'e:any'],
            ['element::CraftCms\Cms\Entry\Elements\Entry::drafts', 'e:drafts'],
            ['element::CraftCms\Cms\Asset\Elements\Asset::volume:2', 'a:v:2'],
            ['element::CraftCms\Cms\User\Elements\User::group:2', 'u:g:2'],
            ['element::CraftCms\Cms\Address\Elements\Address::owner:5', 'ad:o:5'],
            // Legacy (yii2-adapter) and plugin element types map by class name when their refHandle isn't known
            ['element::craft\elements\Category::group:1', 'c:g:1'],
            ['element::craft\commerce\elements\Product::productType:2', 'p:pt:2'],
            ['element::craft\commerce\elements\Variant::product:7', 'v:p:7'],
            // Anything else is squeezed into the charset the VCL accepts
            ['my-plugin::thing\x*', 'my-plugin::thing_x_'],
        ];
    }

    #[DataProvider('tags')]
    public function test_header_tag(string $craftTag, string $headerTag): void
    {
        $this->assertSame($headerTag, TagHelper::headerTag($craftTag));
    }
}
