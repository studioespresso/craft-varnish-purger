<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use studioespresso\varnish\models\Settings;
use studioespresso\varnish\services\Purger;

class SettingsTest extends Unit
{
    public function testTableRowsBecomeUrls(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['purgeUrls' => [
            ['host' => 'varnish', 'port' => '80'],
            ['host' => '10.0.0.11', 'port' => '6081'],
            ['host' => 'https://cache.internal', 'port' => '443'],
            ['host' => 'https://cache.internal', 'port' => '8443'],
            ['host' => '$VARNISH_HOST', 'port' => ''],
            ['host' => '  ', 'port' => '80'],
        ]]);

        $this->assertSame([
            'http://varnish',
            'http://10.0.0.11:6081',
            'https://cache.internal',
            'https://cache.internal:8443',
            '$VARNISH_HOST',
        ], $settings->purgeUrls);
    }

    public function testEmptyTableClearsServers(): void
    {
        $settings = new Settings(['purgeUrls' => ['http://varnish']]);
        $settings->setAttributes(['purgeUrls' => '']);

        $this->assertSame([], $settings->purgeUrls);
    }

    public function testConfigFileUrlsAreKeptAsIs(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['purgeUrls' => ['http://varnish', '$VARNISH_URL']]);

        $this->assertSame(['http://varnish', '$VARNISH_URL'], $settings->purgeUrls);
    }

    public function testUrlsBecomeTableRows(): void
    {
        $settings = new Settings(['purgeUrls' => [
            'http://varnish',
            'http://10.0.0.11:6081',
            'https://cache.internal',
            '$VARNISH_URL',
        ]]);

        $this->assertSame([
            ['host' => 'varnish', 'port' => 80],
            ['host' => '10.0.0.11', 'port' => 6081],
            ['host' => 'https://cache.internal', 'port' => 443],
            ['host' => '$VARNISH_URL', 'port' => ''],
        ], $settings->getServers());
    }

    public function testRowsSurviveARoundTrip(): void
    {
        $urls = ['http://varnish', 'http://10.0.0.11:6081', 'https://cache.internal:8443'];
        $settings = new Settings(['purgeUrls' => $urls]);
        $settings->setServers($settings->getServers());

        $this->assertSame($urls, $settings->purgeUrls);
    }

    public function testEnvironmentVariablesAreResolved(): void
    {
        putenv('VARNISH_TEST_URL=http://varnish-from-env:6081');
        try {
            $settings = new Settings(['purgeUrls' => ['$VARNISH_TEST_URL', 'http://varnish', '$VARNISH_TEST_UNSET']]);

            $this->assertSame(['http://varnish-from-env:6081', 'http://varnish'], $settings->getResolvedPurgeUrls(), 'unset variables are dropped');
        } finally {
            putenv('VARNISH_TEST_URL');
        }
    }

    public function testResolveMapsHostnameToIp(): void
    {
        putenv('VARNISH_TEST_IP=203.0.113.10');
        try {
            $settings = new Settings(['resolve' => ['jan.example.com' => '$VARNISH_TEST_IP', 'other.example.com' => '10.0.0.1', 'unset.example.com' => '$VARNISH_TEST_UNSET']]);

            $this->assertSame(['jan.example.com:443:203.0.113.10'], $settings->getCurlResolveFor('https://jan.example.com'));
            $this->assertSame(['jan.example.com:80:203.0.113.10'], $settings->getCurlResolveFor('http://jan.example.com/'));
            $this->assertSame(['other.example.com:6081:10.0.0.1'], $settings->getCurlResolveFor('http://other.example.com:6081'));
            $this->assertSame([], $settings->getCurlResolveFor('http://varnish'), 'hosts without a mapping connect normally');
            $this->assertSame([], $settings->getCurlResolveFor('http://unset.example.com'));
        } finally {
            putenv('VARNISH_TEST_IP');
        }
    }

    public function testHostnamesAreNormalised(): void
    {
        putenv('VARNISH_TEST_HOST=WWW.Example.com:443, shop.example.com.');
        try {
            $settings = new Settings(['hostnames' => ['jan.example.com', '$VARNISH_TEST_HOST', ' jan.example.com ', '', '$VARNISH_TEST_UNSET', 'bad/host']]);

            $this->assertSame(['jan.example.com', 'www.example.com', 'shop.example.com'], $settings->getResolvedHostnames());
            $this->assertSame([], (new Settings())->getResolvedHostnames(), 'empty by default: no limit');
        } finally {
            putenv('VARNISH_TEST_HOST');
        }
    }

    public function testBanHeaders(): void
    {
        $this->assertSame(['X-Cache-Tags-Ban' => '12|e:s:3'], Purger::banHeaders(['12', 'e:s:3'], null, []));
        $this->assertSame(
            ['X-Cache-Tags-Ban' => '12', 'X-Cache-Site-Ban' => '2', 'X-Cache-Hosts-Ban' => 'example.com|www.example.com'],
            Purger::banHeaders(['12'], 2, ['example.com', 'www.example.com']),
        );
    }

    public function testInvalidAddressesFailValidation(): void
    {
        $this->assertTrue((new Settings(['purgeUrls' => ['http://varnish', 'http://10.0.0.11:6081']]))->validate());
        $this->assertFalse((new Settings(['purgeUrls' => ['http://varnish:99999']]))->validate());
        $this->assertFalse((new Settings(['purgeUrls' => ['not a url']]))->validate());
    }
}
