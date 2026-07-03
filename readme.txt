=== Analyse ===
Contributors: analyse
Tags: analytics, blog, tracking, seo, content
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Analyse: privacy-friendly analytics, blog post sync, and auto-publishing of Analyse posts to your site.

== Description ==

The official Analyse plugin connects your WordPress site to [Analyse](https://analyse.net):

* **Analytics tracking** — adds the lightweight Analyse snippet to your site. Pageviews, sessions and conversions show up in your Analyse dashboard. Options to skip logged-in users and respect Do Not Track / Global Privacy Control.
* **Sync posts to Analyse** — published WordPress posts are sent to Analyse so your content analytics and AI assistant know about everything you publish, not just posts written in Analyse.
* **Publish from Analyse** — posts written or generated in Analyse are created on your WordPress site automatically, including categories and featured image. Deliveries are verified with an HMAC signature.

= Setup =

1. Install and activate the plugin.
2. Go to **Settings → Analyse**.
3. Paste your site **public key** (from Analyse → Site settings → Tracking).
4. In Analyse, open **Integrations → WordPress**, generate a **signing secret**, and paste it into the plugin settings.
5. Copy the **webhook URL** shown by the plugin into the Analyse WordPress integration.

= Self-hosting the tracking script =

The snippet loads the open-source Analyse SDK from a CDN. If you prefer to
self-host, download `index.global.js` from the [SDK repository](https://github.com/track/sdk),
serve it from your own domain, and filter the script source with the
`analyse_snippet_src` filter (or keep the CDN default).

== Frequently Asked Questions ==

= Does tracking collect personal data? =

The Analyse SDK is cookieless by default and does not collect personal data unless you explicitly identify users. The plugin can additionally honor DNT/GPC headers.

= Will posts published from Analyse be synced back to Analyse? =

No. Posts created by Analyse are tagged and excluded from sync, so nothing loops.

== Changelog ==

= 0.1.0 =
* Initial release: analytics snippet, post sync to Analyse, publish receiver.
