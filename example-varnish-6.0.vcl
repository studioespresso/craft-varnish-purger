# Minimal VCL for craft-varnish-purger, for Varnish 6.0 (use example.vcl on 6.6 and newer).
# Same as example.vcl, except that bans use ban() instead of std.ban(), which only exists since 6.6: a ban
# Varnish can't parse isn't reported back to Craft (the X-Cache-Tags-Ban header is validated before banning).
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
  # DDEV: novarnish.* bypasses Varnish.
  if (req.http.Host ~ "^novarnish\.") {
    return (pipe);
  }

  # Tell Craft we can process <esi:include> tags (craft.varnish.include() falls back to inline rendering without
  # it). Only after the pipe above: piped requests bypass Varnish, so their ESI tags would never be processed.
  unset req.http.Surrogate-Capability;
  if (req.esi_level == 0) {
    set req.http.Surrogate-Capability = {"varnish="ESI/1.0""};
  }

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
    # With X-Cache-Site-Ban, only that site's pages are banned (content that only changed on one site).
    if (req.http.X-Cache-Site-Ban) {
      if (req.http.X-Cache-Site-Ban !~ "^[0-9]+$") {
        return (synth(400, "Invalid X-Cache-Site-Ban header"));
      }
      ban("obj.http.X-Cache-Site == " + req.http.X-Cache-Site-Ban + " && obj.http.X-Cache-Tags ~ (^|[[:space:]])(" + req.http.X-Cache-Tags-Ban + ")([[:space:]]|$)");
      return (synth(200, "Ban added"));
    }
    ban("obj.http.X-Cache-Tags ~ (^|[[:space:]])(" + req.http.X-Cache-Tags-Ban + ")([[:space:]]|$)");
    return (synth(200, "Ban added"));
  }

  if (
    (req.method != "GET" && req.method != "HEAD") ||
    req.http.Authorization ||
    req.url ~ "^/(admin|actions|index\.php)" ||
    req.url ~ "[?&](token|x-craft-preview|x-craft-live-preview)=" ||
    req.http.Cookie ~ "_identity="
  ) {
    return (pass);
  }

  unset req.http.Cookie;
  return (hash);
}

sub vcl_backend_response {
  # Responses that contain <esi:include> tags ask for processing; each include is fetched as its own request
  if (beresp.http.Surrogate-Control ~ "ESI/1.0") {
    unset beresp.http.Surrogate-Control;
    set beresp.do_esi = true;
  }

  if (!beresp.http.X-Cache-Tags) {
    set beresp.uncacheable = true;
    set beresp.ttl = 120s;
    return (deliver);
  }
  unset beresp.http.Set-Cookie;
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
  # Keep the tags visible for debugging; uncomment to hide them in production.
  # unset resp.http.X-Cache-Tags;
  # unset resp.http.X-Cache-Site;
}
