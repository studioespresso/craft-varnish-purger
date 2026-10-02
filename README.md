# Varnish Purger for Craft CMS

Cache your Craft site in Varnish, and purge exactly the pages that changed when content is saved.

Every page Varnish caches is labelled with what it shows: the entries, assets and categories it outputs, the sections it lists, the Matrix fields it renders. When an editor saves something, the plugin tells Varnish to drop every page with a matching label. Edit an event and only the event page and the listings that show it are purged; the rest of the site stays cached.

The labels are Craft's own element cache tags, the ones behind `{% cache %}`, so the plugin follows Craft's rules for what a save affects. It uses Varnish's built-in bans, so no Varnish modules are needed.

## Requirements

- Craft CMS 5
- Varnish 6.6 or newer (tested with 7.7), in front of your site
- Varnish must be reachable from the Craft server, so it can receive purge requests

## Installation

```bash
composer require studioespresso/craft-varnish-purger
php craft plugin/install varnish
```

Then set up Varnish and tell the plugin where it lives.

## 1. Set up Varnish

Start from [`example.vcl`](example.vcl). It:

- only caches pages the plugin has tagged, and passes everything else (the CP, action requests, previews, logged-in users, non-GET requests) through to Craft
- strips cookies from cached pages
- accepts purge requests (`BAN`) only from the IP addresses in `acl purge`, and only when they're sent directly rather than relayed through a proxy or load balancer
- adds an `X-Cache: HIT/MISS` header, handy while testing

Adapt these before going live:

| In `example.vcl` | Change to |
|---|---|
| `backend default` | the host and port of your web server |
| `acl purge` | the IP addresses of your Craft server(s) only |
| `# unset resp.http.X-Cache-Tags;` and `# unset resp.http.X-Cache-Site;` | uncomment them, so visitors don't see the tags |

## 2. Tell Craft where Varnish lives

Go to **Settings → Plugins → Varnish** and add each Varnish server: its host and port.

- **Use the internal address**, e.g. `127.0.0.1` port `6081`, or the container name in Docker. Not the public site URL: the VCL refuses purge requests that come in through a proxy, CDN or load balancer.
- **Add every server** that caches the site. Each keeps its own cache, and each gets the purge request.
- **The port** is usually 6081 for Varnish from Linux packages, and 80 in Docker. Leave it empty for 80.
- **Hosts can be environment variables**, like `$VARNISH_HOST`.

Or configure the servers per environment in `config/varnish.php`. This overrides the CP setting and locks the table:

```php
<?php

return [
    'purgeUrls' => [
        craft\helpers\App::env('VARNISH_PURGE_URL') ?: 'http://127.0.0.1:6081',
    ],
];
```

If no servers are configured, pages are still tagged and cached but nothing is ever purged. A warning is logged each time a purge is skipped.

## 3. Check that it works

Request a page twice and look at the headers:

```bash
curl -sI https://example.com/news | grep -i x-cache
# X-Cache: MISS
# X-Cache-Tags: all e 12 e:s:3 45
curl -sI https://example.com/news | grep -i x-cache
# X-Cache: HIT
```

Save one of the entries on that page in the CP, and the next request is a `MISS` again. Failed purges are always logged as `Varnish ban failed for …`; with `devMode` on, successful ones are logged too, as `Varnish banned: …`.

## Purging everything

Use **Utilities → Caches → Varnish cache**, or:

```bash
php craft clear-caches/varnish
```

## How pages are tagged

While the page template renders, Craft records:

- **every element the template outputs**, including related entries, assets, Matrix entries and anything inside `{% cache %}` blocks
- **every element query**, scoped as narrowly as Craft can: by section, entry type, field, volume or group

Tags are shortened to keep the header small:

| Tag | Means the page… | Purged when… |
|---|---|---|
| `12` | outputs element 12 | element 12 is saved, deleted, restored or moved |
| `e:s:3` | lists entries from section 3 | any entry in section 3 changes, including new ones |
| `e:t:4`, `e:f:6` | queries entry type 4, or the Matrix field 6 | an entry of that type, or in that field, changes |
| `e:any` | runs an entry query with no scope | any entry changes |
| `all` | is cached | everything is purged |

The element type comes first: `e` entry, `c` category, `a` asset, `u` user, `t` tag, `g` global set, `b` content block, `ad` address, `p` Commerce product, `v` Commerce variant. Then the scope: `s` section, `t` entry type, `f` field, `o` owner, `g` group, `v` volume, `pt` product type.

Some details:

- **Matrix:** saving a nested entry also purges its owner's pages.
- **Drafts and revisions** never purge anything, so autosaves don't empty the cache.
- **Relation fields:** Craft tags these queries `e:any` or `c:any`, so any entry or category save would purge every page using such a field. The plugin narrows Entries fields to the sections they allow, and Categories fields to their category group.
- **Expiry dates:** pages are cached until the nearest expiry date of an entry they show, or for Craft's `cacheDuration` (1 day by default).
- **Multi-site:** each page also carries its site ID (`X-Cache-Site`). When a save only changes translatable content (translatable fields, a translatable title), only that site's pages are purged. Anything else purges the affected pages on every site: shared values like untranslatable fields or the post date (Craft copies those to the other sites), new entries, and changes to a version's slug, URI or enabled status (other sites link to it, e.g. from a language switcher). One exception: a page that shows another site's content (`craft.entries.site('*')`) isn't purged by a change limited to that other site.
- **What gets tagged:** only front-end `GET` requests that return `200`. Error pages, redirects, CP requests and previews are never tagged, so Varnish doesn't cache them.

## Purge requests

When content changes, the plugin collects the tags Craft invalidates and, at the end of the request, sends one request to each Varnish server:

```
BAN http://127.0.0.1:6081/
X-Cache-Tags-Ban: 45|e:s:3|e:t:1|e:any
X-Cache-Site-Ban: 1
```

`X-Cache-Site-Ban` is only sent when the change was limited to one site.

Varnish then drops every cached page carrying one of those tags, whatever its URL. Requests go to all servers in parallel. An unreachable server is logged and skipped, and doesn't slow down saving by more than a few seconds.

## Known limitations

- **Future post dates:** an entry scheduled to go live doesn't trigger a purge when it does. Listings pick it up once their cached copy expires.
- **Forms and CSRF tokens:** a cached page contains the same CSRF token for every visitor. Load forms or tokens dynamically on cached pages.
- **No retries:** a server that misses a purge keeps its old pages until they expire or the cache is cleared.
- **Bulk resaves:** every save adds a ban, so resaving thousands of entries adds a lot. Varnish's ban lurker clears them in the background; keep an eye on `varnishadm ban.list` during large imports.

## Development

The tests use Codeception with Craft's test module, and need an empty `testing` database. The suite wipes and reinstalls it on every run.

```bash
composer install
composer test
```

`tests/_craft/config/db.php` sets the connection, and can be overridden with `DB_SERVER`, `DB_USER` and `DB_PASSWORD`. `tests/_bootstrap.php` clears any `CRAFT_DB_*` environment variables first, because hosts like ddev set those and they would otherwise point the suite at your project's database. It refuses to run unless the database resolves to `testing`.

In ddev:

```bash
ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS testing"
ddev exec -d /var/www/html/code/craft-varnish-purger composer test
```

CI runs ECS, PHPStan and the tests on every push and pull request (`.github/workflows/ci.yml`).
