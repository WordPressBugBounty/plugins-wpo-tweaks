=== DietPress ===
Contributors: fernandot, ayudawp
Tags: performance, optimization, cleanup, speed, bloat
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 3.1.0
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Put your WordPress on a diet and speed it up. Disable the bloat you do not need and enable performance optimizations, all fully configurable.

== Description ==

DietPress puts your WordPress on a diet and speeds it up. It pairs a complete set of performance optimizations (the ones that used to ship in "Zero Config Performance Optimization") with a clean, risk-based interface to disable the WordPress features you do not use. Everything is configurable, and the performance optimizations are already on by default, so you can simply activate and enjoy a faster site, or fine-tune every detail.

> **Coming from "Zero Config Performance Optimization"?** This is the same plugin, now called DietPress and fully configurable. All your previous optimizations stay active by default; you just gained a settings page and a whole new set of WordPress-diet options.

By default WordPress loads functions, services and scripts that most sites do not need. They slow down loading times and consume hosting resources. DietPress lets you trim that fat and apply battle-tested performance tweaks, with a clear description of what each option does and what might break, organized by risk level so you always know what is safe.

### TWO THINGS IN ONE PLUGIN

**1. Performance optimizations (on by default)**

* Automatic Critical CSS inlined in the head (optional experimental deferral of non-critical CSS)
* JavaScript defer parsing with smart dependency handling
* Image loading attributes safety net: lazy loading, decoding=async and fetchpriority for images that bypass core
* Automatic image dimensions for better CLS scores (including picture elements)
* Resource hints: preconnect and DNS prefetch for common third-party origins
* Theme stylesheet, critical fonts and logo preloading for a faster LCP
* Google Fonts display=swap
* RSS feed optimization (cache headers and item limit)
* Server rules in .htaccess: browser caching, GZIP and Brotli compression, immutable cache headers, CORS for fonts and keep-alive (master switch plus per-feature toggles)
* Database maintenance: daily expired-transient cleanup and safe query optimizations

**2. Put WordPress on a diet (risk-based, opt-in)**

* **Light** (safe for any site): emojis, RSD/WLW tags, shortlinks, self-pingbacks, comment pagination, admin email check, and more
* **Moderate** (evaluate first): oEmbed, jQuery Migrate, Dashicons on the frontend, Global Styles and Duotone, remote block patterns, avatars and Gravatar, comment threading, and more
* **Strict** (site-specific): granular RSS feed control, Heartbeat API mode, post revisions and autosave, disable comments, XML sitemap, native lazy loading/fetchpriority, content types, and more
* **Widgets**: dashboard widgets (including third-party ones from Yoast, WooCommerce, Elementor, Jetpack, Wordfence, Rank Math, Gravity Forms), classic sidebar widgets, block-editor widgets and the Customizer

### SCALE, PROFILES AND ANALYZER

* Savings indicator: HTTP requests removed, CSS/JS saved and active optimizations at a glance
* Quick profiles: Personal Blog, WooCommerce Store, Landing Page and Maximum Cleanup
* Site analyzer: personalized recommendations based on your active plugins and content
* Import and export your whole configuration as a JSON file

### COMPATIBILITY AND EXTENSIBILITY

The plugin includes filters for developers:

* `dietpress_critical_css` - Customize the inline critical CSS
* `dietpress_critical_css_handles` - Define which CSS handles are critical
* `dietpress_skip_defer_script_handles` - Opt scripts out of the JavaScript defer
* `dietpress_skip_defer_style_handles` - Opt stylesheets out of the CSS deferral
* `dietpress_preconnect_hints` - Customize preconnect origins
* `dietpress_dns_prefetch_domains` - Customize DNS prefetch domains
* `dietpress_critical_fonts` - Define critical fonts to preload

**Compatible with:**

* Well-coded themes and page builders (Divi, Elementor, Beaver Builder, Gutenberg)
* Cache plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, etc.)
* Security plugins (DietPress focuses on performance and deliberately leaves security to them; we recommend our free Vigilant)
* CDNs (Cloudflare, StackPath, KeyCDN, etc.) thanks to CORS and Vary headers
* WordPress Multisite

### HOW TO VERIFY THE OPTIMIZATIONS

