=== WordPress WPO Tweaks & Optimizations ===
Contributors: fernandot
Donate link: https://www.paypal.me/fernandotellado
Tags: wpo, optimization, optimisation, gzip, browser cache, cache expires, compression, speed, query strings, heartbeat api, json, rest api, json api, jquery_migrate, emoji, query strings, dashicons, pagespeed, pingdom, gtmetrix 
Requires at least: 4.8
Requires PHP: 7.4
Tested up to: 6.7.1
Stable tag: trunk
License: GPLv2+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WPO Optimizations, Improvements and Tweaks to Speed Up WordPress

== Description ==

By default, WordPress load several functions, services and scripts that aren’t mandatory and usually slow down your installation and waste hosting resources. From years I’ve been trying out some tweaks to save hosting resources and improve WordPress Performance and Load Time. After thousands of tests this plugin include my best speed and performance optimizations with just one click.

With this plugin you can safely deactivate that annoying services, unnecessary codes and scripts in order to save hosting resources and costs, and to speed up WordPress for better results in tools like Google PageSpeed, Pingdom Tools, GTMetrix, WebPageTest and others.

The improvements (tweaks) that the plugin automatically applies securely are the following:

* <strong>NEW:</strong> Added browser cache expires rules to main WordPress .htaccess file
* <strong>NEW:</strong> Added GZIP compression rules to main WordPress .htaccess file
* Remove Dashicons in admin bar (only for non logged users)
* Remove Emoji’s styles and scripts
* Disable REST API (full disabled)
* Control Heartbeat API interval
* Remove Query Strings from Static Resources
* Defer Parsing of JavaScript and YouTube videos iframes
* Remove Gravatar Query Strings
* Remove Really Simple Discovery link from header
* Remove wlwmanifest.xml (Windows Live Writer) from header
* Remove Shortlink URL from header
* Remove WordPress Generator Version from header
* Remove s.w.org DNS Prefetch
* Remove unnecessary links from header
* Remove generator name from RSS Feeds
* Remove Capital P Dangit filter
* Disable PDF thumbnails preview
* Disable Self Pingbacks

<strong>No options</strong>. Just activate the plugin and test your site’s speed in your favourite tool (GTMetrix, Pingdom Tools, etc.)

== Plugin Requirements ==
* This plugin requires WordPress 4.8 or greater
* This plugin requires PHP 7.3 or greater


== Installation ==

1. Go to your WP Dashboard > Plugins and search for ‘wpo tweaks’ or…
2. Download the plugin from WP repository
3. Upload the ‘wpo-tweaks’ folder to the '/wp-content/plugins/' directory
2. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= What does WPO mean? =

WPO is short for Web Performance Optimization. It measures a bunch of several improvements in the optimization and improvement of the performance and times of load of web pages

= Where can I test my site Performance? =

