=== WPO Tweaks & Performance Optimizations ===
Contributors: fernandot, ayudawp
Tags: performance, optimization, speed, cache, lazy-loading
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 2.1.1
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced performance optimizations for WordPress. Improves speed, reduces server resources and optimizes PageSpeed.

== Description ==

WPO Tweaks is the most complete performance optimization plugin for WordPress. It combines the best WPO (Web Performance Optimization) practices in a single easy-to-use tool. No configuration needed: activate and enjoy a faster WordPress.

By default, WordPress loads several functions, services and scripts that are not mandatory and usually slow down your installation and consume hosting resources. For years I have been testing tweaks to save hosting resources and improve WordPress performance and loading times. After thousands of tests, this plugin includes my best speed and performance optimizations with a single click.

With this plugin you can safely disable those annoying services, unnecessary codes and scripts to save resources and hosting costs, and speed up WordPress to get better results in tools like Google PageSpeed, Pingdom Tools, GTMetrix, WebPageTest and others.

**New version 2.1.x with modular architecture and enhanced reliability!**

### NEW FEATURES V2.1.x

**Modular Architecture:**
* **Complete Code Refactoring**: Plugin rebuilt with modular architecture for better maintainability and performance
* **Separated Components**: Each optimization is now in its own module for easier debugging and updates
* **Enhanced Reliability**: Improved error handling and better compatibility across different hosting environments

**File Management System:**
* **Automatic Backups**: Creates secure backups of wp-config.php and .htaccess before modifications
* **Safe Restoration**: Automatic restoration of original files on plugin deactivation
* **Intelligent Installation**: Detects and resolves conflicts with existing configurations

**Image Optimizations:**
* **Missing Image Dimensions**: Automatically detects and adds width/height attributes to images and picture elements without dimensions, improving Cumulative Layout Shift (CLS) scores
* **Picture Element Support**: First plugin to automatically add dimensions to <picture> elements (more comprehensive than most optimization plugins)
* **Enhanced Lazy Loading**: Improved lazy loading system with better Gravatar support

**Configuration Management:**
* **wp-config.php Optimization**: Direct wp-config.php modifications for guaranteed trash retention settings (7 days)
* **Conflict Resolution**: Automatically removes conflicting existing configurations
* **Clean Deactivation**: Complete cleanup of all modifications when plugin is deactivated

**User Experience:**
* **Activation Notice**: Informative welcome message showing all applied optimizations
* **Admin Dashboard**: Clean interface with optimization summary
* **Developer Friendly**: Enhanced filter system for advanced customization

### INCLUDED OPTIMIZATIONS

**Classic Optimizations (since v1.0):**
* Browser cache rules in .htaccess
* GZIP compression in .htaccess  
* Remove Dashicons in admin bar (non-logged users only)
* Remove Emojis styles and scripts
* Disable REST API (completely disabled)
* Control Heartbeat API interval (60s instead of 15s)
* Remove Query Strings from static resources
* Defer JavaScript parsing
* Remove Query Strings from Gravatar
* Remove Really Simple Discovery link from header
* Remove wlwmanifest.xml (Windows Live Writer) from header
* Remove URL Shortlink from header
* Remove WordPress Generator version from header
* Remove DNS Prefetch from s.w.org
* Remove unnecessary links from header
* Remove RSS feeds generator name
* Remove Capital P Dangit filter
* Disable PDF thumbnails previews
* Disable internal Self Pingbacks

**Advanced Optimizations (since v2.0):**
* **Automatic Critical CSS** with smart cache
* **Deferred CSS Loading** for non-critical styles
* **Automatic preconnect** for Google Fonts, Analytics, etc.
* **Smart DNS Prefetch** for external resources including Gravatar
* **Native Lazy Loading** with decoding=async
* **Automatic transients cleanup** for expired entries
* **Database query optimization**
* **jQuery Migrate removal** when not needed
* **Critical resources preloading** (theme CSS, fonts)
* **Enhanced security headers**
* **Administrative dashboard cleanup**
* **Smart revisions and trash management**

**New in v2.1.0:**
* **Missing Image Dimensions** - Automatically adds width/height attributes to improve CLS scores
* **Enhanced File Management** - Secure backup and restoration system
* **Modular Code Architecture** - Better performance and maintainability
* **Improved wp-config.php Handling** - Direct configuration management for better reliability

