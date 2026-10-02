<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use studioespresso\varnish\Varnish;

class EsiTest extends Unit
{
    public function testRendersInlineWithoutVarnish(): void
    {
        // Console request: no Surrogate-Capability header, so the template is rendered in place
        $html = (string)Varnish::getInstance()->esi->include('_esiTest', ['name' => 'Varnish']);

        $this->assertSame('Hello Varnish', trim($html));
    }

    public function testFragmentDataRoundTrips(): void
    {
        $esi = Varnish::getInstance()->esi;
        $path = $esi->fragmentPath('_esiTest', ['name' => 'Varnish']);
        $this->assertStringStartsWith('/', $path, 'relative, so Varnish fetches it from its own backend');
        parse_str((string)parse_url($path, PHP_URL_QUERY), $query);

        $this->assertSame(['t' => '_esiTest', 'v' => ['name' => 'Varnish']], $esi->decode($query['data']));
    }

    public function testTamperedFragmentDataIsRejected(): void
    {
        $esi = Varnish::getInstance()->esi;
        parse_str((string)parse_url($esi->fragmentPath('_esiTest'), PHP_URL_QUERY), $query);
        $tampered = str_replace('_esiTest', '_other', $query['data']);

        $this->assertNull($esi->decode($tampered));
        $this->assertNull($esi->decode('{"t":"_esiTest"}'));
    }
}
