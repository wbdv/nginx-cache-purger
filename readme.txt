=== Nginx Cache Purger ===
Contributors: wbdv
Tags: nginx, cache, purge, fastcgi, woocommerce
Requires at least: 6.0
Tested up to: 7.0.2
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Purges the Nginx FastCGI cache when content changes — automatically on publish, update and delete, or site-wide from the admin bar.

== Description ==

Nginx Cache Purger keeps an Nginx FastCGI page cache in step with WordPress. It
adds a "Purge Nginx Cache" button to the admin bar for clearing the whole site,
and purges the affected pages by itself whenever content changes.

There is no settings page, no database option and no cron job. The plugin sends
an HTTP request to a `/purge` location that Nginx handles; the caching policy
itself lives entirely in your Nginx configuration.

**What it purges**

* Admin-bar button — the entire cache, in a single wildcard request.
* A post or page published, updated, unpublished or trashed — its permalink, the
  home page and its public taxonomy archives.
* A WooCommerce product — the above, plus the shop page.
* A term created, edited or deleted in any public taxonomy — its archive and the
  home page.

Post types with no front-end URL (menu items, revisions, WooCommerce orders and
similar) are skipped, because they were never cached in the first place.

**Requirements**

This plugin does not cache anything itself — Nginx does. It requires Nginx built
with the ngx_cache_purge module from https://github.com/nginx-modules/ngx_cache_purge
— the actively maintained continuation of Piotr Sikora's original module, which
passed through torden's fork around 2017. If you have seen it called "the Torden
fork", that is the same lineage.

The abandoned FRiCKLE repository will not work: the admin-bar button clears the
site with a single wildcard request, and wildcard purging landed after FRiCKLE
development stopped around 2015.

Check yours with:

    nginx -V 2>&1 | tr ' ' '\n' | grep cache_purge

Full Nginx configuration, including a tested FastCGI cache setup with
WooCommerce-safe bypass rules, is in README.md in the plugin folder.

== Installation ==

= 1. Nginx =

You need a working FastCGI cache and a purge location. Minimal version — see
README.md for the complete configuration:

In the `http` context (the top of your site's file in `conf.d/` is fine):

    fastcgi_cache_path /var/cache/nginx/wpcache levels=1:2 keys_zone=wpcache:100m
                       inactive=12h max_size=512m;

In `server { }`:

    location ~ ^/purge(/.*) {
        allow 127.0.0.1;
        allow ::1;
        allow 203.0.113.10;   # this server's own public IP
        deny  all;

        cache_purge_response_type json;
        fastcgi_cache_purge wpcache "$scheme$host$1";
    }

In the PHP location:

    open_file_cache          off;
    fastcgi_cache            wpcache;
    fastcgi_cache_key        "$scheme$host$request_uri";
    fastcgi_cache_methods    GET;
    fastcgi_cache_valid      200 301 302 12h;
    fastcgi_ignore_headers   Cache-Control Expires;

Three things that catch people out:

* Use `$request_uri`, not `$uri`, in the cache key. WordPress routes everything
  through `try_files ... /index.php`, so `$uri` becomes `/index.php` and the
  whole site collapses onto one cache entry.
* Leave `$request_method` out of the key, and set `fastcgi_cache_methods GET`.
  Otherwise GET and HEAD are stored separately and the HEAD copies can never be
  purged.
* If you set a global `open_file_cache`, turn it off in the cached location.
  Nginx caches the descriptors of cache files too, so purges appear to do
  nothing for up to `open_file_cache_valid` seconds.

The key expression in the purge location must match `fastcgi_cache_key` exactly,
with `$1` in place of the path. If they differ, every purge returns 412.

Then:

    nginx -t && systemctl reload nginx

= 2. The plugin =

1. Upload the `nginx-cache-purger` folder to `wp-content/plugins/`, or install
   the zip through Plugins > Add New > Upload Plugin.
2. Activate it through the Plugins menu.

There is nothing to configure. The purge URL is derived from your site address.

= 3. Check it =

    curl -sI https://example.com/ | grep -i x-fastcgi-cache   # MISS
    curl -sI https://example.com/ | grep -i x-fastcgi-cache   # HIT
    curl -s  https://example.com/purge/                       # {"Status": "purged"}
    curl -s  "https://example.com/purge/*"                    # 200 = correct module

Add `add_header X-FastCGI-Cache $upstream_cache_status always;` while testing.

== Frequently Asked Questions ==

= Does this plugin cache my pages? =

No. Nginx does the caching; this plugin only tells it what to throw away.

= Why doesn't it work with the FRiCKLE ngx_cache_purge module? =

That module purges one exact key per request and has no wildcard support, so the
"purge everything" button cannot work. It has also been unmaintained since 2015
and no longer builds against current Nginx. Use
https://github.com/nginx-modules/ngx_cache_purge instead.

= Purges return 403. =

The request is not reaching Nginx from an address in your `allow` list. The
plugin calls the site's own public hostname, so the source address is normally
the server's public IP — not 127.0.0.1. Behind a proxy that sets
`real_ip_header`, `$remote_addr` becomes the visitor's address instead; in that
case point the plugin at your origin with the `ncp_purge_endpoint` filter.

= Purges return 412. =

412 means "this key was not in the cache". For a page nobody has requested yet
that is normal and the plugin treats it as success. If it happens for pages that
are definitely cached, your purge-location key expression does not match
`fastcgi_cache_key`.

= Nothing happens when content changes. =

Enable `WP_DEBUG` and `WP_DEBUG_LOG`. Every purge attempt is logged to
`wp-content/debug.log` with an `NCP:` prefix, including the URL and HTTP status.

= Can I change where purge requests are sent? =

Yes, with the `ncp_purge_endpoint` filter. `ncp_purge_sslverify` controls
certificate verification, and `ncp_paths_for_post` controls which paths are
purged when a post changes. See README.md.

= Does it work with WooCommerce? =

Yes. Product and product-category purging switch on automatically. Make sure
your Nginx bypass rules exclude cart, checkout, my-account and the WooCommerce
session cookies — the configuration in README.md does.

== Changelog ==

= 1.0.1 =
* Security: the purge URL is built from the site address instead of the
  client-supplied Host header, which could be spoofed into making the site issue
  an outbound request to an attacker-chosen host.
* Security: SSL verification on the purge request is enabled by default, with
  the `ncp_purge_sslverify` filter for setups that need it off.
* Purging a post now also clears the home page, its taxonomy archives and, for
  products, the shop page — previously only its own permalink.
* Unpublishing, trashing and permanently deleting a post now purge. Previously
  only transitions to `publish` did, so content pulled offline stayed cached.
* Deleting a term now purges; the old handler always bailed out because
  `delete_term` fires after the term is already gone.
* Term purging covers every public taxonomy, not only `product_cat`.
* Post types that are not publicly viewable no longer trigger purge requests.
* A 412 response (key not in cache) counts as success instead of an error.
* The admin-bar button reports through a WordPress notice instead of a
  JavaScript alert, with a working spinner.
* Fixed the front-end admin-bar button, whose script only loaded in wp-admin.
* Added the `ncp_purge_endpoint`, `ncp_purge_sslverify` and `ncp_paths_for_post`
  filters.

= 1.0.0 =
* Initial release: admin-bar purge button, automatic purging on post save, and
  WooCommerce product and product-category purging.

== Upgrade Notice ==

= 1.0.1 =
Fixes a spoofable Host header used to build the outbound purge URL, enables SSL
verification, and purges the home page and archives — not just the edited post.
