# Minimal VCL for craft-varnish-purger, using core Varnish bans (no vmods needed).
# Only responses tagged by the plugin (X-Cache-Tags header) are cached; everything else passes through untouched.
vcl 4.1;

import std;

# Your web server (in ddev: the `web` container). Replace with your own host and port.
backend default {
  .host = "web";
  .port = "80";
}

# Hosts allowed to send BAN requests: replace these private ranges with your Craft server's IP(s).
# `php craft varnish/check` shows the IP Varnish sees if it refuses a ban.
acl purge {
  "localhost";
  "127.0.0.1";
  "10.0.0.0"/8;
  "172.16.0.0"/12;
  "192.168.0.0"/16;
}

sub vcl_recv {
  # DDEV only (remove in production): novarnish.* bypasses Varnish.
  if (req.http.Host ~ "^novarnish\.") {
    return (pipe);
  }

  # Tell Craft we can process <esi:include> tags (craft.varnish.include() falls back to inline rendering without
  # it). Only after the pipe above: piped requests bypass Varnish, so their ESI tags would never be processed.
  unset req.http.Surrogate-Capability;
  if (req.esi_level == 0) {
    set req.http.Surrogate-Capability = {"varnish="ESI/1.0""};
  }

  # Craft (Yii) builds the URL, host and site from these headers. Varnish caches by URL + Host only, so a request
  # carrying them could store a different page under a normal URL (cache poisoning): drop them.
  # X-Forwarded-Proto stays (TLS-terminating proxies set it), but is part of the hash, see vcl_hash.
  unset req.http.X-Rewrite-Url;
  unset req.http.X-Original-Url;
  unset req.http.X-Original-Host;
  unset req.http.X-Forwarded-Host;
  unset req.http.X-Forwarded-Port;
  unset req.http.X-Forwarded-Server;
  unset req.http.Forwarded;
  unset req.http.Front-End-Https;
  unset req.http.X-Craft-Site;

  if (req.method == "BAN") {
    # A proxy/load balancer also connects from an internal IP. Varnish appends client.ip to X-Forwarded-For,
    # so more than one address means the request was relayed: refuse it.
    if (client.ip !~ purge || req.http.X-Forwarded-For ~ ",") {
      # The reason ends up in Craft's log and `craft varnish/check`, so it says which IP to add to `acl purge`
      if (req.http.X-Forwarded-For ~ ",") {
        return (synth(403, "Forbidden: BAN relayed via proxy (X-Forwarded-For " + req.http.X-Forwarded-For + ")"));
      }
      return (synth(403, "Forbidden: BAN from " + client.ip + " (not in acl purge)"));
    }
    # Tags are joined with "|" by the plugin; refuse anything else so the header can't inject regex.
    # ("." is allowed for class names; as a regex wildcard it can only over-match, never under-match.)
    if (req.http.X-Cache-Tags-Ban !~ "^[A-Za-z0-9:._|-]+$") {
      return (synth(400, "Missing or invalid X-Cache-Tags-Ban header"));
    }
    # Only obj.* in the expression, so the ban lurker can evict matching objects in the background.
    set req.http.X-Ban-Expression = "obj.http.X-Cache-Tags ~ (^|[[:space:]])(" + req.http.X-Cache-Tags-Ban + ")([[:space:]]|$)";
    # X-Cache-Site-Ban: only that site's pages (content that only changed on one site)
    if (req.http.X-Cache-Site-Ban) {
      if (req.http.X-Cache-Site-Ban !~ "^[0-9]+$") {
        return (synth(400, "Invalid X-Cache-Site-Ban header"));
      }
      set req.http.X-Ban-Expression = req.http.X-Ban-Expression + " && obj.http.X-Cache-Site == " + req.http.X-Cache-Site-Ban;
    }
    # X-Cache-Hosts-Ban: only pages cached under these hostnames ("|"-separated), when other sites share this Varnish
    if (req.http.X-Cache-Hosts-Ban) {
      if (req.http.X-Cache-Hosts-Ban !~ "^[a-z0-9.-]+(\|[a-z0-9.-]+)*$") {
        return (synth(400, "Invalid X-Cache-Hosts-Ban header"));
      }
      set req.http.X-Ban-Expression = req.http.X-Ban-Expression + " && obj.http.X-Cache-Host ~ ^(" + req.http.X-Cache-Hosts-Ban + ")$";
    }
    if (!std.ban(req.http.X-Ban-Expression)) {
      return (synth(500, std.ban_error()));
    }
    return (synth(200, "Ban added"));
  }

  if (
    (req.method != "GET" && req.method != "HEAD") ||
    req.http.Authorization ||
    # The CP and action requests, also behind a site prefix like /nl. Change "admin" if you set cpTrigger.
    req.url ~ "^(/[^/]+)?/(admin|actions|index\.php)(/|$|\?)" ||
    # Tokens (previews, shared drafts, site tokens)
    req.url ~ "[?&](token|siteToken|x-craft-preview|x-craft-live-preview)=" ||
    req.http.X-Craft-Token || req.http.X-Craft-Preview-Token || req.http.X-Craft-Site-Token ||
    req.http.Cookie ~ "_identity="
  ) {
    return (pass);
  }

  # Cookies: by default all are stripped, so every visitor shares the cached page. To pass cookies the backend
  # renders differently for (e.g. a cookie-consent choice), use this cookie keep-list instead of the unset below,
  # adjust the "__consent" pattern, AND enable the vcl_hash block further down (one cached copy per value).
  # Only for cookies with a few possible values: a unique value per visitor makes every request a miss.
  # set req.http.Cookie = ";" + req.http.Cookie;
  # set req.http.Cookie = regsuball(req.http.Cookie, "; +", ";");
  # set req.http.Cookie = regsuball(req.http.Cookie, ";(__consent[^=]*)=", "; \1=");
  # set req.http.Cookie = regsuball(req.http.Cookie, ";[^ ][^;]*", "");
  # set req.http.Cookie = regsuball(req.http.Cookie, "^[; ]+|[; ]+$", "");
  # if (req.http.Cookie == "") {
  #   unset req.http.Cookie;
  # }
  unset req.http.Cookie;
  return (hash);
}