* **Cache rules:** check your `.htaccess` for a block marked `# BEGIN DietPress` with `immutable` Cache-Control headers
* **Logo preload:** view page source and look for `<link rel="preload" ... fetchpriority="high">` pointing to your logo
* **Critical CSS:** view source and look for `<style id="core-diet-critical-css">` in the head
* **Compression:** test at [giftofspeed.com/gzip-test](https://www.giftofspeed.com/gzip-test/)

Always measure with tools like Google PageSpeed, GTMetrix or WebPageTest, and run each test at least twice to account for caching.

== Installation ==

1. Go to your WP Dashboard > Plugins > Add New and search for 'DietPress', or upload the `wpo-tweaks` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Open the **DietPress** menu to review the settings. Performance optimizations are already on; the diet options are off until you enable them.

== Frequently Asked Questions ==

= I was using "Zero Config Performance Optimization". What changed? =

It is the same plugin, now called DietPress. All the performance optimizations you had are still active by default, so nothing breaks on update. On top of that you now get a settings page, individual control over every optimization, and a complete set of options to disable unused WordPress features.

= Is it still zero-config? =

Yes, if you want it to be. The performance optimizations are on by default, so you can just activate and go. The difference is that now you can fine-tune everything and, optionally, put WordPress on a diet by disabling features you do not use.

= I also have the standalone "DietPress" (core-diet) plugin installed. What do I do? =

Nothing needs to be done by hand. When this plugin is active it detects the old "core-diet" plugin and deactivates it automatically (and core-diet 1.0.4 also steps aside on its own). Your settings are preserved because both plugins store them in the same place. The only thing left for you to do is delete the "core-diet" plugin whenever you like.

= Where are the security options the standalone DietPress had? =

They were intentionally left out. The standalone DietPress (core-diet) included a few security toggles (disable XML-RPC, hide login errors, disable Application Passwords, hide the WordPress version, close pingbacks). Security belongs in a security plugin, where those protections are implemented properly and maintained as such; we recommend our free [Vigilant](https://wordpress.org/plugins/vigilante/). If you migrate with any of those toggles enabled, DietPress shows you a one-time notice listing them, and those features simply return to the default WordPress behavior.

= Will it break my site? =

The performance optimizations are designed to be safe and are tested across many sites. The diet options only change something when you explicitly enable each toggle, and every option has a description of what might break. If something fails, turn the toggle off; deactivating the plugin restores default WordPress behavior.

= Is it compatible with caching plugins and CDNs? =

Yes. DietPress works alongside caching plugins and includes CORS and Vary headers for full CDN compatibility.

= Something went wrong after activation =

If a plugin or theme does not enqueue scripts correctly, the JavaScript defer may affect it; you can turn that option off or use the `dietpress_skip_defer_script_handles` filter. If you get a 500 error, edit your `.htaccess` and remove the block that starts with `# BEGIN DietPress` (or `# BEGIN Zero Config Performance` if you updated from 2.x and the rules have not been rewritten yet), or disable the ".htaccess server rules" option.

= Can I customize the optimizations as a developer? =

Yes. See the filters listed in the description (the `dietpress_*` hooks).

== Screenshots ==

1. Scale tab: savings indicator, quick profiles and site analyzer.
2. Light tab: safe optimizations and cleanup, organized by section.
3. Moderate tab: image, database and editor options to evaluate.
4. Strict tab: frontend performance, server .htaccess rules and site-specific settings.
5. Widgets tab: dashboard, block editor, Customizer and classic sidebar widgets.

== Changelog ==

= 3.1.0 =
* Improved: JavaScript defer now skips a built-in list of third-party scripts that must run on load (reCAPTCHA, Typekit, HubSpot forms, payment SDKs and more), matched by URL, and extendable with the new `dietpress_skip_defer_script_patterns` filter.
* Improved: image optimization now leaves images already handled by another lazy-load solution untouched, honoring the `skip-lazy`/`data-skip-lazy` markers and the markup of common sliders (LayerSlider, Soliloquy), the Contact Form 7 captcha and the WooCommerce placeholder.
* Improved: image optimization is skipped inside page builder editors and previews (Beaver Builder, Divi, Oxygen and WordPress previews) so layouts are not altered while editing.
* Improved: site logo detection for image priority is now filterable via the new `dietpress_logo_class_patterns` filter.
* Improved: missing dimensions are now added to SVG images too (read from their width/height or viewBox), reducing layout shift.
* Improved: the .htaccess rules now declare the AVIF MIME type so AVIF images are served correctly on older Apache servers, and set the document charset from your site settings.
* Improved: the Scale tab savings counter now also accounts for the performance optimizations (defer, critical CSS, image loading, resource hints, feeds, transient and query optimization, and .htaccess rules).
* Fix: the per-tab "Restore defaults" button now also resets the performance options shown on that tab, which it previously skipped.
* Fix: settings export files now use the "dietpress" identifier; imports still accept files exported by earlier versions.

= 3.0.1 =
* Fix: removed the "REST API access" control from the Strict tab. Restricting the REST API is a security feature, not a performance one, and it overlapped with dedicated security plugins, with which it could also clash on the same `rest_authentication_errors` filter. REST API access now follows default WordPress behavior; manage it from your security plugin instead, such as our free Vigilant. If you had set it to "Require authentication" or "Disable", that restriction no longer applies after updating, and DietPress shows a one-time notice pointing you to Vigilant.

= 3.0.0 =
* New: the plugin is now DietPress (formerly "Zero Config Performance Optimization"), fully configurable from a new top-level "DietPress" admin menu with its own settings page.
* New: a complete, risk-based set of options to put WordPress on a diet (Light, Moderate, Strict and Widgets), covering emojis, RSD/WLW, shortlinks, self-pingbacks, oEmbed, jQuery Migrate, Dashicons, REST API control, granular RSS feeds, comments, Heartbeat, revisions, autosave, content types, and dashboard/sidebar/block/Customizer widgets. Security-related toggles (XML-RPC, pingbacks, login errors, Application Passwords, version hiding) are intentionally not included: they belong in a security plugin, like our free Vigilant, and DietPress tells you once if you had any of them enabled.
* New: Scale tab with a savings indicator, quick profiles (Personal Blog, WooCommerce, Landing Page, Maximum Cleanup) and a site analyzer with recommendations.
* New: import and export your configuration as a JSON file.
* Improved: every performance optimization from the previous "Zero Config" version is now an individual setting you can fine-tune (JavaScript defer, Critical CSS, image loading attributes, image dimensions, resource hints, asset and logo preloading, RSS feed optimization, .htaccess browser caching/GZIP/Brotli/headers, transient cleanup and query optimizations). They all stay ON by default, so updating sites keep their current behavior.
* Improved: the Critical CSS deferral of non-critical stylesheets (the part that can cause a flash of unstyled content) is now a separate, opt-in "experimental" toggle, while the safe inline critical CSS stays on by default.
* Improved: the .htaccess server rules are now applied when you save settings, with a master switch plus per-feature toggles (browser caching, GZIP, Brotli, cache headers, CORS for fonts, keep-alive), and are removed cleanly on deactivation. The block is now labeled "# BEGIN DietPress"; the old "Zero Config Performance" block is replaced automatically.
* Improved: developer filters renamed to the `dietpress_` prefix (`dietpress_critical_css`, `dietpress_critical_css_handles`, `dietpress_skip_defer_script_handles`, `dietpress_skip_defer_style_handles`, `dietpress_preconnect_hints`, `dietpress_dns_prefetch_domains`, `dietpress_critical_fonts`). The former `ayudawp_wpotweaks_*` filters keep working through a compatibility bridge, but are deprecated: please update your snippets to the new names.
* Fix: the retired standalone DietPress ("core-diet") plugin is detected and deactivated automatically, preventing fatal conflicts while both are installed; its settings carry over.

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/wpo-tweaks/trunk/changelog.txt) file.

== Upgrade Notice ==

= 3.1.0 =
Sharper JavaScript defer and image optimization: skips problematic third-party scripts, respects other lazy-load plugins and page builders, adds SVG dimensions and the AVIF MIME type. Also fixes per-tab "Restore defaults" and the savings counter for performance options.

== Support ==

Need help or have suggestions?

* [Official website](https://servicios.ayudawp.com/)
* [WordPress support forum](https://wordpress.org/support/plugin/wpo-tweaks/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com/)

Love the plugin? Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.
