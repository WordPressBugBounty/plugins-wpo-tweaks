=== DietPress ===
Contributors: fernandot, ayudawp
Tags: performance, cache, optimization, cleanup, speed
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 3.5.1
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Page cache, browser cache, defer JS, critical CSS, lazy load and WordPress cleanup. Speed up your site and disable the bloat you do not need.

== Description ==

**DietPress is a free WordPress speed optimization plugin with a built-in page cache.** Page caching, browser caching, GZIP and Brotli compression, deferred JavaScript, critical CSS, image loading attributes, preloading, locally hosted Google Fonts and selective asset loading, all in one plugin and all free.

And it goes further than a caching plugin: it also puts WordPress itself on a diet, switching off the features your site never uses. It pairs that with a clean, risk-based interface. Everything is configurable, the optimizations are already on by default, and nothing is hidden behind a paid tier. Activate it and your site is faster, or open the settings and tune every detail.

By default WordPress loads functions, services and scripts that most sites do not need. They slow down loading times and consume hosting resources. DietPress lets you trim that fat and apply battle-tested performance tweaks, with a clear description of what each option does and what might break, organized by risk level so you always know what is safe.

### WHY CHOOSE DIETPRESS

* **Page caching, for free.** Serve anonymous visitors a copy stored on disk instead of building the page again. The feature people buy WP Rocket or a NitroPack subscription for, with no licence and no monthly fee.
* **No drop-in, no changes to wp-config.php.** Unlike WP Super Cache or W3 Total Cache, DietPress installs no `advanced-cache.php` and never edits `wp-config.php`. Switching it off leaves your site exactly as it was, with nothing orphaned behind.
* **It tells you why.** Most cache plugins leave you guessing when nothing is cached. DietPress reports its own status, tests itself against your home page, and names the exact reason a page was skipped.
* **WooCommerce-safe by design.** Carts, checkout, my account and any visitor carrying a cart cookie always get the live site, so nobody ever sees somebody else's basket.
* **Diet as well as speed.** Where Perfmatters focuses on disabling scripts, DietPress covers that ground and adds page caching, critical CSS, local Google Fonts and a dashboard, admin and email cleanup, in one plugin.
* **Light on your server.** No account, no external service, no telemetry, no upsell nags. Everything runs on your own hosting.

### WHAT THE PAGE CACHE DOES TO YOUR RESPONSE TIME

Measured on a WordPress 7.0 install with GeneratePress, WooCommerce and a 76 product catalogue, PHP 8.5, taking the median of 15 requests per URL. Your own hosting will give different figures, but the shape of the result will not change.

Server response time, the same pages with the page cache off and on:

* **Home page** - 52.7 ms without, **15.1 ms with**, 71% faster
* **WooCommerce shop** - 45.4 ms without, **14.8 ms with**, 67% faster
* **A 110 KB article** - 43.0 ms without, **15.1 ms with**, 65% faster
* **A 98 KB page** - 41.0 ms without, **14.9 ms with**, 64% faster
* **A product category** - 41.3 ms without, **15.1 ms with**, 63% faster

The interesting part is not the percentage, it is that the cached figure barely moves. Serving a stored file costs the same whether the page was cheap or expensive to build, so the saving grows with the size of your site rather than with the power of your server. The heaviest page in the test is the one that gained most.

It also changes how much traffic your hosting can take at once. In the same test the server went from serving about 40 visits a second to more than 210, **five times as many**, on exactly the same plan. That is what keeps a small site standing up when one of your posts does well.

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
* Server rules in .htaccess: browser caching with a configurable lifetime for media, for styles and scripts and for fonts, GZIP and Brotli compression, immutable cache headers, CORS for fonts and keep-alive (master switch plus per-feature toggles)
* Database maintenance: daily expired-transient cleanup and safe query optimizations
* Page cache: store each page on disk and serve it to anonymous visitors without building it again, with automatic purging, gzip precompression and a status panel that says whether it is working (opt-in, in its own tab)

**2. Put WordPress on a diet (risk-based, opt-in)**