### HOW TO VERIFY OPTIMIZATIONS ARE WORKING

You can check each optimization individually to ensure WPO Tweaks is working correctly:

**Missing Image Dimensions:** Inspect images in your browser (F12 > Elements). Images should have `width="X"` and `height="Y"` attributes even if they weren't originally coded with dimensions.

**Critical CSS:** View page source (Ctrl+U) and look for `<style id="ayudawp-wpotweaks-critical-css">` in the head section containing basic CSS rules.

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

### COMPATIBILITY AND EXTENSIBILITY

The plugin includes multiple filters for developers:
* `ayudawp_wpotweaks_critical_css` - Customize critical CSS
* `ayudawp_wpotweaks_preconnect_hints` - Add custom preconnect
* `ayudawp_wpotweaks_dns_prefetch_domains` - Customize DNS prefetch domains
* `ayudawp_wpotweaks_critical_fonts` - Define critical fonts for preload
* `ayudawp_wpotweaks_keep_xmlrpc` - Keep XML-RPC if needed
* `ayudawp_wpotweaks_keep_feeds` - Control feeds removal

**Compatible with:**
* Jetpack (keeps XML-RPC automatically)
* All well-coded themes
* Cache plugins (W3 Total Cache, WP Rocket, etc.)
* WordPress Multisite
* Builders (Elementor, Divi, Gutenberg)

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

1. Go to your WP Dashboard > Plugins and search for 'wpo tweaks' or…
2. Download the plugin from WP repository
3. Upload the 'wpo-tweaks' folder to '/wp-content/plugins/' directory
4. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

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

This plugin is compatible with all WordPress JavaScript functions (`wp_localize_script()`, js in header, in footer...) and works with all well-coded plugins and themes. If a plugin or theme is not enqueuing scripts correctly, your site may not work. If your hosting doesn't support some of the tweaks, usually due to security restrictions, something may fail. 

If something fails, please access your `/wp-content/plugins/wpo-tweaks/` directory via your favorite FTP client or hosting panel (cPanel, Plesk, etc.) and rename the plugin folder to deactivate it.

If you get a 500 Error (server error), then go to your hosting panel and edit the .htaccess file to remove the lines added by the plugin (they start with 'WPO Tweaks by Fernando Tellado') and save changes, or delete the file and create it again from Dashboard > Settings > Permalinks > Save changes.

= What's next? =

I will be including in next updates every new performance tweak I test for better results in order to speed up WordPress.

= Do you plan to include a settings panel? =

