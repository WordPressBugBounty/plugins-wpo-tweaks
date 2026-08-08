=== DietPress ===
Contributors: fernandot, ayudawp
Tags: performance, optimization, cleanup, speed, bloat
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 3.4.1
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
* Google Fonts local hosting: serve the fonts your theme uses from your own server, GDPR-friendly with a silent fallback to the Google CDN (opt-in)
* Selective third-party loading: WooCommerce, Contact Form 7, block library, Slider Revolution, TablePress, Smash Balloon, Formidable Forms and Everest Forms assets only load where they are used (opt-in)
* RSS feed optimization (cache headers and item limit)
* Server rules in .htaccess: browser caching, GZIP and Brotli compression, immutable cache headers, CORS for fonts and keep-alive (master switch plus per-feature toggles)
* Database maintenance: daily expired-transient cleanup and safe query optimizations

**2. Put WordPress on a diet (risk-based, opt-in)**

* **Light** (safe for any site): emojis, RSD/WLW tags, shortlinks, self-pingbacks, comment pagination, and more
* **Moderate** (evaluate first): oEmbed, jQuery Migrate, Dashicons on the frontend, Global Styles and Duotone, remote block patterns, avatars and Gravatar, comment threading, and more
* **Strict** (site-specific): granular RSS feed control, Heartbeat API mode, post revisions and autosave, disable comments, XML sitemap, native lazy loading/fetchpriority, content types, selective loading for WooCommerce, Contact Form 7, block assets, Slider Revolution, TablePress, Smash Balloon, Formidable Forms and Everest Forms, and more
* **Widgets**: dashboard widgets (including third-party ones from Yoast, WooCommerce, Elementor, Jetpack, Wordfence, Rank Math, Gravity Forms), classic sidebar widgets, block-editor widgets and the Customizer
* **Emails**: silence the automatic emails WordPress sends on its own, grouped by area: auto-update results for core, plugins and themes (plus the new-version notice), comment moderation and new-comment notices, and new user, password and email-change notices, plus toggles for the admin email verification prompt and post-by-email. Every option is off by default, and critical notices such as a failed core update are always kept

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
* `dietpress_exclude_local_fonts` - Exclude Google Fonts stylesheets from local hosting
* `dietpress_selective_{module}_has_content` - Mark a page as showing the content of a selective loading module, so its assets are kept. The module is `wc`, `cf7`, `formidable`, `everest_forms` or `revslider`
* `dietpress_selective_{module}_styles` / `dietpress_selective_{module}_scripts` - Adjust the handles removed by each module
* `dietpress_selective_page_hides_content` - Mark a page as rendering content the content scan cannot reach, so no module removes anything (this is what handles Elementor)
* `dietpress_selective_is_wc_page` - Override the WooCommerce page detection of selective loading
* `dietpress_selective_wc_keep_cart_fragments` - Keep the cart fragments script when your theme has a hand-coded mini-cart
* `dietpress_selective_cf7_has_form` - Mark pages that load a Contact Form 7 form dynamically
* `dietpress_selective_blocks_dequeue` - Override the block library dequeue decision
* `dietpress_selective_everest_forms_dequeue_dashicons` - Keep Dashicons when another plugin enqueues it directly
* `dietpress_native_sitemap_in_use` - Tell DietPress your plugin builds on the native WordPress sitemap, so the option that removes it becomes unavailable

**Compatible with:**

* Well-coded themes and page builders (Divi, Elementor, Beaver Builder, Bricks Gutenberg)
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

= How does Google Fonts local hosting work? =

When you enable it (Light tab, Performance section), DietPress detects the Google Fonts stylesheets your theme enqueues, downloads the stylesheet and its font files once, stores them in your uploads folder, and serves everything from your own server. Visitors no longer connect to Google (faster fonts and GDPR-friendly, since no visitor data is sent to a third party). If any download fails, the fonts silently keep loading from the Google CDN. The local copies are refreshed when you switch themes and removed when you disable the option or deactivate the plugin. Fonts hardcoded by a theme outside the standard WordPress enqueue system are left untouched.

= Selective loading removed something my site needs =

Each selective loading module only removes assets where its target content is not detected, but unusual setups exist: a hand-coded header mini-cart, a form injected via AJAX, a slider printed by the theme, or a classic theme that reuses block styles everywhere. Turn the specific toggle off, or use the escape filters (`dietpress_selective_{module}_has_content`, `dietpress_selective_page_hides_content`, `dietpress_selective_wc_keep_cart_fragments`, `dietpress_selective_cf7_has_form`, `dietpress_selective_blocks_dequeue`) to keep exactly what your site needs.

