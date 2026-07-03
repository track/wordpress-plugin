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

= The tracking script =

The analytics script is the open-source [Analyse SDK](https://github.com/track/sdk),
bundled with this plugin and served from your own site — no third-party CDN is
loaded. Developers can override the script source with the `analyse_snippet_src`
filter.

== External Services ==

This plugin connects your site to the Analyse platform. It communicates with
the following external services, only in the situations described:

**Analyse analytics ingest (pulse.analyse.net)**

When analytics tracking is enabled and a public key is configured, the bundled
script sends visitor analytics events from your site's front-end to
`https://pulse.analyse.net` (or the host you configure). This includes the
page URL and referrer, anonymous visitor and session identifiers, browser,
operating system, device type, screen size, and UTM parameters. The tracking
is cookieless and no personal data is collected unless you explicitly identify
users via the SDK. The "Send test event" button in the plugin settings sends
one test event to the same service. Requests stop as soon as tracking is
disabled or the public key is removed.

**Analyse content sync (analyse.net)**

Only when you enable the optional "Sync posts to Analyse" setting, publishing,
updating, trashing, or deleting a post sends that post's public content —
title, rendered HTML, excerpt, categories, tags, author display name,
permalink, and publish dates — to `https://analyse.net` so it appears in your
Analyse content analytics. Drafts, revisions, and private content are never
sent. This feature is disabled by default.

**Publishing from Analyse (inbound)**

When "Accept publishes" is enabled, the Analyse platform can create posts on
your site via this plugin's REST endpoint. These requests are verified with an
HMAC signature using your signing secret; featured images are downloaded from
Analyse's storage URLs.

These services are operated by Analyse. See the
[Terms of Service](https://analyse.net/terms) and
[Privacy Policy](https://analyse.net/privacy) for details on data handling.

== Frequently Asked Questions ==

= Does tracking collect personal data? =

The Analyse SDK is cookieless by default and does not collect personal data unless you explicitly identify users. The plugin can additionally honor DNT/GPC headers.

= Will posts published from Analyse be synced back to Analyse? =

No. Posts created by Analyse are tagged and excluded from sync, so nothing loops.

== Changelog ==

= 0.1.0 =
* Initial release: analytics snippet, post sync to Analyse, publish receiver.
