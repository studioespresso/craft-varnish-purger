# AGENTS.md — Varnish Purger for Craft CMS

Guidance for AI agents (Claude Code, Cursor, …) working with **Varnish Purger** (`studioespresso/craft-varnish-purger`,
handle `varnish`, namespace `studioespresso\varnish`): installing it on a site, writing or debugging the VCL, changing
templates on a cached site, or working on the plugin itself. The README is the short human version; this file has the
background and the things that went wrong in real setups.

**The most important fact:** the plugin only does half the job. It tags pages and sends BAN requests. Whether anything
is cached or purged depends entirely on the VCL running in Varnish, and that VCL has to be adapted to the site and its
hosting. Never assume a setup works because Craft is configured: verify it with the steps in "Debugging playbook".

## Mental model

1. **Tagging.** For front-end `GET` site requests (not CP, action, preview or token requests), the plugin switches on
   Craft's own element cache tag collection (`Elements::startCollectingCacheInfo()`, the mechanism behind `{% cache %}`)
   while the *page template* renders. Craft records:
   - every element the template outputs (`element::{id}`), including related entries, assets and Matrix entries;
   - every element query, scoped as far as Craft can (`element::craft\elements\Entry::section:3`, `entryType:4`,
     `field:6` for Matrix…), or the catch-all `::*` for unscoped queries;
   - the tags stored with any `{% cache %}` block that's served from cache.
   Collection starts at `View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE` on purpose: Craft's route lookup (an unscoped `uri`
   query) runs before that and would otherwise give every page `Entry::*`, purged by every entry save.
2. **Response headers.** On a `200` response the plugin adds:
   - `X-Cache-Tags: all e 12 e:s:3 …` — the shortened tags (see below), `all` on every page;
   - `X-Cache-Site: 1` — the Craft site ID;
   - `X-Cache-Ttl: 86400` — Craft's suggested lifetime (shorter when a shown entry has an expiry date), the VCL turns
     it into the object's TTL and removes the header.
3. **Caching.** The VCL caches only responses that carry `X-Cache-Tags`. Everything else (404s, redirects, CP,
   actions, previews, POSTs, logged-in users) passes through uncached. It strips cookies from cacheable requests and
   `Set-Cookie` from cached responses, and stores the hostname as `X-Cache-Host` (hidden from visitors).
4. **Invalidation.** Craft fires `Elements::EVENT_INVALIDATE_CACHES` on save, delete, restore, move and Matrix owner
   changes, with exactly the tags to clear. The plugin queues them and, at the end of the request, sends one `BAN` per
   configured server (in parallel, chunked per 100 tags):
   ```
   BAN https://www.example.com/
   X-Cache-Tags-Ban: 45|e:s:3|e:t:1|e:any
   X-Cache-Site-Ban: 1                         (only for changes limited to one site)
   X-Cache-Hosts-Ban: example.com|www.example.com   (only when `hostnames` is configured)
   ```
   The VCL checks the sender against `acl purge`, validates the headers, and adds a ban:
   `obj.http.X-Cache-Tags ~ (^|[[:space:]])(45|e:s:3|…)([[:space:]]|$) && obj.http.X-Cache-Site == 1 && obj.http.X-Cache-Host ~ ^(…)$`.
   Only `obj.*` is used so Varnish's ban lurker can evict matches in the background.

## Tags

Craft tags are shortened by `TagHelper::headerTag()`; the same mapping is applied when tagging and when banning, so it
only needs to be deterministic (a collision can only over-purge).

| Header tag | Craft tag | Meaning |
|---|---|---|
| `all` | `element` | every page (used for "purge everything") |
| `12` | `element::12` | the page outputs element 12 |
| `e` | `element::craft\elements\Entry` | the page ran some entry query (only type-wide invalidation clears it) |
| `e:s:3` | `…Entry::section:3` | queried section 3 |
| `e:t:4` | `…Entry::entryType:4` | queried entry type 4 |
| `e:f:6` | `…Entry::field:6` | Matrix field 6 (nested entries) |
| `e:any` | `…Entry::*` | unscoped entry query: purged by **any** entry save |
| `e:search` | `…Entry::search` | entry search results (see "Search") |

