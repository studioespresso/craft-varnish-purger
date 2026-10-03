<?php

namespace studioespresso\varnish\tests\unit;

use Codeception\Test\Unit;
use studioespresso\varnish\models\Settings;

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
        $settings = new Settings(['purgeUrls' => ['$VARNISH_TEST_URL', 'http://varnish']]);

        $this->assertSame(['http://varnish-from-env:6081', 'http://varnish'], $settings->getResolvedPurgeUrls());
        putenv('VARNISH_TEST_URL');
    }

    public function testResolveMapsHostnameToIp(): void
    {
        putenv('VARNISH_TEST_IP=5.134.6.180');
        $settings = new Settings(['resolve' => ['jan.example.com' => '$VARNISH_TEST_IP', 'other.example.com' => '10.0.0.1']]);

        $this->assertSame(['jan.example.com:443:5.134.6.180'], $settings->getCurlResolveFor('https://jan.example.com'));
        $this->assertSame(['jan.example.com:80:5.134.6.180'], $settings->getCurlResolveFor('http://jan.example.com/'));
        $this->assertSame(['other.example.com:6081:10.0.0.1'], $settings->getCurlResolveFor('http://other.example.com:6081'));
        $this->assertSame([], $settings->getCurlResolveFor('http://varnish'), 'hosts without a mapping connect normally');
        putenv('VARNISH_TEST_IP');
    }

    public function testHostnamesAreNormalised(): void
    {
        putenv('VARNISH_TEST_HOST=WWW.Example.com:443');
        $settings = new Settings(['hostnames' => ['jan.example.com', '$VARNISH_TEST_HOST', ' jan.example.com ', '']]);

        $this->assertSame(['jan.example.com', 'www.example.com'], $settings->getResolvedHostnames());
        $this->assertSame([], (new Settings())->getResolvedHostnames(), 'empty by default: no limit');
        putenv('VARNISH_TEST_HOST');
    }

    public function testInvalidAddressesFailValidation(): void
    {
        $this->assertTrue((new Settings(['purgeUrls' => ['http://varnish', 'http://10.0.0.11:6081']]))->validate());
        $this->assertFalse((new Settings(['purgeUrls' => ['http://varnish:99999']]))->validate());
        $this->assertFalse((new Settings(['purgeUrls' => ['not a url']]))->validate());
    }
}
