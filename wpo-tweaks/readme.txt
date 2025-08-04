=== WPO Tweaks & Performance Optimizations ===
Contributors: fernandot, ayudawp
Tags: performance, optimization, speed, cache, lazy-loading
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 2.0.1
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced performance optimizations for WordPress. Improves speed, reduces server resources and optimizes PageSpeed.

== Description ==

**New version 2.0.x with advanced optimizations!**

WPO Tweaks is the most complete performance optimization plugin for WordPress. It combines the best WPO (Web Performance Optimization) practices in a single easy-to-use tool. No configuration needed: activate and enjoy a faster WordPress.

By default, WordPress loads several functions, services and scripts that are not mandatory and usually slow down your installation and consume hosting resources. For years I have been testing tweaks to save hosting resources and improve WordPress performance and loading times. After thousands of tests, this plugin includes my best speed and performance optimizations with a single click.

With this plugin you can safely disable those annoying services, unnecessary codes and scripts to save resources and hosting costs, and speed up WordPress to get better results in tools like Google PageSpeed, Pingdom Tools, GTMetrix, WebPageTest and others.

### ✨ **NEW FEATURES V2.0.x**

**🚀 Advanced CSS Optimizations:**
* **Automatic Critical CSS**: Above-the-fold critical CSS generation and injection
* **Deferred CSS Loading**: Non-critical CSS loads asynchronously
* **Google Fonts Optimization**: Automatically adds display=swap

**⚡ Performance Optimizations:**
* **Preconnect and DNS Prefetch**: Automatic hints for critical external resources
* **Native Lazy Loading**: Lazy loading for all images with decoding=async
* **Resource Preloading**: Automatic preload for theme CSS and critical fonts

**🗄️ Database Optimizations:**
* **Automatic Transients Cleanup**: Removes expired transients automatically
* **Query Optimization**: Improves comments and files queries
* **Smart Cache**: Enhanced cache system for better performance

**🔒 Security Improvements:**
* **Security Headers**: X-Pingback and other revealing headers removal
* **Version Hiding**: Removes WordPress version information
* **XML-RPC Management**: Smart deactivation based on active plugins

**⚙️ Administration Optimizations:**
* **Dashboard Cleanup**: Removes unnecessary dashboard widgets
* **Revisions Control**: Limits automatic revisions (maximum 3)
* **Trash Management**: Reduces retention time to 7 days

### 📋 **INCLUDED OPTIMIZATIONS**

**Classic Optimizations (since v1.0):**
* ✅ Browser cache rules in .htaccess
* ✅ GZIP compression in .htaccess  
* ✅ Remove Dashicons in admin bar (non-logged users only)
* ✅ Remove Emojis styles and scripts
* ✅ Disable REST API (completely disabled)
* ✅ Control Heartbeat API interval (60s instead of 15s)
* ✅ Remove Query Strings from static resources
* ✅ Defer JavaScript parsing
* ✅ Remove Query Strings from Gravatar
* ✅ Remove Really Simple Discovery link from header
* ✅ Remove wlwmanifest.xml (Windows Live Writer) from header
* ✅ Remove URL Shortlink from header
* ✅ Remove WordPress Generator version from header
* ✅ Remove DNS Prefetch from s.w.org
* ✅ Remove unnecessary links from header
* ✅ Remove RSS feeds generator name
* ✅ Remove Capital P Dangit filter
* ✅ Disable PDF thumbnails previews
* ✅ Disable internal Self Pingbacks

**New Optimizations v2.0:**
* 🆕 **Automatic Critical CSS** with smart cache
* 🆕 **Deferred CSS Loading** for non-critical styles
* 🆕 **Automatic preconnect** for Google Fonts, Analytics, etc.
* 🆕 **Smart DNS Prefetch** for external resources
* 🆕 **Native Lazy Loading** with decoding=async
* 🆕 **Automatic transients cleanup** for expired entries
* 🆕 **Database query optimization**
* 🆕 **jQuery Migrate removal** when not needed
* 🆕 **Critical resources preloading** (theme CSS, fonts)
* 🆕 **Enhanced security headers**
* 🆕 **Administrative dashboard cleanup**
* 🆕 **Smart revisions and trash management**

### 🎯 **COMPATIBILITY AND EXTENSIBILITY**