Type letters: `e` entry, `c` category, `a` asset, `u` user, `t` tag, `g` global set, `b` content block, `ad` address,
`p` Commerce product, `v` Commerce variant (others keep their refHandle or lowercase class name). Scope letters: `s`
section, `t` entry type, `f` field, `o` owner, `g` group, `v` volume, `pt` product type. Anything else (e.g. tags added by
other plugins via `ElementQuery::EVENT_DEFINE_CACHE_TAGS`) is squeezed into `[A-Za-z0-9:._-]`.

## Invalidation rules and their edge cases

- **Drafts and revisions** never purge (autosaves would empty the cache otherwise).
- **Relation fields.** Craft gives relation field queries the catch-all `*` tag, which would purge every page using a
  relation field on any save. The plugin narrows Entries fields to their sections and Categories fields to their group
  (`PageTags::scopeRelationQuery()`). Only plain `section:`/`group:` sources are narrowed; other sources keep `*`.
- **Multi-site.** A save that only changes translatable content (translatable fields, a translatable title) is banned
  on that site only (`X-Cache-Site-Ban`). Everything else bans on all sites, because Craft silently propagates shared
  values to the other sites: untranslatable fields (relation fields are shared by default), post date, global status,
  new elements, and slug/URI/enabled-status changes (other sites link to this version, e.g. language switchers via
  `entry.localized`). `Purger::isSiteSpecificChange()` decides this from the element's dirty attributes/fields, which
  are reliable at invalidation time, including for CP saves that apply a draft. Limitation: a page that queries another
  site's content (`.site('*')`) is not purged by a change scoped to that other site.
- **Search.** CP saves (web requests) update Craft's search index in a queue job *after* the save and its purge. A
  search page re-cached in between would miss the new content, so search queries get an extra `e:search` tag and the
  plugin bans it again when Craft's `UpdateSearchIndex` job finishes (`Queue::EVENT_AFTER_EXEC`). Console saves index
  immediately. `e:search` is per element type, not per section, so indexing any entry also purges section-limited
  search pages (over-purge, never stale).
- **Expiry vs post dates.** Expiry dates shorten the TTL via `X-Cache-Ttl`. A *future post date* triggers nothing when
  it passes: listings show the new entry only after their TTL (1 day by default) or a manual purge.
- **Pagination.** All pages of a paginated listing carry the section tag, so a change purges all of them (a new
  article shifts every page). Pages past the last one should `{% exit 404 %}` (Craft otherwise renders the last page),
  so they aren't cached.