* **Light** (safe for any site): emojis, RSD/WLW tags, shortlinks, self-pingbacks, comment pagination, and more
* **Moderate** (evaluate first): oEmbed, jQuery Migrate, Dashicons on the frontend, Global Styles and Duotone, remote block patterns, avatars and Gravatar, comment threading, and more
* **Strict** (site-specific): granular RSS feed control, Heartbeat API mode, post revisions and autosave, disable comments, XML sitemap, native lazy loading/fetchpriority, content types, selective loading for WooCommerce, Contact Form 7, block assets, Slider Revolution, TablePress, Smash Balloon, Formidable Forms and Everest Forms, and more
* **Widgets**: dashboard widgets (including third-party ones from Yoast, WooCommerce, Elementor, Jetpack, Wordfence, Rank Math, Gravity Forms), classic sidebar widgets, block-editor widgets and the Customizer
* **Emails**: silence the automatic emails WordPress sends on its own, grouped by area: auto-update results for core, plugins and themes (plus the new-version notice), comment moderation and new-comment notices, and new user, password and email-change notices, plus toggles for the admin email verification prompt and post-by-email. Every option is off by default, and critical notices such as a failed core update are always kept

### SCALE, PROFILES AND ANALYZER

* Savings indicator: HTTP requests removed, CSS/JS saved and active optimizations at a glance
* Quick profiles: Personal Blog, WooCommerce Store, Landing Page and Maximum Cleanup
* Site analyzer: personalized recommendations based on your active plugins and content, page cache included
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
* `dietpress_cache_bypass` - Keep the current page out of the page cache
* `dietpress_cache_bypass_cookies` - Adjust the cookie name prefixes that make a visitor uncacheable
* `dietpress_cache_ignored_params` - Adjust the query parameters that do not change the page
* `dietpress_cache_exclude_urls` - Adjust the excluded URL patterns
* `dietpress_cache_post_urls` - Adjust the URLs purged along with a post

**Compatible with:**

* Well-coded themes and page builders (Divi, Elementor, Beaver Builder, Bricks Gutenberg)
* Cache plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, etc.). The one exception is the optional page cache module, which will not run beside another page cache and says so; everything else in DietPress works alongside them as it always has
* Security plugins (DietPress focuses on performance and deliberately leaves security to them; we recommend our free Vigilant)
* CDNs (Cloudflare, StackPath, KeyCDN, etc.) thanks to CORS and Vary headers
* WordPress Multisite (except the optional page cache module, which does not support it yet)

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

= What does a page cache actually do, and do I need one? =

Every time somebody visits your site, WordPress queries the database, runs your plugins, builds the page from your theme and sends it. For a visitor who is not logged in, the result is the same every time until you change something, so doing all of that again is wasted work.

A page cache does it once and stores the finished HTML on disk. The next visitor gets that file, skipping the database, the theme and most of the loading time. It is normally the single biggest speed gain a WordPress site can get, and it is also what keeps a small hosting plan standing up when a post does well on social media.

You need one unless your hosting already provides it. DietPress detects the usual managed hosts and tells you when that is the case.

= How were the speed figures in the description measured, and can I repeat them? =

On a local WordPress 7.0 install with GeneratePress, WooCommerce and a 76 product catalogue, on PHP 8.5. Each URL was requested twice to warm up and then fifteen times, and the median was taken; the parallel figures are 100 requests with ten in flight at a time. The only thing that changed between the two columns was the page cache switch, on the same content, in the same session.

You can repeat it on your own site with any tool that reports time to first byte. From a terminal, `curl -o /dev/null -w "%{time_starttransfer}\n" https://yoursite.com/` a dozen times with the cache off, then a dozen with it on, and compare the medians rather than any single reading. Do it while logged out, because your own visits are never cached.

Two honest caveats. A local install has no network latency, so the absolute milliseconds are lower than you will see in production, where the saving is usually larger rather than smaller. And these numbers say nothing about a Lighthouse score: that measures mostly what the browser does with your theme and your scripts after the page arrives, which is a different problem from how fast your server answers.

= What is critical CSS and why does it matter? =

A browser will not paint anything until it has downloaded every stylesheet in the page. Those files are render-blocking: your visitor stares at a blank screen while they arrive.

Critical CSS is the small subset of rules needed to draw what fits on the screen at first sight. DietPress writes it straight into the page, so the browser can paint immediately and load the rest of the styles afterwards. It is the usual fix for the "eliminate render-blocking resources" warning in PageSpeed Insights, and it improves Largest Contentful Paint, one of the Core Web Vitals Google measures.

= What does deferring JavaScript do? =

By default a script tag stops the browser: it downloads the file, runs it, and only then carries on reading your page. A theme with half a dozen scripts in the head can hold the first paint for a second or more.

Deferring tells the browser to keep building the page and run the scripts once it has finished. Nothing is removed and nothing loads later than it should, it just stops blocking. DietPress handles the dependency order for you and lets you exclude any script that misbehaves, from the settings or with a filter.

= Why should I host Google Fonts on my own server? =