No. WPO Tweaks plugin is intended for users who want to get optimizations and speed safely with one click. If you are a developer and know what you are doing, then please check out [Machete plugin by my friend Nilo Velez](https://wordpress.org/plugins/machete/), a complete suite to decide how to solve common WordPress problems and annoyances. And yes, it has a huge settings page!

= Can I customize the optimizations? =

Yes, since v2.0+ the plugin includes multiple WordPress filters for developers that allow customizing plugin behavior according to specific site needs.

== Screenshots ==

1. Pingdom Tools results before plugin activation
2. Pingdom Tools results after plugin activation

== Changelog ==

= 2.1.1 =
* **CRITICAL FIX: Admin bar display for Editor and Author roles**
* Fixed bug where users with Editor and Author roles couldn't see the admin bar correctly
* Dashicons now only removed for non-logged users (ALL logged-in users, regardless of role, can see admin bar)
* Improved logic in Script Optimization module
* Improved logic in Critical CSS module
* Better compatibility with all WordPress user roles
* Fixed: Admin notice footer text now fully translatable

= 2.1.0 =
* **NEW MAJOR REFACTORING: Modular architecture for better maintainability**
* NEW: **Missing Image Dimensions** - Automatically adds width/height attributes to <img> and <picture> elements for better Cumulative Layout Shift (CLS) scores
* NEW: **Picture Element Support** - First plugin to automatically add dimensions to <picture> elements (more comprehensive than most optimization plugins)
* NEW: **Enhanced File Management System** - Automatic backup and restoration of wp-config.php and .htaccess files
* NEW: **Direct wp-config.php Management** - Guaranteed trash retention settings by modifying wp-config.php directly
* NEW: **Admin Activation Notice** - Informative welcome message showing all applied optimizations
* IMPROVED: Code completely refactored into modular architecture with separated components
* IMPROVED: Better performance with optimized module loading system
* IMPROVED: Enhanced debugging capabilities with isolated modules
* IMPROVED: Conflict resolution system for existing configurations
* IMPROVED: Complete cleanup system on plugin deactivation
* IMPROVED: Enhanced Gravatar lazy loading support
* IMPROVED: All filter names updated to `ayudawp_wpotweaks_*` for better specificity
* IMPROVED: Each optimization now has its own dedicated module for easier maintenance
* ARCHITECTURAL: Separated into specialized modules: File Management, Critical CSS, Image Optimization, Image Dimensions, Database Optimization, Script Optimization, Security Tweaks, Admin Optimization, Cache Optimization, and Admin Notice

= 2.0.3 =
* Removed: Admin Footer Credits (absolutely not necessary)
* Removed: Deactivation of WordPress file editor (better handle with a security plugin)
* Fixed: Better selection of dashboard widgets to remove (maintaining security related)

= 2.0.2 =
* Fixed: Pagination issues with Twenty Twenty and other themes
* Improved: Query optimization logic to preserve pagination functionality
* Enhanced: Better compatibility with various theme pagination systems
* Added: Better error handling for transient cleanup
* Improved: Memory usage optimization

= 2.0.1 =
* Fixed bug with WooCommerce not showing products in taxonomy and archive pages 

= 2.0.0 =
* **NEW MAJOR VERSION with advanced optimizations**
* NEW: **Automatic Critical CSS**: Above-the-fold critical CSS generation and injection
* NEW: **Deferred CSS Loading**: Non-critical CSS loads asynchronously with noscript fallback
* NEW: **Automatic Preconnect**: Automatic hints for Google Fonts, Analytics and other critical resources
* NEW: **Smart DNS Prefetch**: DNS preloading for common external resources
* NEW: **Native Lazy Loading**: Automatic lazy loading for all images with decoding=async
* NEW: **Database Optimizations**: Automatic cleanup of expired transients and query optimization
* NEW: **Resource Preloading**: Automatic preload for theme CSS and critical fonts
* NEW: **Smart jQuery Migrate Removal**: Only removes when not needed
* NEW: **Security Headers**: X-Pingback removal and sensitive information hiding
* NEW: **Dashboard Cleanup**: Removes unnecessary widgets from administration area
* NEW: **Revisions Management**: Limits automatic revisions to 3 and reduces trash retention to 7 days
* NEW: **Google Fonts Optimization**: Automatically adds display=swap
* IMPROVED: **Multiple Developer Filters**: Allows advanced customization via hooks
* IMPROVED: **Jetpack Compatibility**: Automatically keeps XML-RPC if Jetpack is active
* IMPROVED: **Smart Cache**: Enhanced cache system for critical CSS and other resources
* IMPROVED: **Scheduled Tasks**: Automatic daily cleanup of expired transients
* IMPROVED: **Improved Architecture**: Code restructured following WordPress best practices
* IMPROVED: **Enhanced Security**: All functions carry ayudawp_ prefix following standards
* IMPROVED: **Translation Ready**: Updated text domain and strings prepared for i18n
* IMPROVED: **Better Performance**: Significant optimizations in loading time and server resources
* IMPROVED: **Better Metrics**: Specific optimizations for Core Web Vitals and measurement tools

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

= 2.1.1 =
CRITICAL FIX: Resolves admin bar display issue for Editor and Author roles. Immediate update recommended for multi-user sites.

= 2.1.0 =
MAJOR UPDATE: New modular architecture with enhanced file management system. Automatic backups, missing image dimensions feature, and improved reliability. Fully backward compatible.

= 2.0.3 =
NEW: First Image Preload Optimization for better LCP performance and smart first image detection.

= 2.0.2 =
Important fix for pagination issues. Update recommended for all users experiencing pagination problems.

= 2.0.1 =
* Fixed: WooCommerce compatibility improvements.

= 2.0.x =
NEW MAJOR VERSION! Includes advanced optimizations like automatic critical CSS, deferred CSS loading, automatic preconnect, native lazy loading, database optimizations and much more. Fully backward compatible. Update for maximum performance!

== Support ==

= Need help or have suggestions? =
* [Official website](https://servicios.ayudawp.com/)
* [WordPress support forum](https://wordpress.org/support/plugin/wpo-tweaks/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com/)

**Love the plugin?** Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.