* Go to [Google PageSpeed](https://pagespeed.web.dev/) and test your site.
* Go to the [Pingdom Tools](https://tools.pingdom.com/) and test your site.
* Go to the [GTMetrix](https://gtmetrix.com/) and test your site.
* Go to the [WebPageTest](https://www.webpagetest.org/) and test your site.

= What is the best way to test my site performance? =

Use one of the tools above and make al least two tests to measure your site performance. That’s because the cache systems don’t load the first time your site is tested with this tools. Always test your site with the same tool and measure your site performance over time, not just only one time.

And always remember that no tool can replace human perception. If you see that your web loads faster than ever no tool is going to tell you what you and your visitors feel in real life.

Don’t go crazy with tools, they’re machines and, i.e. Google PageSpeed can show you a 100/100 measure when your site is broken, and that’s far away from an optimised web, isn’t it? 
  
= Something went wrong after activation =

This plugin is compatible with all WordPress JavaScript functions (`wp_localize_script()`, js in header, in footer...) and works with all well coded plugins and themes. If a plugin or a theme is not properly enqueuing scripts, your site may not work. If your host doesn’t support any of the tweaks, usually due to security restrictions, is possible that something fails. If anything fails please access to your <code>/wp-content/plugins/wpo-tweaks/</code> directory via your favourite FTP client or hosting panel (cPanel, Plesk, etc.) and rename the plugin folder to deactivate it.

If you get an Error 500 (server error) then go to your host panel and edit the .htaccess file to remove the lines added by the plugin (they begin with 'WordPress WPO Tweaks by Fernando Tellado') and save changes or delete the file and create it again from Dashboard > Settings > Permalinks > Save Changes.

= What’s next? =

I’ll be including for next updates every new performance tweak I test for better results in order to speed out WordPress.

= Do you plan to include a settings panel? =

No. WordPress WPO Tweaks plugin is intended for users that want to safely obtain optimizations and speed with one click. If you are a developer and know what you're doing, then please check my friend [Nilo Velez's Machete plugin](https://wordpress.org/plugins/machete/), a complete suite to decide how to solve common WordPress issues and annoyances. And yes, it has a huge settings page!

== Screenshots ==

1. Pingdom Tools results before plugin activation.
2. Pingdom Tools results after plugin activation.

== Changelog ==
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
* Updated requisites for WP and PHP

= 1.0.1 =
* Tested up to WordPress 6.0.2

= 1.0 =
* Tested up to WordPress 6.0
* Yeah! It was time to bump tu 1.x version!

= 0.9.31 =
* Change PageSpeed URL

= 0.9.30 =
* Tested up to WordPress 5.9

= 0.9.29 =
* Tested up to WordPress 5.8

= 0.9.28 =
* Tested up to WordPress 5.6

= 0.9.26 =
* Removed the jQuery Migrate option because it's not needed since WordPress 5.5

= 0.9.25 =
* Tested up to WordPress 5.5

= 0.9.24 =
* Regression to old method for defer parsing of JavaScript due to Divi theme support issues.

= 0.9.23 =
* Tested up to WordPress 5.4.1

= 0.9.22 =
* New method for defer parsing of JavaScript. Specially useful with YouTube iframes and other external video sources.

= 0.9.21 =
* Tested up to WordPress 5.3.2

= 0.9.20 =
* Changes in the jQuery Migrate code to solve Elementor last version support

= 0.9.19 =
* Tested up to WordPress 5.3

= 0.9.18 =
* Tested up to WordPress 5.2.2

= 0.9.17 =
* Tested up to WordPress 5.2.

= 0.9.16 =
* Tested up to WordPress 5.1

= 0.9.15 =
* Added conditionals to deflate lines in .htaccess file to prevent error 500 in some hosts (Props to frayca - https://profiles.wordpress.org/frayca)

= 0.9.14 =
* Tested up to WordPress 5.0 tag added

= 0.9.13 =
* Tested up to WordPress 4.9.8

= 0.9.12 =
* Added functions to disable internal self pingbacks

= 0.9.11 =
* Added line to check if "expires" module is active to prevent error 500 in some servers where module isn't active.

= 0.9.10 =
* Tested up to WordPress 4.9.6

= 0.9.9 =
* Added Browser cache expires rules to main WordPress .htaccess file. It's a pretty secure procedure because the plugin first check if the .htaccess file exists and it's writable, and if there aren't previous WPO Tweaks Plugin rules too. The rules added by the plugin are removed at plugin deactivation. Props to @carloslongarela.
* Added GZIP compression rules to main WordPress .htaccess file. It's a pretty secure procedure because the plugin first check if the .htaccess file exists and it's writable, and if there aren't previous WPO Tweaks Plugin rules too. The rules added by the plugin are removed at plugin deactivation. Props to @carloslongarela.
* This is the first release with version tags. This way you can download previous versions of the plugin. They are at the bottom of Advanced View of the plugin page at wordpress.org.
= 0.9.8 =
* Tested up to WordPress 4.9.5. It works!
= 0.9.7 =
* Exception added Dashicons removal in order to show them in the Customizer (Thanks to Juan Ramón Navas for reporting!)

= 0.9.6 =
* Changed method for Hearbeat API because some users need it for autosaves, co-edits and more. From now it isn't disabled but controlled the interval to trigger after 60 seconds instead of default 15 seconds. 
* Credits added to admin footer.
* Plugin tested up to WordPress 4.9 RC.

= 0.9.5 =
* Added link to WebPageTest in readme.txt to measure results.
* Changed to the <code>script_loader_tag</code> filter method to Defer Parsing of JavaScript in order to solve AMP issues with Google's CDN and parsing of scripts in several themes.

= 0.9.4 =
* Changed method to Defeat Parsing of JavaScript to solve AMP issues with Google's CDN (thanks to Juan María Arenas for reporting!)
* Changed Donate URL

= 0.9.3 =
* Changed WP min version from 4.0 to 4.1
* Added filter to remove capital_p_dangit filter
* Added functions to disable PDF thumbnails preview (included in WP 4.7)
* Added action to remove link to homepage from header (thanks @carloslongarela)
* Added action to remove extra links to rss feeds from header (thanks @carloslongarela)
* Added action to remove prev-next links from header (thanks @carloslongarela)
* Added action to remove prev-next links from header (thanks @carloslongarela)
* Added action to remove random post link from header (thanks @carloslongarela)
* Added action to remove parent post link from header (thanks @carloslongarela)
* Added filter to remove generator name from rss feeds in header (thanks @carloslongarela)
* Better coding standards and ordering of functions (thanks @carloslongarela)
* Changes in readme.txt


= 0.9.2 =
* Added action to remove really simple discovery link from header
* Added action to remove wlwmanifest.xml from header
* Added action to remove shortlink url from header
* Added action to remove WordPress generator version from header
* Added action to remove s.w.org DNS prefetch
* Added function to remove jquery_migrate

= 0.9.1 =
* Fixed Text Domain for translation ready

= 0.9 =
* Initial release
