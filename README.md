# AdFree Simple History

**A professional, ad-free WordPress activity log for what matters.**

AdFree Simple History is a clean, fully functional fork of the popular WordPress Simple History plugin. Simple History is an excellent audit log utility that tracks various events occurring in WordPress and presents them in an easy-to-read graphical interface.

This repository was created to provide a lightweight, distraction-free version of the plugin, stripping away the promotional content, restricted features, and intrusive menu placements introduced in recent versions of the main branch.

## Why This Fork?

While the original Simple History plugin is a brilliant tool, recent updates to the main branch (`bonny/WordPress-Simple-History`) have heavily monetized the interface, introducing artificial limitations and dashboard clutter. This fork is designed for agencies, developers, and publishers who just want a quiet, reliable logging tool.

**Key Differences from the Main Branch:**

1. **No Advertising or Nagware:** We have removed all promotional banners, premium upsells, and subscription nags.
2. **Respectful Menu Placement:** The plugin no longer aggressively steals a top-level spot in your left-hand WordPress admin menu. It now appears neatly tucked under the site name drop-down, which is a natural fit that keeps your dashboard clean.
3. **Restored User Links:** In the main branch, hovering over a user name shows a blurred-out popup box directing you to a premium sales page. We have removed this and restored a functional, working link to view all user activity.
4. **Unrestricted Log Retention:** We removed the hardcoded script that limits log retention to 30 days for new installs (or 60 days for existing installs). You can once again set your preferred log retention duration in the Hidden Settings section.

## Version Information

This fork is actively maintained by Foliovision and originated from the `bonny/WordPress-Simple-History` repository.

* **Current AdFree Release:** v5.28.0

## Installation

1. Download the latest `.zip` release from this repository.
2. Navigate to **Plugins > Add New** in your WordPress admin dashboard and upload the zip file.
3. Activate the plugin.
4. Access your activity logs conveniently under your site name menu in the top admin bar.

## Release Notes

### v5.28.0 (AdFree Update)

* **Removed:** Stripped out all promotional dashboard advertisements and premium nag screens.
* **Changed:** Relocated the Simple History navigation link from the primary left-hand admin menu to the drop-down under the Site Name to preserve valuable screen real estate.
* **Fixed:** Replaced the blurred-out premium sales popup on user-hover with a functional link that directly displays the user's activity history.
* **Unlocked:** Restored the ability to set custom log retention durations via the Hidden Settings, removing the forced 30-day/60-day limits.

## Developer API

The standard Simple History API remains fully intact. Developers can easily log custom events using the following methods:

```php
// The safest way to add messages to the log
do_action('simple_history_log', 'This is a logged message');

// Or with context and a specific log level:
do_action(
	'simple_history_log',
	'My message about something',
	[
		'debugThing' => $myThingThatIWantIncludedInTheLoggedEvent,
	],
	'debug'
);

// Add events of different severities using the helper function
SimpleLogger()->info("User admin edited page 'About our company'");
SimpleLogger()->warning("User 'Jessie' deleted user 'Kim'");
SimpleLogger()->debug("Ok, cron job is running!");

```

---

*Maintained by [Foliovision](https://foliovision.com/).*