sub vcl_hash {
  # Craft builds absolute URLs with the scheme from X-Forwarded-Proto: keep http and https copies apart
  hash_data(req.http.X-Forwarded-Proto);
  # Enable together with the cookie keep-list in vcl_recv: one cached copy per value of the kept cookies.
  # if (req.http.Cookie) {
  #   hash_data(req.http.Cookie);
  # }
  # no return: Varnish then also hashes URL + host as usual
}

sub vcl_backend_response {
  # Responses that contain <esi:include> tags ask for processing; each include is fetched as its own request
  if (beresp.http.Surrogate-Control ~ "ESI/1.0") {
    unset beresp.http.Surrogate-Control;
    set beresp.do_esi = true;
  }

  # Untagged, or tagged but private (e.g. a page that output a CSRF token without asyncCsrfInputs): don't cache
  if (!beresp.http.X-Cache-Tags || beresp.http.Cache-Control ~ "(no-store|private)") {
    set beresp.uncacheable = true;
    set beresp.ttl = 120s;
    return (deliver);
  }
  unset beresp.http.Set-Cookie;
  # The hostname this page is cached under, for bans limited with X-Cache-Hosts-Ban
  set beresp.http.X-Cache-Host = std.tolower(regsub(bereq.http.host, ":[0-9]+$", ""));
  # Tags drive invalidation; the TTL is a safety net. Craft suggests one (shorter for entries with an expiry date).
  set beresp.ttl = std.duration(beresp.http.X-Cache-Ttl + "s", 1d);
  unset beresp.http.X-Cache-Ttl;
  set beresp.grace = 1h;
}

sub vcl_deliver {
  set resp.http.X-Cache = "MISS";
  if (obj.hits > 0) {
    set resp.http.X-Cache = "HIT";
  }
  # Internal: only used to limit bans by hostname
  unset resp.http.X-Cache-Host;
  # Keep the tags visible for debugging; uncomment to hide them in production.
  # unset resp.http.X-Cache-Tags;
  # unset resp.http.X-Cache-Site;
}
