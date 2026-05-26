=== AdFree Simple History ===
Contributors: foliovision, aleckinnear
Tags: simple history, activity log, audit log, history, user log
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 5.28.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A professional, distraction-free WordPress activity log. Tracks user edits and login attempts without the ads, nags, or dashboard clutter.

== Description ==

AdFree Simple History is a clean, fully functional fork of the popular WordPress Simple History plugin by Pär Thernström (you can learn more about the original project at [simple-history.com](https://simple-history.com/)). Simple History is an excellent audit log utility that tracks various events occurring in WordPress and presents them in an easy-to-read graphical interface. 

This repository was created to provide a lightweight, distraction-free version of the plugin, stripping away the promotional content, restricted features, and intrusive menu placements introduced in recent versions of the main branch.

For more background on why this fork exists and the history of these changes, please see our Foliovision articles:
* [Ad free Simple History WordPress Plugin (May 2025)](https://foliovision.com/2025/05/adfree-simple-history-plugin)
* [Simple History plugin review | free Simple History Premium (May 2026)](https://foliovision.com/2026/05/simple-history-free)

### Why This Fork?

While the original Simple History plugin is a brilliant tool, recent updates to the main branch have heavily monetized the interface, introducing artificial limitations and dashboard clutter. This fork is designed for agencies, developers, and publishers who just want a quiet, reliable logging tool. 

**Key Differences from the Main Branch:**

1. **No Advertising or Nagware:** We have removed all promotional banners, premium upsells, and subscription nags.
2. **Respectful Menu Placement:** The plugin no longer aggressively steals a top-level spot in your left-hand WordPress admin menu. It now appears neatly tucked under the site name drop-down, which is a natural fit that keeps your dashboard clean.
3. **Restored User Links:** In the main branch, hovering over a user name shows a blurred-out popup box directing you to a premium sales page. We have removed this and restored a functional, working link to view all user activity.
4. **Unrestricted Log Retention:** We removed the hardcoded script that limits log retention to 30 days for new installs (or 60 days for existing installs). You can once again set your preferred log retention duration in the Hidden Settings section.

### Developer API

The standard Simple History API remains fully intact. Developers can easily log custom events using the following methods:

`do_action('simple_history_log', 'This is a logged message');`

Or with context and a specific log level:

`do_action(
	'simple_history_log',
	'My message about something',
	[
		'debugThing' => $myThingThatIWantIncludedInTheLoggedEvent,
	],
	'debug'
);`

== Installation ==

1. Download the latest `.zip` release of AdFree Simple History.
2. Navigate to **Plugins > Add New** in your WordPress admin dashboard and upload the zip file.
3. Activate the plugin.
4. Access your activity logs conveniently under your site name menu in the top admin bar.

== Frequently Asked Questions ==

= Is this compatible with the original Simple History? =
Yes, it functions identically under the hood and hooks into the same WordPress core events. The primary difference is the removal of advertisements and artificial feature restrictions.

= Will I lose my old logs if I switch from the main branch to this fork? =
No. Because this is a direct fork, it reads the same database tables. Your historical logs will remain intact. 

= Will this plugin remain free? =
Yes. This fork is strictly open-source, maintained by Foliovision for the community, and will never contain premium upsells.

== Screenshots ==

1. The ad-free interface neatly tracking user activity.
2. The restored Hidden Settings panel where you can choose your own log retention duration.
3. The plugin respectfully tucked under the site name menu instead of cluttering the left sidebar.
4. Working user activity links instead of blurred premium popups.

== Changelog ==

= 5.28.0 =
* Removed: Stripped out all promotional dashboard advertisements and premium nag screens.
* Changed: Relocated the Simple History navigation link from the primary left-hand admin menu to the drop-down under the Site Name to preserve valuable screen real estate.
* Fixed: Replaced the blurred-out premium sales popup on user-hover with a functional link that directly displays the user's activity history.
* Unlocked: Restored the ability to set custom log retention durations via the Hidden Settings, removing the forced 30-day/60-day limits.
