# Varnish Purger for Craft CMS

![Screenshot](https://www.studioespresso.co/assets/varnish_purgner_github.png)

Cache your Craft site in Varnish, and purge exactly the pages that changed when content is saved.

Every page Varnish caches is labelled with what it shows: the entries, assets and categories it outputs, the sections it lists, the Matrix fields it renders. When an editor saves something, the plugin tells Varnish to drop every page with a matching label. Edit an event and only the event page and the listings that show it are purged; the rest of the site stays cached.

The labels are Craft's own element cache tags, the ones behind `{% cache %}`, so the plugin follows Craft's rules for what a save affects. It uses Varnish's built-in bans, so no Varnish modules are needed.

> [!IMPORTANT]
> **This plugin only works together with a VCL tuned for it, and that VCL depends on the site and its hosting.** The plugin tags pages and sends BAN requests; Varnish has to cache those tagged pages and turn the BANs into bans. The example VCLs are a starting point, not a drop-in config. Expect to adapt them to things like:
>
> - **Your Varnish version:** `std.ban()` needs Varnish 6.6+; on 6.0, use `example-varnish-6.0.vcl`.
> - **How requests reach Varnish:** behind a proxy, load balancer or CDN, the client IP that `acl purge` should check may arrive in a header set by that proxy instead of `client.ip`, and the Craft server may reach Varnish by yet another route.
> - **Managed hosting:** hosts often run their own VCL template, a bypass switch, or a separate purge endpoint that handles BAN/PURGE itself and never reaches your VCL. Find out how to load your own VCL and where it receives requests.
> - **The site itself:** backend address, what must never be cached (login areas, carts, per-visitor content), cookies, and the hostnames it's served under.
>
> Verify the result on the actual setup: `php craft varnish/check` must answer `Ban added`, pages must show `X-Cache: HIT` on a repeat request, and saving an entry must turn its page into a `MISS`. Test before pointing live traffic at Varnish.

## Requirements

- Craft CMS 5, on PHP 8.3 or newer
- Varnish 6.0 or newer in front of your site (tested with 6.0, 6.6 and 7.7)
- Varnish must be reachable from the Craft server, so it can receive purge requests

## Installation

```bash
composer require studioespresso/craft-varnish-purger
php craft plugin/install varnish
```

Then set up Varnish and tell the plugin where it lives.

## 1. Set up Varnish

Start from [`example.vcl`](example.vcl) on Varnish 6.6 and newer, or from [`example-varnish-6.0.vcl`](example-varnish-6.0.vcl) on Varnish 6.0 (check with `varnishd -V`, `varnishadm banner`, or the `Via` response header). The 6.0 version uses `ban()` instead of `std.ban()`, so a ban Varnish can't parse isn't reported back to Craft; otherwise they're identical. Either one:

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

Or configure the servers in `config/varnish.php`. This overrides the CP setting and locks the table. Use Craft's per-environment format to give each environment (`CRAFT_ENVIRONMENT`) its own servers:

```php
<?php

use craft\helpers\App;

return [
    '*' => [],
    'dev' => [
        'purgeUrls' => ['http://varnish'],
    ],
    'staging' => [
        'purgeUrls' => [App::env('VARNISH_PURGE_URL') ?: 'http://127.0.0.1:6081'],
    ],
    'production' => [
        'purgeUrls' => ['http://10.0.0.11:6081', 'http://10.0.0.12:6081'],
    ],
];
```

- Set `purgeUrls` per environment, **not in `'*'` as well**: Craft merges `'*'` into the environment and appends lists, so production would ban on both sets of servers.
- Environment keys match on substrings: `'prod'` also matches `production`.
- Leave `purgeUrls` out for an environment to manage its servers in the CP instead.

Two more options, for hosting setups (config file only):

```php
'production' => [
    'purgeUrls' => ['https://www.example.com'],
    // Connect to this IP for the purge URL's hostname, like an /etc/hosts entry just for purging. Use it when DNS
    // points elsewhere (e.g. Cloudflare) but the hostname must stay in the URL (shared hosting routes on it, HTTPS needs it).
    'resolve' => ['www.example.com' => '203.0.113.10'],
    // Only ban pages cached under these hostnames: needed when other sites share the same Varnish. List every
    // hostname this install's pages are cached under (www-variants, aliases): pages under a missing one are never purged.
    'hostnames' => ['example.com', 'www.example.com'],
],
```

If no servers are configured, pages are still tagged and cached but nothing is ever purged. A warning is logged each time a purge is skipped.

## 3. Check that it works

After deploying, check that every configured server accepts bans from this server:

```bash
php craft varnish/check
# ✓ http://10.0.0.11:6081: 200 Ban added
# ✗ http://10.0.0.12:6081: 403 Forbidden: BAN from 10.0.0.5 (not in acl purge)
```

It sends a harmless test ban (a tag no page carries) and exits with an error code if any server fails, so it can run in a deploy script. When Varnish refuses a ban, the answer names the IP it saw: that's the address to add to `acl purge`.

Then check caching itself:

Request a page twice and look at the headers:

```bash
curl -sI https://example.com/news | grep -i x-cache
# X-Cache: MISS
# X-Cache-Tags: all e 12 e:s:3 45
curl -sI https://example.com/news | grep -i x-cache
# X-Cache: HIT
```

Save one of the entries on that page in the CP, and the next request is a `MISS` again. Every ban is logged per server with Varnish's answer: failures always (`Varnish ban failed (http://… → 403 Forbidden: BAN from 10.0.0.5 …)`), successes with `devMode` on (`Varnish banned: … (http://… → 200 Ban added)`).

## ESI (Edge Side Includes)

Render part of a cached page in its own request, so it can change on every page view while the page around it stays cached:

```twig
{{ craft.varnish.include('_esi/now', {entryId: entry.id}) }}
```

- Behind Varnish this outputs an `<esi:include>` tag, which Varnish replaces with the fragment. Anywhere else (no Varnish, `novarnish.` hosts, console) the template is rendered inline, so templates work either way.
- The fragment is rendered by a plugin action (`/actions/varnish/esi/render`). Its parameters are signed with Craft's security key, so it can't be used to render arbitrary templates. It's never cached.
- Pass simple values only (IDs, strings): the fragment is rendered in a separate request, on the same site as the page.
- Varnish drops the headers of ESI fragments, so a fragment can't set cookies. Don't use it for CSRF tokens: enable Craft's `asyncCsrfInputs` instead.

`example.vcl` handles both sides: it sets `Surrogate-Capability` on requests (so Craft knows it can output ESI tags), and processes responses that send `Surrogate-Control: content="ESI/1.0"`.

## Purging everything

Use **Utilities → Caches → Varnish cache**, or from the command line, which shows each server's answer:

```bash
php craft varnish/purge                       # every page
php craft varnish/purge --tags=12,e:s:3       # only pages with these tags (element 12, entries of section 3)
php craft varnish/purge --site=french         # only pages of one site (handle or ID)
```

`php craft clear-caches/varnish` purges everything too. All of these respect the `hostnames` setting.

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
| `e:search` | runs an entry search | Craft finishes updating the search index for an entry |
| `all` | is cached | everything is purged |

The element type comes first: `e` entry, `c` category, `a` asset, `u` user, `t` tag, `g` global set, `b` content block, `ad` address, `p` Commerce product, `v` Commerce variant. Then the scope: `s` section, `t` entry type, `f` field, `o` owner, `g` group, `v` volume, `pt` product type.

Some details:

- **Matrix:** saving a nested entry also purges its owner's pages.
- **Drafts and revisions** never purge anything, so autosaves don't empty the cache.
- **Relation fields:** Craft tags these queries `e:any` or `c:any`, so any entry or category save would purge every page using such a field. The plugin narrows Entries fields to the sections they allow, and Categories fields to their category group.
- **Search results:** pages that use `.search()` are also tagged `e:search`. CP saves update Craft's search index in a queue job after the save (and after its purge), so the plugin purges search pages again once that job has run. Otherwise a search re-cached in between would miss the new content.
- **Expiry dates:** pages are cached until the nearest expiry date of an entry they show, or for Craft's `cacheDuration` (1 day by default).
- **Multi-site:** each page also carries its site ID (`X-Cache-Site`). When a save only changes translatable content (translatable fields, a translatable title), only that site's pages are purged. Anything else purges the affected pages on every site: shared values like untranslatable fields or the post date (Craft copies those to the other sites), new entries, and changes to a version's slug, URI or enabled status (other sites link to it, e.g. from a language switcher). One exception: a page that shows another site's content (`craft.entries.site('*')`) isn't purged by a change limited to that other site.
- **What gets tagged:** only front-end `GET` requests that return `200`. Error pages, redirects, CP requests and previews are never tagged, so Varnish doesn't cache them.

## Purge requests

When content changes, the plugin collects the tags Craft invalidates and, at the end of the request, sends one request to each Varnish server:

```
BAN http://127.0.0.1:6081/
X-Cache-Tags-Ban: 45|e:s:3|e:t:1|e:any
X-Cache-Site-Ban: 1
X-Cache-Hosts-Ban: example.com|www.example.com
```

`X-Cache-Site-Ban` is only sent when the change was limited to one site, and `X-Cache-Hosts-Ban` only when `hostnames` is configured. The VCL stores the hostname each page was cached under (`X-Cache-Host`, hidden from visitors) to match it.

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