The plugin includes multiple filters for developers:
* `ayudawp_critical_css` - Customize critical CSS
* `ayudawp_preconnect_hints` - Add custom preconnect
* `ayudawp_dns_prefetch_domains` - Customize DNS prefetch domains
* `ayudawp_critical_fonts` - Define critical fonts for preload
* `ayudawp_keep_xmlrpc` - Keep XML-RPC if needed
* `ayudawp_keep_feeds` - Control feeds removal

**Compatible with:**
* Jetpack (keeps XML-RPC automatically)
* All well-coded themes
* Cache plugins (W3 Total Cache, WP Rocket, etc.)
* WordPress Multisite
* Builders (Elementor, Divi, Gutenberg)

### 🔧 **INSTALLATION AND USE**

**No options**. Just activate the plugin and test your site speed in your favorite tool (GTMetrix, Pingdom Tools, Google PageSpeed, etc.)

The plugin is completely automatic and applies optimizations safely without breaking functionality.

### 📊 **MEASURING RESULTS**

**Recommended tools:**
* [Google PageSpeed Insights](https://pagespeed.web.dev/)
* [GTMetrix](https://gtmetrix.com/)
* [Pingdom Tools](https://tools.pingdom.com/)
* [WebPageTest](https://www.webpagetest.org/)

**Best measurement practices:**
* Run at least 2 tests (first one may not show cache)
* Always use the same tool for comparison
* Measure performance over time, not just once
* Remember that no tool can replace human perception

== Installation ==

1. Go to your WP Dashboard > Plugins and search for 'wpo tweaks' or…
2. Download the plugin from WP repository
3. Upload the 'wpo-tweaks' folder to '/wp-content/plugins/' directory
4. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= What does WPO mean? =

WPO stands for Web Performance Optimization. It measures a set of various improvements in optimization and improvement of performance and loading times of web pages.

= Where can I test my site performance? =

* Go to [Google PageSpeed](https://pagespeed.web.dev/) and test your site
* Go to [Pingdom Tools](https://tools.pingdom.com/) and test your site  
* Go to [GTMetrix](https://gtmetrix.com/) and test your site
* Go to [WebPageTest](https://www.webpagetest.org/) and test your site

= What is the best way to test my site performance? =

Use one of the tools above and run at least two tests to measure your site performance. This is because cache systems don't load the first time your site is tested with these tools. Always test your site with the same tool and measure your site performance over time, not just once.

And always remember that no tool can replace human perception. If you see that your web loads faster than ever, no tool is going to tell you what you and your visitors feel in real life.

Don't go crazy with tools, they are machines and, for example, Google PageSpeed can show you a measure of 100/100 when your site is broken, and that's far from being an optimized web, right?

= How can I verify that the optimizations are working? =

You can check each optimization individually to ensure WPO Tweaks is working correctly:

**Critical CSS:** View page source (Ctrl+U) and look for `<style id="ayudawp-critical-css">` in the head section containing basic CSS rules.

**Deferred CSS:** In source code, look for `<link>` tags with `rel="preload" as="style"` instead of `rel="stylesheet"`, followed by `<noscript>` fallbacks.

**Google Fonts Optimization:** Google Fonts URLs should include `&display=swap` parameter.

**Preconnect:** Look for `<link rel="preconnect">` tags in the head pointing to fonts.googleapis.com, fonts.gstatic.com, etc.

**DNS Prefetch:** Check for `<link rel="dns-prefetch">` tags pointing to external domains like gravatar.com, stats.wp.com.

**Lazy Loading:** Inspect images (F12) - they should have `loading="lazy"` and `decoding="async"` attributes.

**Resource Preload:** Look for `<link rel="preload">` tags for your theme's CSS and critical fonts.

**Version Removal:** Source code should NOT contain `<meta name="generator" content="WordPress X.X">` or `?ver=` in script/style URLs.

**Dashicons Removal:** When logged out, source code should NOT include `dashicons.min.css`. When logged in, it should appear.

**Emojis Removal:** Source code should NOT contain `wp-emoji-release.min.js` or emoji-related styles.

**Header Cleanup:** Source code should NOT contain `<link rel="EditURI">`, `<link rel="wlwmanifest">`, or `<link rel="shortlink">`.

**JavaScript Defer:** Most `<script>` tags (except jQuery) should include the `defer` attribute.

**GZIP Compression:** Test at [giftofspeed.com/gzip-test](https://www.giftofspeed.com/gzip-test/) - should show "GZIP is enabled".

**Cache Headers:** Check your `.htaccess` file for a section marked "BEGIN WPO Tweaks by Fernando Tellado" with expiration rules.

**Heartbeat Control:** In WordPress Dashboard, open browser dev tools (F12) > Network tab. AJAX requests to `admin-ajax.php` with `action=heartbeat` should occur every 60 seconds instead of 15.

Use tools like Google PageSpeed, GTMetrix, Pingdom Tools, and WebPageTest to measure overall performance improvements. Always test twice to account for caching effects.

= Something went wrong after activation =

This plugin is compatible with all WordPress JavaScript functions (`wp_localize_script()`, js in header, in footer...) and works with all well-coded plugins and themes. If a plugin or theme is not enqueuing scripts correctly, your site may not work. If your hosting doesn't support some of the tweaks, usually due to security restrictions, something may fail. 

If something fails, please access your `/wp-content/plugins/wpo-tweaks/` directory via your favorite FTP client or hosting panel (cPanel, Plesk, etc.) and rename the plugin folder to deactivate it.

If you get a 500 Error (server error), then go to your hosting panel and edit the .htaccess file to remove the lines added by the plugin (they start with 'WPO Tweaks by Fernando Tellado') and save changes, or delete the file and create it again from Dashboard > Settings > Permalinks > Save changes.

= What's next? =

I will be including in next updates every new performance tweak I test for better results in order to speed up WordPress.

= Do you plan to include a settings panel? =

No. WPO Tweaks plugin is intended for users who want to get optimizations and speed safely with one click. If you are a developer and know what you are doing, then please check out [Machete plugin by my friend Nilo Velez](https://wordpress.org/plugins/machete/), a complete suite to decide how to solve common WordPress problems and annoyances. And yes, it has a huge settings page!

= Is plugin v2.0 compatible with previous version? =

Yes, completely. v2.0 includes all optimizations from v1.x plus new advanced features. There are no breaking changes.

= Can I customize the optimizations? =

Yes, v2.0 includes multiple WordPress filters for developers that allow customizing plugin behavior according to specific site needs.

== Screenshots ==

1. Pingdom Tools results before plugin activation
2. Pingdom Tools results after plugin activation
3. Google PageSpeed results showing Core Web Vitals improvements

== Changelog ==

= 2.0.1 =
* Fixed bug with WooCommerce not showing products in taxonomy and archive pages 

= 2.0.0 =
* **NEW MAJOR VERSION with advanced optimizations**
* ✨ **Automatic Critical CSS**: Above-the-fold critical CSS generation and injection
* ✨ **Deferred CSS Loading**: Non-critical CSS loads asynchronously with noscript fallback
* ✨ **Automatic Preconnect**: Automatic hints for Google Fonts, Analytics and other critical resources
* ✨ **Smart DNS Prefetch**: DNS preloading for common external resources
* ✨ **Native Lazy Loading**: Automatic lazy loading for all images with decoding=async
* ✨ **Database Optimizations**: Automatic cleanup of expired transients and query optimization
* ✨ **Resource Preloading**: Automatic preload for theme CSS and critical fonts
* ✨ **Smart jQuery Migrate Removal**: Only removes when not needed
* ✨ **Security Headers**: X-Pingback removal and sensitive information hiding
* ✨ **Dashboard Cleanup**: Removes unnecessary widgets from administration area
* ✨ **Revisions Management**: Limits automatic revisions to 3 and reduces trash retention to 7 days
* ✨ **Google Fonts Optimization**: Automatically adds display=swap
* 🔧 **Multiple Developer Filters**: Allows advanced customization via hooks
* 🔧 **Jetpack Compatibility**: Automatically keeps XML-RPC if Jetpack is active
* 🔧 **Smart Cache**: Enhanced cache system for critical CSS and other resources
* 🔧 **Scheduled Tasks**: Automatic daily cleanup of expired transients
* 📦 **Improved Architecture**: Code restructured following WordPress best practices
* 🛡️ **Enhanced Security**: All functions carry ayudawp_ prefix following standards
* 🌐 **Translation Ready**: Updated text domain and strings prepared for i18n
* ⚡ **Better Performance**: Significant optimizations in loading time and server resources
* 📈 **Better Metrics**: Specific optimizations for Core Web Vitals and measurement tools

= 1.0.7 =
* Tested up to WordPress 6.7.1

= 1.0.6 =
* Tested up to WordPress 6.6.1

= 1.0.5 =
* Tested up to WordPress 6.5.2
* Added support for PHP 8.2 (props @dbase66)

= 1.0.4 =
* Tested up to WordPress 6.4

= 1.0.3 =
* Tested up to WordPress 6.2

= 1.0.2 =
* Tested up to WordPress 6.1
* Updated requirements for WP and PHP

= 1.0.1 =
* Tested up to WordPress 6.0.2

= 1.0 =
* Tested up to WordPress 6.0
* Yes! It was time to change to version 1.x

= 0.9.31 =
* Change PageSpeed URL

= 0.9.30 =
* Tested up to WordPress 5.9

= 0.9.29 =
* Tested up to WordPress 5.8

= 0.9.28 =
* Tested up to WordPress 5.6

= 0.9.26 =
* Removed jQuery Migrate option because it's not needed since WordPress 5.5

= 0.9.25 =
* Tested up to WordPress 5.5

= 0.9.24 =
* Regression to previous method for defer parsing of JavaScript due to support issues with Divi theme

= 0.9.23 =
* Tested up to WordPress 5.4.1

= 0.9.22 =
* New method for defer parsing of JavaScript. Especially useful with YouTube iframes and other external video sources

= 0.9.21 =
* Tested up to WordPress 5.3.2

= 0.9.20 =
* Changes in jQuery Migrate code to resolve support of latest Elementor version

= 0.9.19 =
* Tested up to WordPress 5.3

= 0.9.18 =
* Tested up to WordPress 5.2.2

= 0.9.17 =
* Tested up to WordPress 5.2

= 0.9.16 =
* Tested up to WordPress 5.1

= 0.9.15 =
* Added conditionals to deflate lines in .htaccess file to prevent 500 error on some hostings (Props to frayca)

= 0.9.14 =
* Added tested up to WordPress 5.0 tag

= 0.9.13 =
* Tested up to WordPress 4.9.8

= 0.9.12 =
* Added functions to disable internal self pingbacks

= 0.9.11 =
* Added line to check if "expires" module is active to prevent 500 error on some servers where module is not active

= 0.9.10 =
* Tested up to WordPress 4.9.6

= 0.9.9 =
* Added browser cache expiration rules to main WordPress .htaccess file
* Added GZIP compression rules to main WordPress .htaccess file
* This is the first version with version tags
* Props to @carloslongarela for .htaccess improvements

= 0.9.8 =
* Tested up to WordPress 4.9.5

= 0.9.7 =
* Added exception in Dashicons removal to show them in Customizer

= 0.9.6 =
* Changed method for Heartbeat API - now controls interval to 60 seconds instead of default 15 seconds
* Added credits to admin footer
* Plugin tested up to WordPress 4.9 RC

= 0.9.5 =
* Added link to WebPageTest in readme.txt to measure results
* Changed to `script_loader_tag` filter method for Defer Parsing of JavaScript

= 0.9.4 =
* Changed method for Defer Parsing of JavaScript to resolve AMP issues with Google CDN
* Changed donation URL

= 0.9.3 =
* Changed minimum WP version from 4.0 to 4.1
* Added filter to remove capital_p_dangit filter
* Added functions to disable PDF thumbnails previews
* Added multiple actions to clean header (props @carloslongarela)
* Better code standards and functions ordering
* Changes in readme.txt

= 0.9.2 =
* Added multiple actions to clean WordPress header
* Added function to remove jquery_migrate

= 0.9.1 =
* Fixed Text Domain to be ready for translation

= 0.9 =
* Initial version

== Upgrade Notice ==

= 2.0.x =
NEW MAJOR VERSION! Includes advanced optimizations like automatic critical CSS, deferred CSS loading, automatic preconnect, native lazy loading, database optimizations and much more. Fully backward compatible. Update for maximum performance!