Pages built with Elementor, and any request an Elementor Theme Builder template applies to, are left alone on purpose: their content lives in the database, out of reach of the content scan.

= Do the TablePress and Smash Balloon modules dequeue anything? =

No. Both plugins already have a conditional loading mode of their own, off by default in the case they cover, and those two modules simply turn it on for visitors. The plugin itself then loads its stylesheet when a table or a feed is rendered, so nothing can end up unstyled. The Formidable Forms module does dequeue, but it puts the plugin own footer fallback back in play for the same reason.

= What does the Slider Revolution module do exactly? =

It turns the "Include libraries globally" setting of Slider Revolution off for each visit, without saving anything and without touching your configuration. From there Slider Revolution decides on its own, exactly as if you had turned that setting off in its Global Settings: it loads its libraries in preview mode, when one of its shortcodes is in the content, when its widget is active, and on any page you listed in its own "List of pages to include RevSlider libraries". DietPress only adds the cases its check misses, such as a shortcode inside a text widget or an archive page. If you already turned that setting off yourself, the module does nothing at all and your page list stays in charge.

One thing to know: with the global loading off, the `add_revslider()` PHP function that some themes use to print a slider from a template refuses to render and shows a notice instead. That is how Slider Revolution behaves on its own, and the remedy is the one it documents: add those pages to its list.

= Why is one of the options greyed out, or telling me it does nothing? =

Because it cannot do what it says right now, and saying so is better than letting you switch on something inert. Two things can happen. An option is unavailable when it would contradict another one or break another plugin: "Disable WordPress XML sitemap" while a plugin builds its own sitemap on top of the native one, or "Disable native lazy loading" and "Disable fetchpriority attribute" while "Enhance image loading attributes" is on and already sets those attributes itself. And an option simply says it has no effect when another option already covers it: the granular RSS feed toggles while "Disable ALL RSS feeds" is on, or the .htaccess sub-options while the master switch is off. Those ones stay usable, so you can set them up before turning the master switch on.

Whatever you had saved is kept. An option that cannot apply is not switched off behind your back: it stays stored and starts working again as soon as the thing blocking it changes.

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
6. Emails tab: silence the automatic emails WordPress sends on its own, grouped by updates, comments, users and passwords.

== Changelog ==

= 3.4.1 =
* Improved: The settings page code is no longer loaded on frontend requests. Around 90 KB of PHP that only the admin ever needs was being read and parsed on every visit to the site.
* Improved: The saved settings are no longer completed with the built-in defaults on every single request. That work, and the database write it could trigger in the middle of an anonymous visit, now happens only after a plugin update, which is the only moment it can change anything.
* Fix: Translations were loaded before the init action, which printed a "Translation loading for the wpo-tweaks domain was triggered too early" notice on sites that have DietPress translated and WP_DEBUG on. Deciding whether an option was locked went through the sentence that explains the lock, so merely reading a setting asked for a translation while the plugin was still loading.
* Fix: The WooCommerce selective loading module left two stylesheets behind on pages with no store content. On WordPress 7.0, wc-blocks-style survived because block styles are now enqueued while the page renders and only afterwards moved up into the head, past the point where the module was looking. And with any block theme, woocommerce-blocktheme was never on the list to begin with, so it loaded everywhere. Every other WooCommerce file was already being removed.