- **Data that isn't an element** never purges by itself: plugin records, settings, project config changes (a new
  section or single), Navigate menus (unless the Navigate version in use calls `invalidateAllCaches()` when nodes change), anything in custom tables.
  Call `Craft::$app->getElements()->invalidateAllCaches()` (or `invalidateCachesForElement()`) after changing such
  data; the plugin listens to those and Craft's own template caches stay in sync. After deploys, run
  `php craft varnish/purge` (template/CSS/JS changes aren't elements either).

## Configuration (`config/varnish.php` or Settings → Plugins → Varnish)

```php
<?php

use craft\helpers\App;

return [
    '*' => [],
    'dev' => [
        'purgeUrls' => ['http://varnish'],
    ],
    'production' => [
        'purgeUrls' => ['https://www.example.com'],
        'resolve' => ['www.example.com' => App::env('VARNISH_ORIGIN_IP')],
        'hostnames' => ['example.com', 'www.example.com'],
    ],
];
```

- **`purgeUrls`** — where BAN requests are *delivered*: one entry per Varnish instance (not per site; a ban carries the
  tags, and every site in that Varnish is matched). It must reach Varnish's own HTTP listener directly: not the admin
  port (varnishadm), not a CDN/Cloudflare hostname, not a host's separate purge endpoint. Setting it in the config file
  locks the CP table. Values may be env vars (`$VARNISH_URL`).
- **`resolve`** — `hostname => IP`, like an `/etc/hosts` entry used only for purging (cURL `CURLOPT_RESOLVE`). Use it
  when the hostname must be in the URL (shared hosting routes on it, HTTPS needs it for SNI/the certificate) but DNS
  points elsewhere (typically Cloudflare).
- **`hostnames`** — limit bans to pages cached under these hostnames. Required when other sites (another install,
  another customer) share the same Varnish; otherwise saving element 12 also purges their pages tagged 12 and "purge
  everything" empties their cache. List every hostname this install's pages are cached under, www-variants and aliases
  included: pages under a missing hostname are never purged again. Empty (default) = no limit. Not derived from site
  base URLs on purpose, because aliases are easy to miss.
- **Multi-environment gotcha:** Craft merges `'*'` into the active environment with `ArrayHelper::merge()`, which
  *appends* lists. Never set `purgeUrls` in `'*'` and in an environment: both sets of servers would receive bans.
  Environment keys match on substrings (`'prod'` also matches `production`).

## The VCL

Start from `example.vcl` (Varnish 6.6+) or `example-varnish-6.0.vcl` (Varnish 6.0, still common at hosting companies
as a long-term-support release; it uses `ban()` because `std.ban()`/`std.ban_error()` only exist since 6.6, so a ban
that fails to parse isn't reported back). The two files differ only in their header comment and the ban call; CI
compiles both. Things that must stay true in any adapted VCL:

- Cache only responses with `X-Cache-Tags`; mark everything else `uncacheable`.
- Pass the CP, `/actions`, previews/tokens, non-GET/HEAD, `Authorization`, and logged-in users (Craft's `_identity`
  cookie — not `CraftSessionId`, which anonymous visitors get too).
- Strip `Cookie` on cacheable requests and `Set-Cookie` on cached responses. A commented-out **cookie keep-list** in
  `vcl_recv` plus a `vcl_hash` block show how to keep a cookie the backend renders differently for (e.g. a consent
  choice) and cache one copy per value; only for cookies with a few possible values. Enable both or neither.
- Handle `BAN` before anything else that could catch it, check the sender, validate the headers
  (`X-Cache-Tags-Ban` must match `^[A-Za-z0-9:._|-]+$`, site `^[0-9]+$`, hosts `^[a-z0-9.-]+(\|[a-z0-9.-]+)*$`) so
  the regex can't be injected, and answer exactly `200 "Ban added"`: `varnish/check` treats any other 200 as "something
  else answered".
- Set `Surrogate-Capability` only after any `pipe`/bypass rules (a piped request's ESI tags are never processed) and
  `unset` the incoming one first, so a client can't make Craft emit raw `<esi:include>` tags.
- Remove the debug headers in production (`X-Cache-Tags`, `X-Cache-Site`); `X-Cache: HIT/MISS` can stay.

### Who may send BANs (`acl purge`)

Without this check anyone can send `X-Cache-Tags-Ban: all` repeatedly and turn Varnish into a pass-through (a cheap
denial of service). Which IP to check depends on how requests reach Varnish:

- **Varnish directly reachable:** check `client.ip`; refuse requests relayed by a proxy (Varnish appends `client.ip` to
  `X-Forwarded-For`, so a comma means more than one hop). This is what the examples do.
- **Behind the host's own proxy/load balancer:** `client.ip` is always the proxy. Find the header the proxy sets with
  the real client IP (some hosts use `X-Forwarded-For`, others a custom header such as `x-ff-ip`) and confirm it is
  *overwritten* by the proxy (send a forged value from outside: it must still be refused). Requests from the hosting
  itself may skip that proxy entirely and arrive without the header; then use `client.ip` as the fallback:
  `std.ip(req.http.<header>, client.ip)`. Do **not** allow "either `client.ip` or the header": the proxy's own IP (often
  `localhost`) would let every outside request through.
- **Shared servers:** the Craft server's outgoing IP may be shared with other customers; they could then send bans too
  (nuisance purges, no data access). A shared secret header checked in the VCL closes that gap (not built in yet).
- The 403 answer names the IP Varnish saw (`Forbidden: BAN from x.x.x.x (not in acl purge)`); `varnish/check` prints it.
  Add exactly that IP.

### Managed hosting pitfalls (seen in practice)

- The host may run **its own VCL template** until you switch to a custom VCL, and a **bypass switch** that sends all
  traffic past Varnish. Turning bypass off can take minutes to apply. Signs: the host's own debug header (e.g.
  `X-Varnish-cache`) or no Varnish headers at all; our VCL is active only when responses carry `X-Cache` and no
  `X-Cache-Ttl`.
- The host's template may cache by the backend's `Cache-Control` (a long `max-age` on HTML then means weeks of stale
  pages) and purge by URL only.
- A **separate purge endpoint** (often nginx on port 80 of an "admin"/Varnish IP) can accept `BAN`/`PURGE` itself and
  answer `200 Purged` (Varnish's built-in `vcl_purge` text): that is a URL purge of `/`, not a tag ban, and `ban.list`
  shows nothing from the plugin. The same host may answer normal GETs with `405`. Never point DNS at such an address.
- The **admin port** (varnishadm, `-T host:port`, protected by a secret) speaks the CLI protocol, not HTTP; cURL reports
  "Received HTTP/0.9". `varnishadm … vcl.list`, `vcl.show <name>`, `ban.list` and `banner` (version) are the best
  diagnostics. `Rejected 500` = wrong secret (the host's instructions may *append* to the secret file; overwrite it).
  Treat that secret as sensitive and regenerate it if it was shared.
- On some platforms Varnish sits **transparently in front of the hosting IP** for the hostnames you enable; on others it
  has its own IP the domain must point to. Check a working site on the same platform before changing DNS, and always
  test a new address with `curl --resolve host:443:IP https://host/` before pointing live traffic at it.
- With **Cloudflare** (proxied) in front: DNS for the domain returns Cloudflare, so BANs to the domain go through
  Cloudflare and are refused. Use `resolve` to send them to the origin directly. Cloudflare doesn't cache HTML by
  default (`cf-cache-status: DYNAMIC`); keep it that way, the plugin only purges Varnish.
- A long `Cache-Control: max-age` on HTML (often from `.htaccess`/`mod_expires`) makes *browsers* keep stale pages no
  matter how well Varnish is purged. HTML should get `no-cache` or a short max-age.

## Forms, CSRF and ESI

- Craft marks any response that prints a CSRF token as uncacheable (`Request::getCsrfToken()` sends
  `Cache-Control: no-store`), and Varnish honours it. Enable `->asyncCsrfInputs(true)` in `config/general.php`:
  `csrfInput()` then outputs `<craft-csrf-input>` and Craft's JS fetches the token (and sets the cookie) from
  `/actions/users/session-info`. Formie's form template uses `csrfInput()`, so Formie works with it; captcha tokens are
  not covered (use Formie's `refreshForCache()`). Templates printing `craft.app.request.csrfToken` directly still make
  the page uncacheable — that's safe, just not cached.
- **ESI:** `{{ craft.varnish.include('_partials/stock', {productId: product.id}) }}`. Behind Varnish (request header
  `Surrogate-Capability: …ESI/1.0`, set by the VCL) it outputs `<esi:include src="/actions/varnish/esi/render?data=…">`
  and sets `Surrogate-Control: content="ESI/1.0"` so the VCL enables `do_esi`; elsewhere it renders inline. The data is
  signed with Craft's security key (`Security::hashData()`), so the action can't render arbitrary templates; tampered
  data gets a 400. Fragments are action requests: never tagged, never cached. Pass scalars only. **Fragments cannot set
  cookies** (Varnish ignores fragment response headers): never use ESI for CSRF tokens.
- **Image transforms:** use `asset.getUrl(transform, true)` on cached pages so the cached HTML holds the final URL, not
  a temporary generate-transform URL.

## Console commands

```bash
php craft varnish/check                 # harmless test ban to every server; ✓ only for "200 Ban added"
php craft varnish/purge                 # everything (tag "all"), respects `hostnames`
php craft varnish/purge --tags=12,e:s:3 # specific header tags
php craft varnish/purge --site=french   # one site (handle or ID)
php craft clear-caches/varnish          # everything, without per-server output
```

Both commands exit non-zero on failure. `varnish/check` interprets the answers: 403 → add the shown IP to `acl purge`;
no response → wrong address/port or unreachable (and "HTTP/0.9" means the admin port); `?` → a 200 that isn't
"Ban added" (another VCL or a host purge endpoint answered). Every ban is also logged per server: failures always,
successes with devMode on.

## Debugging playbook (in this order)

1. `curl -sI https://site/page | grep -iE '^(x-cache|age|via|x-cache-tags|x-cache-site|x-cache-ttl|cache-control)'`,
   twice. Expect `X-Cache: MISS` then `HIT`, increasing `Age`, and no `X-Cache-Ttl`. Tags present but no `X-Cache` →
   Varnish isn't in the path or another VCL is active.
2. `php craft varnish/check` on the Craft server → must print `✓ … 200 Ban added`.
3. Save an entry shown on that page in the CP → the next request is `MISS`; a page on another site/hostname stays `HIT`.
4. If bans "succeed" but nothing is purged: `varnishadm … ban.list` must show `obj.http.X-Cache-Tags ~ …`.
5. Test while logged out (logged-in users always bypass the cache).

## PHP API

- `Varnish::getInstance()->purger->ban(array $headerTags, ?int $siteId = null): array` — sends a BAN now, returns
  one result per request (`url`, `ok`, `status`, `message`). `TagHelper::TAG_ALL` purges everything.
- `TagHelper::headerTag(string $craftTag): string` — convert a Craft tag to a header tag.
- `Varnish::getInstance()->pageTags->getTags()` — header tags of the page rendered in this request.
- `Varnish::getInstance()->esi->include($template, $vars)` — same as the Twig helper.
- Settings: `Varnish::getInstance()->getSettings()` (`purgeUrls`, `resolve`, `hostnames`, `getResolvedPurgeUrls()`,
  `getCurlResolveFor($url)`, `getResolvedHostnames()`).

## Working on the plugin

- Layout: `Varnish.php` (wiring), `services/PageTags.php` (collect/scope/headers), `services/Purger.php` (queue,
  site-scoping, BAN delivery), `services/Esi.php` + `controllers/EsiController.php`, `helpers/TagHelper.php`,
  `models/Settings.php` + `templates/_settings.twig`, `console/controllers/{Check,Purge}Controller.php`.
- PHP 8.3+ (`composer.json` `require.php` and `config.platform.php`, so the lock installs on 8.3; CI tests 8.3–8.5).
- Tests: Codeception with Craft's test module (`composer test`), needing an empty `testing` database the suite wipes.
  `tests/_bootstrap.php` clears all `CRAFT_DB_*` env vars (ddev sets them, and they would point the suite at the
  project database) and refuses to run unless the database resolves to `testing`. Never remove that guard.
- VCL changes: keep `example.vcl` and `example-varnish-6.0.vcl` in sync (only the ban call differs) and compile both
  (`varnishd -C -f`, CI job `vcl`). For behaviour, `varnishtest` with a `.vtc` file can drive a fake backend and
  clients, e.g. to check which cookies reach the backend.
- Don't add custom tagging or purging for elements — extend Craft's tags (`ElementQuery::EVENT_DEFINE_CACHE_TAGS`,
  `Element::EVENT_DEFINE_CACHE_TAGS`) so `{% cache %}` and Varnish stay consistent.
