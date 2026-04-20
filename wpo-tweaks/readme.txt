=== Zero Config Performance Optimization ===
Contributors: fernandot, ayudawp
Tags: performance, optimization, speed, pagespeed, core-web-vitals
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 2.3.1
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced performance optimizations for WordPress. Improves speed, reduces server resources and optimizes PageSpeed.

== Description ==

Zero Config Performance Optimization is the most complete performance optimization plugin for WordPress. It combines the best WPO (Web Performance Optimization) practices in a single easy-to-use tool. No configuration needed: activate and enjoy a faster WordPress.

By default, WordPress loads several functions, services and scripts that are not mandatory and usually slow down your installation and consume hosting resources. For years I have been testing tweaks to save hosting resources and improve WordPress performance and loading times. After thousands of tests, this plugin includes my best speed and performance optimizations with a single click.

With this plugin you can safely disable those annoying services, unnecessary codes and scripts to save resources and hosting costs, and speed up WordPress to get better results in tools like Google PageSpeed, Pingdom Tools, GTMetrix, WebPageTest and others.

### NEW IN V2.3.0

**Core Compatibility Refactoring:**
* **Image module rebuilt** - Now works as a safety net that complements WordPress core. Only adds lazy loading, decoding, and fetchpriority attributes when core hasn't already handled them (covers images from themes, page builders, widgets, and custom templates that bypass core's pipeline)
* **NEW: fetchpriority="low"** for non-critical images, which WordPress core does NOT add. Frees bandwidth for critical resources

**Bug Fixes:**
* Fixed Gravatar avatars broken by aggressive query string removal
* Fixed Critical CSS broken by incorrect HTML escaping of CSS selectors
* Removed deprecated JSON/REST API filters that could interfere with Gutenberg and WooCommerce
* Fixed compatibility with CusRev and other review plugins
* Automatic cleanup of ghost plugin entries from previous versions

**Other:**
* Tested up to WordPress 7.0

### INCLUDED OPTIMIZATIONS

**Frontend Optimizations:**
* Automatic Critical CSS generation and injection
* Deferred CSS Loading with noscript fallback
* Image lazy loading safety net (catches images that bypass WordPress core pipeline)
* fetchpriority="low" for non-critical images (complements core LCP detection)
* Automatic preconnect for Google Fonts, Analytics, etc.
* Smart DNS Prefetch for external resources including Gravatar
* Automatic image dimensions for better CLS scores
* Google Fonts display=swap optimization
* JavaScript defer parsing
* Logo preload with high priority

**Server Optimizations:**
* Browser cache rules with immutable flag
* GZIP and Brotli compression
* Keep-Alive connections
* Vary Accept-Encoding headers
* CORS headers for fonts (CDN compatibility)
* Extended MIME type coverage

**Backend Optimizations:**
* Database transients cleanup
* Query optimizations
* Heartbeat API control (60s interval)
* Post revisions limited to 3
* jQuery Migrate removal when not needed
* Self-pingback prevention
* Dashboard widgets cleanup

### HOW TO VERIFY OPTIMIZATIONS ARE WORKING

You can check each optimization individually to ensure Zero Config Performance Optimization is working correctly:

**Logo Preload:** View page source (Ctrl+U) and look for `<link rel="preload" ... fetchpriority="high">` pointing to your logo image.

**fetchpriority:** Inspect images below the fold (F12 > Elements). Non-critical images should have `fetchpriority="low"` (added by ZCP). The first content image should have `fetchpriority="high"` (added by WordPress core or ZCP as fallback).

