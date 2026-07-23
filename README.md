# Nginx Cache Purger

[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.txt)

Purges the Nginx FastCGI cache when WordPress content changes — automatically on
publish, update, trash and delete, or site-wide from a button in the admin bar.

No settings page, no database options, no cron. The plugin sends an HTTP request
to a `/purge` location that Nginx handles; everything else is Nginx configuration.

---

## Contents

- [Requirements](#requirements)
- [Installing the Nginx module](#installing-the-nginx-module)
- [Nginx configuration](#nginx-configuration)
- [Installing the plugin](#installing-the-plugin)
- [Verifying it works](#verifying-it-works)
- [What gets purged, and when](#what-gets-purged-and-when)
- [Filters](#filters)
- [Purging behind a proxy or Cloudflare](#purging-behind-a-proxy-or-cloudflare)
- [Troubleshooting](#troubleshooting)
- [Credits](#credits)
- [Changelog](#changelog)

---

## Requirements

| | |
|---|---|
| WordPress | 6.0 or newer |
| PHP | 7.4 or newer |
| Nginx | built with **ngx_cache_purge** — see below |
| WooCommerce | optional; product and product-category purging activates automatically |

### The Nginx module matters

This plugin needs [**nginx-modules/ngx_cache_purge**](https://github.com/nginx-modules/ngx_cache_purge)
— the actively maintained continuation of Piotr Sikora's original module, which
passed through torden's fork around 2017 and has been developed there ever since.

**The abandoned FRiCKLE repository will not work.** The admin-bar button clears
the site with a single wildcard request (`/purge/*`), and `purge_all` /
wildcard purging landed after FRiCKLE development stopped around 2015 — that
repository can only purge one exact key per request, and no longer compiles
against current Nginx.

If you have seen this described elsewhere as "the Torden fork", it is the same
lineage: one repository, handed on twice.

Check what you have:

```bash
nginx -V 2>&1 | tr ' ' '\n' | grep cache_purge
```

If that prints nothing, the module is missing. If it prints a path, confirm it is
the fork by testing a wildcard purge once configured (see
[Verifying it works](#verifying-it-works)) — the fork answers `200`, FRiCKLE
answers `404`/`412`.

---

## Installing the Nginx module

The module is compiled into Nginx; it cannot be added to an existing binary at
runtime. Some distributions ship it (`nginx-module-cache-purge`, or Nginx
built by hosting panels), otherwise rebuild Nginx with it:

```bash
git clone https://github.com/nginx-modules/ngx_cache_purge.git
cd /path/to/nginx-source
./configure --add-module=/path/to/ngx_cache_purge \
            ... your existing configure arguments ...
make && make install
```

Copy your existing arguments from `nginx -V` so you do not lose modules you
already depend on. `--add-dynamic-module=` works too if you prefer to load it
with `load_module`.

---

## Nginx configuration

Two pieces are needed: a FastCGI cache, and a `/purge` location. This is a
complete working example — replace `example.com` and the zone name `wpcache`.

### 1. In the `http` context

`fastcgi_cache_path` and `map` are only valid at `http` level. Files in
`conf.d/` are included inside `http`, so the top of your site's `.conf` file
works fine.

```nginx
fastcgi_cache_path /var/cache/nginx/wpcache levels=1:2 keys_zone=wpcache:100m
                   inactive=12h max_size=512m;

# Never cache for a visitor with a session-ish cookie.
map $http_cookie $wp_nc_cookie {
    default                     0;
    ~*wordpress_logged_in       1;
    ~*wp-postpass               1;
    ~*comment_author            1;
    ~*wordpress_no_cache        1;
    ~*woocommerce_items_in_cart 1;
    ~*woocommerce_cart_hash     1;
    ~*wp_woocommerce_session    1;
}

# Never cache these paths.
map $request_uri $wp_nc_uri {
    default                          0;
    ~*^/wp-admin                     1;
    ~*^/wp-.*\.php                    1;   # wp-login, wp-signup, wp-trackback, wp-cron, wp-comments-post, etc.
    ~*^/xmlrpc\.php                   1;
    ~*^/wp-json                       1;
    ~*^/feed                          1;
    ~*sitemap.*\.xml                  1;   # sitemap.xml and Yoast-style post-sitemap.xml / page-sitemap2.xml
    ~*^/purge                         1;
    ~*^/(cart|checkout|my-account)    1;
    ~*^/wc-api                        1;
    ~*^/store-api                     1;
}

map $request_method $wp_nc_method {
    default 1;
    GET     0;
    HEAD    0;
}

# Anything with a query string bypasses the cache. This covers ?wc-ajax=,
# ?add-to-cart=, ?preview=, ?s= without listing them, and keeps query strings
# out of the cache key — which is what wildcard purging needs.
map $query_string $wp_nc_query {
    default 1;
    ""      0;
}

map "$wp_nc_cookie$wp_nc_uri$wp_nc_method$wp_nc_query" $wp_skip_cache {
    default 1;
    "0000"  0;
}
```

### 2. The purge location, inside `server { }`

```nginx
# Purge endpoint. The plugin requests it over the site's own public hostname,
# so the request arrives from this server's public IP — not from 127.0.0.1.
location ~ ^/purge(/.*) {
    allow 127.0.0.1;
    allow ::1;
    allow 203.0.113.10;   # <- this server's own public IP
    deny  all;

    cache_purge_response_type json;
    fastcgi_cache_purge wpcache "$scheme$host$1";
}
```

The key expression here **must match `fastcgi_cache_key` exactly**, with `$1`
standing in for the path. If they differ, every purge answers `412` and nothing
is ever cleared.

### 3. The cached PHP location, inside `server { }`

```nginx
location ~ \.php$ {
    include         fastcgi_params;
    fastcgi_param   SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass    php-fpm;   # your upstream / socket

    # If a global open_file_cache is set, turn it off here: Nginx also caches
    # the descriptors of *cache* files, so after a purge unlinks a file it keeps
    # serving the deleted inode and purges appear to do nothing for up to
    # open_file_cache_valid seconds.
    open_file_cache             off;

    fastcgi_cache               wpcache;
    fastcgi_cache_key           "$scheme$host$request_uri";
    fastcgi_cache_methods       GET;
    fastcgi_cache_valid         200 301 302 12h;
    fastcgi_cache_valid         404 1m;
    fastcgi_cache_lock          on;
    fastcgi_cache_revalidate    on;
    fastcgi_cache_use_stale     error timeout updating invalid_header http_500 http_503;
    fastcgi_cache_background_update on;

    # WordPress sends "Cache-Control: no-cache, must-revalidate" on most pages;
    # without this nothing is ever stored. Set-Cookie is deliberately NOT
    # ignored, so a response that sets a cookie stays uncached.
    fastcgi_ignore_headers      Cache-Control Expires;

    fastcgi_cache_bypass        $wp_skip_cache;
    fastcgi_no_cache            $wp_skip_cache;
}
```

Add this while testing, then remove it:

```nginx
add_header X-FastCGI-Cache $upstream_cache_status always;
```

### Two mistakes worth avoiding

**Use `$request_uri`, not `$uri`, in the cache key.** WordPress vhosts route
everything through `try_files … /index.php`, which rewrites `$uri` to
`/index.php`. A key built on `$uri` collapses the entire site onto one cache
entry — every page serves whatever was cached first.

**Leave `$request_method` out of the key.** With it, `GET` and `HEAD` are stored
as separate entries, and since the plugin always purges with `GET`, the `HEAD`
copies can never be cleared. `fastcgi_cache_methods GET` plus a method-free key
lets a `HEAD` request be answered from the cached `GET` entry.

### Apply

```bash
mkdir -p /var/cache/nginx/wpcache
chown nginx:nginx /var/cache/nginx/wpcache
nginx -t && systemctl reload nginx
```

---

## Installing the plugin

**From a release zip** — *Plugins → Add New → Upload Plugin*, choose the zip,
*Install Now*, then *Activate*.

**From git**

```bash
cd wp-content/plugins
git clone https://github.com/wbdv/nginx-cache-purger.git
wp plugin activate nginx-cache-purger
```

There is nothing to configure in WordPress. The plugin derives the purge URL
from `home_url()`.

---

## Verifying it works

```bash
# 1. Caching. First request MISS, second HIT.
curl -sI https://example.com/ | grep -i x-fastcgi-cache
curl -sI https://example.com/ | grep -i x-fastcgi-cache

# 2. Single-entry purge.
curl -s https://example.com/purge/
# {"Key": "httpsexample.com/", "Status": "purged"}

# 3. Wildcard purge — fork-only. 200 means the fork; 404/412 means FRiCKLE.
curl -s "https://example.com/purge/*"

# 4. Bypasses. All of these must report BYPASS.
curl -sI -b "wordpress_logged_in_x=1" https://example.com/ | grep -i x-fastcgi-cache
curl -sI "https://example.com/?utm=x"                      | grep -i x-fastcgi-cache
curl -sI https://example.com/cart/                         | grep -i x-fastcgi-cache
```

Then edit a post and confirm its URL, the home page and its category archive all
go back to `MISS`.

A `412` from a purge means *"that key was not in the cache"* — normal for a page
nobody has requested yet, and the plugin treats it as success.

---

## What gets purged, and when

| Event | Purged |
|---|---|
| Admin-bar **Purge Nginx Cache** | Everything, in one wildcard request |
| Post/page published, updated, unpublished or trashed | Its permalink, the home page, its public taxonomy archives |
| Product saved (WooCommerce) | The above, plus the shop page |
| Post permanently deleted | Same set as above |
| Term created or edited (any public taxonomy) | Its archive and the home page |
| Term deleted | Its archive and the home page |
| Comment posted (visible), edited, approved, unapproved, spammed or trashed | The commented post's permalink, the home page and its archives |
| Theme switched | Everything |
| Nav menu, widgets or Customizer saved | Everything |

Post types that are not publicly viewable — menu items, revisions, WooCommerce
orders, most custom internal types — are skipped, since they were never cached.

---

## Filters

**`ncp_purge_endpoint`** — scheme and host the purge is sent to. Defaults to
`home_url()`. Use this when the site sits behind a CDN or proxy and the purge
must reach the origin directly:

```php
add_filter( 'ncp_purge_endpoint', function () {
    return 'https://origin.example.com';
} );
```

**`ncp_purge_sslverify`** — certificate verification on the purge request,
default `true`. Only disable it if the endpoint is reached over a hostname or IP
the certificate does not cover:

```php
add_filter( 'ncp_purge_sslverify', '__return_false' );
```

**`ncp_paths_for_post`** — the list of paths purged when a post changes:

```php
add_filter( 'ncp_paths_for_post', function ( $paths, $post ) {
    $paths[] = '/blog/';
    return $paths;
}, 10, 2 );
```

---

## Purging behind a proxy or Cloudflare

By default the plugin sends the purge to your site's own address (`home_url()`).
When nginx serves the site directly — Cloudflare **grey-clouded** ("DNS only"),
or no CDN at all — that request loops straight back to nginx from the server's
own IP and everything works. Nothing to configure.

It breaks when the site is **orange-clouded** (Cloudflare "Proxied") or sits
behind any other reverse proxy. In the Cloudflare DNS panel the cloud icon next
to a record is the toggle: **orange = Proxied** (traffic runs through Cloudflare),
**grey = DNS only** (traffic goes straight to your origin). Only the orange case
is a problem.

With the site orange-clouded, `home_url()` no longer resolves to your server —
it resolves to Cloudflare. So the purge request leaves your box, crosses the
internet to Cloudflare, and only then comes back to your origin. Along the way:

- Cloudflare rewrites the source address, so the IP that finally reaches your
  `/purge` location may not be in its `allow` list → **403, purge silently
  fails**.
- Cloudflare's WAF or "Under Attack" mode can block the request before it ever
  reaches the origin.
- Even when it works, it is a pointless round-trip to Cloudflare to purge a
  cache that lives on the same machine.

### The fix: purge over localhost

Point the purge at `127.0.0.1` instead of the public hostname. The request never
leaves the server, never touches Cloudflare, and arrives from `127.0.0.1`, which
your `allow` list already trusts:

```php
// Send purges to nginx directly, bypassing the proxy in front of the site.
add_filter( 'ncp_purge_endpoint', function () {
    return 'http://127.0.0.1';
} );
add_filter( 'ncp_purge_sslverify', '__return_false' );
```

Two details make this reliable — miss either and the purge quietly does nothing:

1. **Use `http://`, not `https://`.** Over loopback plain HTTP is safe (it never
   leaves the machine), and it avoids a certificate-name mismatch: nginx would
   present the site's certificate, which is issued for the domain, not for
   `127.0.0.1`. (`ncp_purge_sslverify` → false covers the case where you must use
   https anyway.)

2. **Hardcode `https` in the purge location's key.** Your cache key is
   `"$scheme$host$request_uri"`, and real visitors store pages under `https…`.
   A purge arriving over `http://127.0.0.1` would otherwise build an `http…` key
   that never matches the stored `https…` entry. Pin the scheme so a localhost
   purge still hits the cached pages:

   ```nginx
   location ~ ^/purge(/.*) {
       allow 127.0.0.1;
       allow ::1;
       deny  all;

       cache_purge_response_type json;
       fastcgi_cache_purge wpcache "https$host$1";   # https, not $scheme
   }
   ```

   With the endpoint set to localhost you can drop the server's public IP from
   the `allow` list entirely — only loopback is needed.

If the proxy is not Cloudflare but a separate front-end (HAProxy, a TLS-
terminating nginx), the same fix applies: purge the origin directly over
`127.0.0.1` rather than the public hostname the proxy answers.

---

## Troubleshooting

**Nothing is ever cached (always `MISS`).** Check `X-Cache-Skip` /
`$wp_skip_cache`. Usual causes: a plugin setting a cookie on every response, a
missing `fastcgi_ignore_headers Cache-Control Expires`, or a query string on the
URL you are testing.

**Purges return `412` for pages that are definitely cached.** The key expression
in the `/purge` location does not match `fastcgi_cache_key`. Compare them
character by character. You can read the stored key straight out of a cache
file:

```bash
grep -a -m1 -o "KEY: [^\r]*" /var/cache/nginx/wpcache/*/*/* | head
```

**Purges return `403`.** The request is not coming from an address in the
`allow` list. The plugin calls the site's public hostname, so the source is
usually the server's own public IP. Behind Cloudflare (orange-clouded) or another
proxy the source becomes unpredictable — see
[Purging behind a proxy or Cloudflare](#purging-behind-a-proxy-or-cloudflare) for
the localhost fix.

**Purge answers `200` but pages stay stale.** A global `open_file_cache` is
holding descriptors of the deleted cache files — set `open_file_cache off;` in
the cached location.

**The whole site goes stale after one edit, or edits show up nowhere.** Almost
always `$uri` instead of `$request_uri` in the cache key.

**Nothing happens at all.** Enable `WP_DEBUG` and `WP_DEBUG_LOG`; every attempt
is written to `wp-content/debug.log` prefixed with `NCP:`, including the URL and
the HTTP status.

---

## Credits

Originally based on [olvy-cache-purger](https://github.com/olvycloud/olvy-cache-purger)
by Olvy Cloud, released under GPL-2.0+. This fork rewrites the purge logic,
fixes several correctness and security issues, and adds documentation. Thanks to
the original authors for the starting point.

---

## Changelog

### 1.1.0

* **Optional background cache warmer.** When enabled, purged URLs are re-fetched
  on a cron tick so the next visitor gets a HIT instead of paying for the MISS. A
  full purge warms a bounded set — the home page plus recent posts, capped by a
  setting — never the whole sitemap at once. Off by default.
* **Settings page** (Settings → Nginx Cache Purger): warmer toggle, purge
  endpoint / SSL-verify overrides, a WP-Cron panel (detect + optionally add
  `DISABLE_WP_CRON` to `wp-config.php`, plus the system-cron line and a last-run
  canary), and a one-click cache self-test using the `X-FastCGI-Cache` header.
* Endpoint and SSL-verify are now settable from the page as well as the filters;
  a code filter still wins.
* Everything is optional — with nothing configured the plugin behaves exactly as
  in 1.0.x.

### 1.0.2

* New purge triggers: a new or edited comment — and approve / unapprove / spam /
  trash of an existing one — now purges the post it belongs to.
* Theme switch, nav-menu edits, widget changes and Customizer saves purge the
  whole cache, since they restyle or re-populate every page.
* Several site-wide triggers firing in a single request collapse into one
  wildcard purge instead of many.

### 1.0.1

* **Security:** the purge URL is built from `home_url()` instead of the
  client-supplied `Host` header, which could be spoofed to make the site issue
  an outbound request to an attacker-chosen host.
* **Security:** SSL verification on the purge request is on by default, with the
  `ncp_purge_sslverify` filter for setups that need it off.
* Purging a post now also clears the home page, its taxonomy archives and — for
  products — the shop page, instead of only its own permalink.
* Unpublishing, trashing and permanently deleting a post now purge; previously
  only transitions *to* `publish` did, so content pulled offline stayed cached.
* Deleting a term now purges. `delete_term` fires after the term is gone, so the
  old handler's `get_term()` returned null and it always bailed out.
* Term purging covers every public taxonomy, not just `product_cat`.
* Purges are skipped for post types that are not publicly viewable, instead of
  firing an HTTP request for every menu item and WooCommerce order.
* A `412` response (key not in cache) is treated as success rather than an error.
* The admin-bar button now reports through a WordPress notice instead of a
  JavaScript `alert()`, with a working spinner.
* The front-end admin-bar button works; its script was only ever loaded in
  wp-admin.
* Added the `ncp_purge_endpoint`, `ncp_purge_sslverify` and `ncp_paths_for_post`
  filters.

### 1.0.0

* Initial release: admin-bar purge button, automatic purging on post save, and
  WooCommerce product/category purging.