= 3.4.0 =
* New: Selective loading covers five more plugins that load their assets on every page. Slider Revolution, around 660 KB of JavaScript per page, by turning its own Include libraries globally setting off for each visit and letting Slider Revolution decide, so its shortcodes, its widget and its own page list keep working (DietPress adds the cases its check misses, such as a shortcode inside a widget). TablePress and Smash Balloon, by turning on the conditional loading each one already ships with (nothing is dequeued, and no table or feed can end up unstyled). Formidable Forms, whose stylesheet is removed while its own footer fallback is put back in play. And Everest Forms, including the Dashicons file it forces on every visitor. All five are off by default, the site analyzer suggests each one when its plugin is active, and the Maximum cleanup profile enables them.
* New: Options that cannot do what they promise now say so in their own card, and the ones that would contradict another option or break another plugin cannot be switched on at all. "Disable WordPress XML sitemap" becomes unavailable while a plugin that builds on the native sitemap is active (Visibility, or any plugin that says so through the new dietpress_native_sitemap_in_use filter), so nobody can pull the sitemap from under it by mistake. A stored value is never lost: it stays saved, it simply does not apply while the lock lasts, and the site analyzer stops suggesting anything locked.
* New: The cards for "Disable Posts content type" and "Disable Pages content type" now count what you have and warn before you switch them on, for example that your site has 42 pages that would disappear from the admin, the frontend and your menus. Nothing is ever deleted, and the warning travels with the analyzer recommendations too.
* Improved: Selective loading no longer removes assets on pages it cannot read. A page built with Elementor keeps its content in the database, out of reach of the content scan, and an Elementor Theme Builder template can inject a header, a footer or a popup into any request. Those pages now keep the assets of every detection-based module, which closes the known gap of the Contact Form 7 module with forms placed inside a builder.
* Improved: Every selective loading module that removes handles now exposes the same three filters (dietpress_selective_{module}_has_content, _styles and _scripts), where before only WooCommerce had its handle lists filterable; the modules that flip a native setting expose their own escape filter instead. The filters published in 3.3.0 keep working.
* Improved: The developer filters from the 2.x days (the ayudawp_wpotweaks_* prefix, renamed in 3.0.0) now report their deprecation through the standard WordPress notice, only when a site still has a callback hooked to one of them and only with WP_DEBUG on. They keep working, and are scheduled for removal in 4.0.
* Improved: "Disable native lazy loading" and "Disable fetchpriority attribute" are no longer offered while "Enhance image loading attributes" is on. They only make sense when another plugin manages image loading, and with the DietPress option on they changed nothing, because DietPress sets those attributes itself right after WordPress. Turning off the image enhancements makes both available again, without reloading the page. If you had one of them on together with the image enhancements, the attributes were already being set by DietPress; now WordPress keeps setting its own too, which is the behavior the option descriptions always described.
* Improved: The Scale tab is coherent with all of the above. The savings counter no longer counts an option that cannot apply, the analyzer does not suggest one, and applying a quick profile no longer stores one: it keeps whatever the site had and says which option it left alone and why. The Maximum cleanup profile also stops switching on "Disable native lazy loading" and "Disable fetchpriority attribute", which it combined with the image enhancements it keeps on, so they could never do anything.
* Improved: The options that another option already covers now say so instead of looking active for no reason: the five granular RSS feed toggles while "Disable ALL RSS feeds" is on, the six .htaccess sub-options while the master switch is off, and the Customizer widgets one while the whole Customizer is disabled. They stay usable so a site can be configured in any order.
* Improved: The option cards of every section are now laid out by how much text each one carries, so the two-column grid forms even rows instead of a ragged, masonry-looking wall. The order follows the texts themselves, so it keeps working after an edit or a translation, and the switch that governs a group (the .htaccess master, the nuclear RSS option) still comes first. Card notes sit at the bottom, so the ones sharing a row line up.
* Fix: The site analyzer recommendation to disable the WordPress sitemap checked a constant belonging to no real plugin, so it only ever detected Yoast SEO, Rank Math and All in One SEO. It now also detects SEOPress, The SEO Framework and Slim SEO.
* Fix: Internal cleanup with no change in behavior: an unused rendering method, two deletions of a transient that is never written, and a duplicated cleanup of a 2.x option on activation.

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/wpo-tweaks/trunk/changelog.txt) file.

== Upgrade Notice ==

= 3.4.1 =
Fixes a notice about translations loading too early with WP_DEBUG on, and two WooCommerce stylesheets that selective loading left behind on WordPress 7.0 and on block themes. The settings page code and the defaults merge no longer run on frontend requests.

== Support ==

Need private support or custom development?

Do you need one-on-one help, priority troubleshooting, or a custom feature, integration, or tweak built specifically for your site? I offer private support and custom development. Just [contact me](mailto:wpo-tweaks@ayudawp.com) and tell me what you need.

Need help or have suggestions?

* [Official website](https://servicios.ayudawp.com/)
* [WordPress support forum](https://wordpress.org/support/plugin/wpo-tweaks/)
* [YouTube channel](https://www.youtube.com/AyudaWordPressES)
* [Documentation and tutorials](https://ayudawp.com/)

Love the plugin? Please leave us a 5-star review and help spread the word!

== About AyudaWP ==

We are specialists in WordPress security, SEO, AI and performance optimization plugins. We create tools that solve real problems for WordPress site owners while maintaining the highest coding standards and accessibility requirements.
