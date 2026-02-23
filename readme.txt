=== TMW Fastest Rocket ===
Contributors: tmw
Tags: performance, cache, preload, optimization
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A project-specific performance framework for RetroTube Child v3 sites.

== Description ==

TMW Fastest Rocket is a modular performance plugin built for the "tmw-Fastest-Rocket" project.

Modules (all OFF by default):
* HTML: safe trims (emoji, embeds, dashicons)
* Media: image attribute tuning (promote first images, sizes fix inside [tmw_home_categories])
* Preload: preload first thumb on category archives, optional preconnect
* JavaScript: defer/delay controls (conservative when theme already delays scripts)
* Cache: file-based page cache with optional advanced-cache.php drop-in

== Installation ==

1. Upload the plugin ZIP in WordPress > Plugins > Add New > Upload Plugin
2. Activate
3. Go to "TMW Fastest Rocket" in wp-admin
4. Enable modules gradually

== Notes ==

* The Cache module has the highest impact, but do not run multiple page caches at once.
* Drop-in mode requires WP_CACHE to be true in wp-config.php.
