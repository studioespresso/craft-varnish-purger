# Upgrading to Varnish Purger 6 (Craft 6)

This guide lists what you need to update when moving from Varnish Purger 1.x (Craft 5) to 6.x.

## Requirements

- Craft CMS 6
- PHP 8.5
- `craftcms/yii2-adapter` is not needed

Update your VCL: the check that passes logged-in users changed (see below). The rest of the VCL is the same as in 1.x.

## Upgrade steps

1. Move your config file (see below), if you have one.
2. Deploy, then purge everything once: `php craft varnish/purge`. Pages cached by 1.x contain ESI links in the old format, which 6.x no longer accepts.
3. Check the setup: `php craft varnish/check`, then log in and open a front-end page: you should stay logged in.

## Breaking changes

### VCL: logged-in users

1.x passed logged-in users through by Craft 5's `_identity` cookie. Craft 6 doesn't set it, so with the 1.x VCL Varnish strips their session cookie on front-end pages, and the new session Craft starts logs them out. Replace the cookie check in `vcl_recv` with the one from the plugin's `example.vcl` (or `example-varnish-6.0.vcl`):

Before:

```vcl
req.http.Cookie ~ "_identity="
```

After:

```vcl
req.http.Cookie ~ "(^|;\s*)([A-Za-z0-9_.-]+[-_]session|remember_web_[0-9a-f]+)="
```

It matches Laravel's default `{APP_NAME}-session` cookie and the remember-me cookie. If you set `SESSION_COOKIE`, use that name instead.

### Config file

The config file moved, and is now a plain Laravel config file. Craft's multi-environment format (`'*'`, `'dev'`, `'production'` keys) is no longer supported: use `env()` for values that differ per environment.

| Before | After |
| --- | --- |
| `config/varnish.php` | `config/craft/varnish.php` |

Publish a fresh copy with `php artisan vendor:publish --tag=varnish`.

Before:

```php
return [
    '*' => [],
    'production' => [
        'purgeUrls' => ['http://10.0.0.11:6081'],
    ],
];
```

After:

```php
return [
    'purgeUrls' => array_filter([env('VARNISH_URL')]),
];
```

### Classes and namespaces

Classes moved to Laravel-style folders. The root namespace (`studioespresso\varnish`) is unchanged.

| Before | After |
| --- | --- |
| `studioespresso\varnish\models\Settings` | `studioespresso\varnish\Settings` |
| `studioespresso\varnish\services\Esi` | `studioespresso\varnish\Services\Esi` |
| `studioespresso\varnish\services\PageTags` | `studioespresso\varnish\Services\PageTags` |
| `studioespresso\varnish\services\Purger` | `studioespresso\varnish\Services\Purger` |
| `studioespresso\varnish\helpers\TagHelper` | `studioespresso\varnish\Support\TagHelper` |
| `studioespresso\varnish\variables\VarnishVariable` | `studioespresso\varnish\Twig\VarnishVariable` |
| `studioespresso\varnish\controllers\EsiController` | `studioespresso\varnish\Http\Controllers\EsiController` |
| `studioespresso\varnish\console\controllers\CheckController` | `studioespresso\varnish\Console\Commands\CheckCommand` |
| `studioespresso\varnish\console\controllers\PurgeController` | `studioespresso\varnish\Console\Commands\PurgeCommand` |

`Varnish::getInstance()->purger` (and `->esi`, `->pageTags`) still work. In your own code, prefer resolving the service from the container: `app(Purger::class)`.

## Other changes

### Console commands

The commands are Artisan commands now. The Craft 5 names still work as aliases.

| Before | After |
| --- | --- |
| `php craft varnish/check` | `php craft varnish:check` |
| `php craft varnish/purge` | `php craft varnish:purge` |

## Unchanged

- `{{ craft.varnish.include('_esi/now') }}` in templates.
- The plugin handle (`varnish`), its settings and the CP settings page.
- The `X-Cache-Tags`, `X-Cache-Site` and ban headers the VCL reads.
- "Varnish cache" under Utilities → Caches (`php craft clear-caches/varnish`).