Two reasons. Speed: a font from fonts.gstatic.com needs a fresh DNS lookup, TCP connection and TLS handshake to a domain the browser has never contacted, and browser cache partitioning means the visitor gets no benefit from having downloaded that font on another site. Privacy: serving them from Google transfers your visitor's IP address to Google, which German and other European courts have ruled a GDPR violation.

DietPress downloads the fonts your theme uses to your own uploads folder and rewrites the stylesheets to point there. If anything fails it quietly falls back to Google, so a font never goes missing.

= What is selective loading? =

Plugins tend to load their CSS and JavaScript on every page of your site, whether or not the page uses them. A contact form plugin loads its scripts on every blog post; a slider plugin loads its libraries on pages with no slider; WooCommerce loads its cart on your About page.

DietPress detects which pages actually use each one and removes the rest, for WooCommerce, Contact Form 7, the block library, Slider Revolution, TablePress, Smash Balloon, Formidable Forms and Everest Forms. Pages built with a builder such as Elementor keep everything, because their content is not readable from the database and guessing there is how things break.

= What is browser caching and how is it different from the page cache? =

They cache different things for different people. The page cache stores your HTML on your server, so the next visitor is served it without rebuilding the page. Browser caching tells each visitor's own browser to keep your images, styles, scripts and fonts on their machine, so their second visit downloads almost nothing.

You want both, and they do not overlap. DietPress lets you set how long each family of files is kept: media, styles and scripts, and fonts, each on its own, because the right answer is different for each.

= What does "let browsers keep the HTML for" do, and why is zero the default? =

It decides how long a visitor's browser may reuse the page itself, as opposed to its images and styles. Zero, "Always revalidate", is not the same as sending nothing at all.

With no answer at all the browser invents a lifetime of its own, normally about a tenth of the age of the document, which on an old page can be hours and is entirely out of your hands. With "Always revalidate" the browser asks every time, and the usual answer is a 304 Not Modified of a few hundred bytes, so the page is not downloaded again either: you get almost all of the saving and none of the staleness.

Raise it only for a site that genuinely almost never changes, and be aware of the trade: an edit then takes that long to reach anyone who has already visited, because their browser will not even ask. This one is sent as an HTTP header rather than an .htaccess rule, so it works on nginx too.

= What is the difference between Light, Moderate and Strict? =

Risk, not importance. Light is safe on any site: things nobody misses, like the emoji script or the Windows Live Writer tag. Moderate deserves a look first, because a plugin or theme might use it, oEmbed or jQuery Migrate for example. Strict depends on what your site actually does: disabling comments, feeds or a whole content type is only right if you really do not use them.

Every option says what it does and what might break, and the Scale tab analyses your site and recommends only what applies to you, so you never have to guess.

= What are Core Web Vitals and does this help with them? =

They are the three measurements Google uses to judge how a page feels: how quickly the main content appears (LCP), how quickly the page responds to a click (INP) and how much things jump around while loading (CLS).

DietPress works on all three. The page cache and preloading cut the time to the first byte and to the largest element; deferring JavaScript and trimming what loads leaves the main thread free to answer clicks; and adding width and height to images that lack them stops the layout from shifting as they arrive.

= Is it zero-config? =

Yes, if you want it to be. The performance optimizations are on by default, so you can just activate and go. The difference is that now you can fine-tune everything and, optionally, put WordPress on a diet by disabling features you do not use.

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

= Why is a page not being cached? =

Open DietPress and go to the Cache tab, then press "Test the cache now": the site asks itself for its own home page and tells you whether it was served from disk, rebuilt, or skipped entirely. If you need the exact reason for one particular page, turn `WP_DEBUG` on and read the last line of that page's HTML source: DietPress writes the reason there, for example that the response set a cookie or that a plugin declared the page uncacheable.

The three usual causes are a plugin that sets a cookie on every visit (a consent banner, some analytics scripts), a page that is genuinely personal (cart, checkout, my account, a password protected post), and a cache directory the server cannot write to. The status panel reports the third one on its own.

= I published a change and visitors still see the old page =

Publishing, editing, deleting or renaming a post purges it and everything that lists it, and approving a comment purges the post it belongs to. Changing the theme, the menus, the widgets, the permalinks or the front page settings empties the whole cache, and so does activating or updating any plugin.

If a change made outside WordPress (straight in the database, or by an importer) is not showing, use the purge button on the Page Cache screen. And check with a private window first: DietPress asks browsers not to keep the HTML, but an aggressive browser cache, a CDN or a hosting cache in front of the site are outside its reach.