**Brotli/GZIP Compression:** Test at [giftofspeed.com/gzip-test](https://www.giftofspeed.com/gzip-test/) - should show compression enabled.

**Cache Headers:** Check your `.htaccess` file for a section marked "BEGIN Zero Config Performance" with `immutable` in Cache-Control headers.

**Critical CSS:** View page source and look for `<style id="ayudawp-wpotweaks-critical-css">` in the head section.

**Deferred CSS:** In source code, look for `<link>` tags with `rel="preload" as="style"` instead of `rel="stylesheet"`.

**Keep-Alive:** Use browser dev tools (F12 > Network) and check response headers for `Connection: keep-alive`.

Use tools like Google PageSpeed, GTMetrix, Pingdom Tools, and WebPageTest to measure overall performance improvements. Always test twice to account for caching effects.

### COMPATIBILITY AND EXTENSIBILITY

The plugin includes multiple filters for developers:

* `ayudawp_wpotweaks_critical_css` - Customize critical CSS
* `ayudawp_wpotweaks_critical_css_handles` - Customize which CSS handles are considered critical
* `ayudawp_wpotweaks_preconnect_hints` - Add custom preconnect
* `ayudawp_wpotweaks_dns_prefetch_domains` - Customize DNS prefetch domains
* `ayudawp_wpotweaks_critical_fonts` - Define critical fonts for preload

**Compatible with:**

* All well-coded themes
* Cache plugins (W3 Total Cache, WP Rocket, LiteSpeed Cache, etc.)
* Security plugins (no conflicts, focused only on performance)
* WordPress Multisite
* Page Builders (Divi, Elementor, Beaver Builder, Gutenberg)
* CDNs (Cloudflare, StackPath, KeyCDN, etc.)

### INSTALLATION AND USE

**No options**. Just activate the plugin and test your site speed in your favorite tool (GTMetrix, Pingdom Tools, Google PageSpeed, etc.)

The plugin is completely automatic and applies optimizations safely without breaking functionality.

### MEASURING RESULTS

**Recommended tools:**

* [Google PageSpeed Insights](https://pagespeed.web.dev/)
* [GTMetrix](https://gtmetrix.com/)
* [WebPageTest](https://www.webpagetest.org/)

**Best measurement practices:**

* Run at least 2 tests (first one may not show cache)
* Always use the same tool for comparison
* Measure performance over time, not just once
* Remember that no tool can replace human perception

== Installation ==

1. Go to your WP Dashboard > Plugins and search for 'zero config performance' or...
2. Download the plugin from WP repository
3. Upload the 'wpo-tweaks' folder to '/wp-content/plugins/' directory
4. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= What does Zero Config mean? =

Zero Config means you don't need to configure anything. Just activate the plugin and all optimizations are applied automatically. No settings page, no options to tweak, no learning curve.

= What does WPO mean? =

WPO stands for Web Performance Optimization. It measures a set of various improvements in optimization and improvement of performance and loading times of web pages.

= Where can I test my site performance? =

* Go to [Google PageSpeed](https://pagespeed.web.dev/) and test your site
* Go to [GTMetrix](https://gtmetrix.com/) and test your site
* Go to [WebPageTest](https://www.webpagetest.org/) and test your site

= What is the best way to test my site performance? =

Use one of the tools above and run at least two tests to measure your site performance. This is because cache systems don't load the first time your site is tested with these tools. Always test your site with the same tool and measure your site performance over time, not just once.

And always remember that no tool can replace human perception. If you see that your web loads faster than ever, no tool is going to tell you what you and your visitors feel in real life.

Don't go crazy with tools, they are machines and, for example, Google PageSpeed can show you a measure of 100/100 when your site is broken, and that's far from being an optimized web, right?

= How can I verify that the optimizations are working? =

Please check the "HOW TO VERIFY OPTIMIZATIONS ARE WORKING" section in the Description for detailed instructions on how to verify each optimization individually.

= Something went wrong after activation =

This plugin is compatible with all WordPress JavaScript functions (`wp_localize_script()`, js in header, in footer...) and works with all well-coded plugins and themes. If a plugin or theme is not enqueuing scripts correctly, your site may not work. If your hosting doesn't support some of the tweaks, usually due to server restrictions, something may fail.

If something fails, please access your `/wp-content/plugins/wpo-tweaks/` directory via your favorite FTP client or hosting panel (cPanel, Plesk, etc.) and rename the plugin folder to deactivate it.

If you get a 500 Error (server error), then go to your hosting panel and edit the .htaccess file to remove the lines added by the plugin (they start with 'Zero Config Performance') and save changes, or delete the file and create it again from Dashboard > Settings > Permalinks > Save changes.

= What's next? =

I will be including in next updates every new performance tweak I test for better results in order to speed up WordPress.

= Do you plan to include a settings panel? =

No. Zero Config Performance Optimization plugin is intended for users who want to get optimizations and speed safely with one click. If you are a developer and know what you are doing, then please check out [Machete plugin by my friend Nilo Velez](https://wordpress.org/plugins/machete/), a complete suite to decide how to solve common WordPress problems and annoyances. And yes, it has a huge settings page!

= Can I customize the optimizations? =

Yes, the plugin includes multiple WordPress filters for developers that allow customizing plugin behavior according to specific site needs.

= Is it compatible with caching plugins? =

Yes! Zero Config Performance Optimization plugin is designed to work alongside caching plugins. We recommend using it with Cache Enabler, WP Super Cache, W3 Total Cache, LiteSpeed Cache, or WP Rocket for maximum performance.

= Is it compatible with security plugins? =

Yes! This plugin focuses exclusively on performance optimizations and does not include any security features, so it won't conflict with your security plugins.

= Does it work with CDNs? =

Yes. The plugin includes CORS headers for fonts and proper Vary headers that ensure full compatibility with CDNs like Cloudflare, StackPath, KeyCDN, and others.

== Screenshots ==

1. Pingdom Tools results before plugin activation
2. Pingdom Tools results after plugin activation

== Changelog ==

= 2.3.1 =
* **FIX: JavaScript defer breaking scripts with inline "after" code** - Fixed "wp is not defined" errors on scripts using `wp_add_inline_script()` with 'after' position (wp-i18n) and scripts registered with `wp_set_script_translations()` (Contact Form 7 translations, etc.). The defer filter now skips scripts with attached inline code to prevent execution order issues
* **IMPROVED: Changelog history moved to separate file** - Full changelog history now lives in [changelog.txt](https://plugins.svn.wordpress.org/wpo-tweaks/trunk/changelog.txt) following WordPress.org best practices. The readme.txt now only contains the latest versions for lighter maintenance and cleaner translations

= 2.3.0 =
* **MAJOR: Image module refactored to complement WordPress core** - Rebuilt to work as a safety net instead of duplicating core. WordPress 5.5+/6.1+/6.3+ handles lazy loading, decoding, and fetchpriority for images in its standard pipeline, but images injected by themes, page builders, widgets or custom code may bypass it. The module now checks if each attribute is already present before adding it, so it only fills the gaps core didn't reach
* **NEW: fetchpriority="low" for non-critical images** - Deprioritizes below-the-fold images so the browser allocates bandwidth to critical resources first. WordPress core does NOT do this
* **FIX: Gravatar avatars broken by query string removal** - Previous version stripped all query parameters from Gravatar URLs, including functional ones (size, default image, rating). Function removed entirely since Gravatar params are not cache-busting
* **FIX: Critical CSS broken by esc_html()** - CSS selectors using `>` (child combinator) and other special characters were escaped to HTML entities, breaking the CSS. Now uses wp_strip_all_tags() only
* **FIX: Deprecated JSON/REST API filters removed** - `json_enabled` and `json_jsonp_enabled` were legacy filters from the old WP-API plugin (pre WP 4.7) that could interfere with Gutenberg, WooCommerce and other plugins depending on the REST API
* **FIX: CusRev and review plugins compatibility** - Comments query optimization now only applies to standard comments, not custom comment types used by review plugins (CusRev, WooCommerce Reviews, etc.)
* **FIX: Ghost plugin entries cleanup** - Automatically removes orphaned entries in active_plugins from renamed or incorrectly registered plugin paths on activation
* IMPROVED: Removed dns-prefetch hint for s.w.org (only used by emoji scripts already disabled by the plugin)
* IMPROVED: Reduced code footprint in image optimization module, cleaner logic with no duplication
* Tested up to WordPress 7.0

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/wpo-tweaks/trunk/changelog.txt) file.

== Upgrade Notice ==

= 2.3.1 =
FIX: Resolves "wp is not defined" errors caused by deferring scripts with inline translations code (wp-i18n, Contact Form 7, etc.). Recommended update for all users.

== Support ==

Need help or have suggestions?

* [Official website](https://servicios.ayudawp.com/)
* [WordPress support forum](https://wordpress.org/support/plugin/wpo-tweaks/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com/)

Love the plugin? Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.