= Does it work with WooCommerce? =

Yes. WooCommerce marks the cart, the checkout and my account as uncacheable itself, and DietPress obeys that mark rather than guessing from the URL, so it keeps working if you move or rename those pages. On top of that, any visitor carrying a WooCommerce cart or session cookie is served the live site everywhere, so a mini cart in the header never shows somebody else's basket. A store in "coming soon" mode is not cached at all.

= Can I use it together with my other cache plugin, or with my hosting cache? =

With another page cache plugin, no, and DietPress will not let you: two page caches on the same site serve each other's stale HTML and the result is very hard to diagnose. If W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, Cache Enabler, Surge, WP Rocket or any of about thirty similar plugins is active, the toggle stays disabled and says which one. Object cache plugins such as Redis Object Cache are a different thing and are fine.

With a hosting cache (Kinsta, WP Engine, SpinupWP, Cloudways and others) it is possible but rarely a good idea, because purging one does not purge the other. DietPress detects the usual ones and asks for an explicit confirmation before letting you enable it. On LiteSpeed servers we recommend the LiteSpeed Cache plugin instead: caching at server level is faster than anything PHP can do.

= Are logged in users cached? =

Never, and there is no option to change that. The same goes for anyone with a cart, an unlocked password protected post or a comment awaiting moderation.

= Can I customize the optimizations as a developer? =

Yes. See the filters listed in the description (the `dietpress_*` hooks). The page cache adds `dietpress_cache_bypass`, `dietpress_cache_bypass_cookies`, `dietpress_cache_ignored_params`, `dietpress_cache_exclude_urls` and `dietpress_cache_post_urls`, the `dietpress_cache_purge_all` action and the `dietpress_purge_page_cache( $url )` function. Defining `DIETPRESS_DISABLE_CACHE` as true switches the engine off without deactivating anything.

== Screenshots ==

1. Scale tab: savings indicator, quick profiles and site analyzer.
2. Light tab: safe optimizations and cleanup, organized by section.
3. Moderate tab: image, database and editor options to evaluate.
4. Strict tab: frontend performance, server .htaccess rules and site-specific settings.
5. Widgets tab: dashboard, block editor, Customizer and classic sidebar widgets.
6. Emails tab: silence the automatic emails WordPress sends on its own, grouped by updates, comments, users and passwords.
7. Cache tab: page cache settings with master switches and status cards

== Changelog ==

= 3.5.1 =
* Improved: The Cache tab now says out loud when its cleanup is not running. The next cleanup figure tells a run that is due apart from one that is overdue and marks the overdue one, and a note underneath names the likely cause: WordPress cron switched off with no real cron behind it, a site too quiet for WordPress to fire its scheduled tasks at all, or no cleanup scheduled, which the tab itself can put right. While the schedule is being met it says nothing.
* Fix: Pages whose address is not plain ASCII shared a single cached copy. The request path was cleaned with a function that deletes percent encoded characters, and that is exactly how a browser sends every byte of an accented or non Latin permalink, so /café/ and /cafá/ were stored as the same page, and a site whose slugs are written in Cyrillic, Greek, Arabic or CJK collapsed nearly every address onto one entry, the home page included. Languages WordPress transliterates into ASCII by itself, Spanish and French among them, were only affected where a slug had been edited by hand. The cache is emptied once when this version is installed, because a copy stored under the old rules can hold a different page altogether and there is no way to tell which from the file.
* Fix: A URL exclusion pattern containing an accent or any non Latin character never matched anything, for the same reason: those characters were stripped when the pattern was saved. Both spellings work now and mean the same thing, the one the address bar shows and the one the clipboard usually holds.
* Fix: Renaming or deleting a category or a tag left its old archive cached and still being served. It is the case renaming a post already handled: once the slug has changed, nothing can name the address the old page lived at, and nothing would ever rebuild it either.
* Fix: Editing what a widget says purged nothing, so every page showing it kept the old text until it expired. Only adding, moving or removing a widget purged. Block based widgets are covered too, since they all share one option.
* Fix: The browser caching rules handed HTML pages the lifetime chosen for media. The rule that covers every file type without one of its own covers the page itself too, and there was no rule for HTML. On an anonymous visit the Cache-Control header the plugin sends overrode it, which is why it went unnoticed; on a page viewed while logged in that header is deliberately not sent, and there a page could sit in the browser for a month. HTML now follows the lifetime chosen for it in the Cache tab.
* Fix: The query parameters that may never be ignored treated filter_ as a whole name rather than a prefix, so a WooCommerce layered navigation parameter such as filter_color could be added to the ignore list, and a filtered shop page was then answered with the unfiltered one.
* Fix: The next cleanup figure on the Cache tab could not tell a late cleanup from a coming one. It printed a bare time difference, and the WordPress function behind it carries no direction, so a cleanup that had run two days late and one due in two days both read as 2 days. The one figure that could have revealed a cron nobody was running was the one hiding it.

= 3.5.0 =
* New: Page cache. DietPress can now store a static copy of each page on disk and serve it to anonymous visitors without building the page again, from its own Cache tab in the DietPress settings, next to the diet levels, which also gathers the browser caching rules that used to live under Strict. It is off by default, it installs no drop-in file and it never writes to wp-config.php, so switching it off leaves the site exactly as it was. Logged in visitors, anyone carrying a cart, a password protected post and a comment awaiting moderation always get the live page. Every response carries an X-DietPress-Cache header, and every stored page a footprint comment, so a hit and a miss can be told apart at a glance.
* New: Browser caching is now configurable rather than fixed: how long browsers keep your media, your styles and scripts, your fonts and the HTML itself, each on its own, plus a switch for the ETag header. Browser caching and compression also get a master switch each, so you can run one without the other; a site updating from 3.4.x keeps whatever its single switch said. The Cache-Control headers are built from those same lifetimes instead of a hardcoded year, which is a fix in itself: they used to contradict the Expires rules, and browsers obey Cache-Control when the two disagree. Only styles, scripts and fonts are marked immutable now, because they carry a version in their URL; an image keeps its URL when you replace it. The HTML lifetime is sent as an HTTP header instead of an .htaccess rule, which is a fix too: the old rule only matched files whose name ends in .html, so it never applied to a WordPress permalink at all.
* New: The Cache tab reports what the cache is really doing instead of just offering switches: how many pages and how much disk it holds, when the cleanup last ran and when it runs next, and a Test the cache now button that asks the site for its own home page, as an anonymous visitor would, and says whether it was served from disk, rebuilt, or skipped. The same status block appears on the Scale tab next to the savings, the site analyzer gained a Cache section that covers the engine, the gzip copy and the browser caching rules, and the quick profiles switch the cache on with a lifetime that suits each one, six hours for a store and twelve for the rest. Purging accepts a title as you type and fills in the right URL for you. With WP_DEBUG on, a page that is not being stored names the exact reason in an HTML comment at the end of its source.
* New: Invalidation happens on its own for the things that change a page. Publishing, editing, deleting or renaming a post purges it along with the front page, the blog page, its archives and its author page, and renaming a slug also purges the address it used to live at. Approving, editing or deleting a comment purges its post. Changing the theme, the menus, the widgets, the permalinks or the front page settings, and activating or updating any plugin, empty the cache completely. There is also a purge button, a field to purge one URL, the dietpress_cache_purge_all action and the dietpress_purge_page_cache() function.
* New: The cache refuses to run beside another one instead of fighting it. With W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, Cache Enabler, Surge, WP Rocket or any of about thirty similar plugins active, the toggle is disabled and says which one is in the way, and if one of them is activated later DietPress switches its own cache off and tells you. On hosting that already serves a page cache, such as Kinsta, WP Engine, SpinupWP or Cloudways, it explains the risk and asks for an explicit confirmation. On LiteSpeed servers it points at the LiteSpeed Cache plugin, which caches at server level. Multisite is not supported yet and says so rather than misbehaving.
* Fix: Activating a conflicting cache plugin from WP-CLI, or from any code that activates plugins outside the dashboard, did not switch the page cache off. The check only listened while an admin screen was loading, so a host control panel or a staging script could leave two page caches running at once, which is the exact situation the check exists to prevent.
* Fix: An option held back by another one lost its saved value on the next save. The switches that depend on a master switch, the five RSS feed ones and the compression ones among them, were drawn from whether they apply right now rather than from what you had chosen, so while the master was off they rendered as off, and saving wrote that over your real preference. Turning the master back on then restored nothing. A card now always shows what is stored, and the note underneath is what says whether it can do anything yet.

For older changelog entries, please check the [changelog.txt](https://plugins.svn.wordpress.org/wpo-tweaks/trunk/changelog.txt) file.

== Upgrade Notice ==

= 3.5.1 =
Fixes the cache key for addresses that are not plain ASCII, which made accented and non Latin permalinks share one cached copy. Also purges category, tag and widget edits that were being missed, and stops the browser rules handing HTML the media lifetime. The cache is emptied once on update.

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
