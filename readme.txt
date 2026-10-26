=== Site Toolkit for Bricks ===
Tags: bricks, dynamic-content, login, admin, comments
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Global dynamic fields for Bricks, custom login styling and URL, WordPress admin cleanup, comments management and post revision limits.

== Description ==

Site Toolkit for Bricks is a modular set of tools for building and maintaining client websites. It provides global values that can be inserted in Bricks using dynamic data tags. WordPress-only tools also work without Bricks.

* Global business details: logo, company, email, telephone and address.
* Up to 100 custom global fields with text, textarea, email, phone, URL, number or media-library image values.
* Native Bricks dynamic tag integration and [bst_field] shortcode support.
* Login page customization: logo, background, form and button colors.
* Optional custom login path that hides the default wp-login.php endpoint on supported single-site installs.
* Hide selected WordPress admin menu items (visual hiding only).
* Disable native comments, pingbacks and the WordPress comment-management interface.
* Separate confirmation-based bulk actions to permanently delete spam or all comments.
* Limit how many post revisions WordPress keeps.

Features can be switched on or off independently. No separate ACF plugin is required.

== Installation ==

1. Install the GitHub release ZIP through Plugins > Add New > Upload Plugin.
2. Activate Site Toolkit for Bricks.
3. Open Site Toolkit in the WordPress administration menu.
4. Enable and configure the features you need.

For older Bricks Site Toolkit 1.0.x and 1.1.x ZIP installations: deactivate the legacy plugin first, then install and activate this package. Both plugins use the same saved WordPress option and the same `{bst_*}` tag names. Do not run both plugins simultaneously. Deleting the legacy plugin does not delete its saved option.

== Frequently Asked Questions ==

= Is Bricks required? =

No. Bricks is required only to display the dynamic tags in the Bricks editor. Global fields are also available through shortcodes.

= Which dynamic tags are available? =

`{bst_logo}`, `{bst_logo_url}`, `{bst_company}`, `{bst_email}`, `{bst_email_url}`, `{bst_phone}`, `{bst_phone_url}`, `{bst_address}` and `{bst_your_custom_key}`. Logo tags work with Bricks image fields, and the shortcode `[bst_field key="phone"]` works outside Bricks.

= Does disabling comments delete previous comments? =

No. The comments module disables native comments and hides their WordPress admin controls. Permanent deletion is a separate confirmed action on the Comments settings page. Deleting ALL comments includes WooCommerce product reviews, spam, pingbacks and metadata. Back up your database first.

= Does hiding admin menu items restrict permissions? =

No. Hiding items changes navigation only. Use WordPress capabilities and roles to restrict what a user can access.

= Can the login path change on multisite? =

No. This feature is single-site only. Do not use it alongside WPS Hide Login or another conflicting login-URL plugin. After enabling, remember your new address and test it in a private browser window. Exclude the login path from page caches. To recover access, temporarily deactivate the plugin through FTP or your hosting file manager. Changing the URL is not a replacement for 2FA or rate limiting.

= Do revision limits remove old revisions? =

No. The configured limit is used when WordPress saves posts and does not immediately delete historical revisions.

= Are updates delivered automatically? =

This edition gets updates exclusively from the public GitHub repository https://github.com/JackFrydrych/site-toolkit-for-bricks. It does not use WordPress.org updates. Once a newer non-prerelease GitHub Release is published with the asset `site-toolkit-for-bricks.zip`, WordPress will show the new version under Plugins. Automatic installation is optional and controlled by the site administrator.

The Release ZIP must contain exactly one root directory called `site-toolkit-for-bricks`, with the main plugin file inside it. Don't attach the auto-generated GitHub source ZIP to updates. The updater intentionally refuses a release without the correctly named installable ZIP.

== Changelog ==

= 1.3.1 =
* Test release to verify the native GitHub update process from version 1.3.0.
* No feature or settings changes.


= 1.3.0 =
* Integrated the WordPress Update URI filter with stable public GitHub Releases.
* Update detection, native one-click/automatic installations, cached release API and plugin information dialog.
* Requires an attached `site-toolkit-for-bricks.zip` release asset; does not accept GitHub source ZIPs.
* No change to existing global fields, Bricks tags, login URL or user settings.


= 1.2.0 =
* Prepared for WordPress.org distribution with an updated, brand-compliant plugin name, directory and text domain.
* Retained all saved setting keys and dynamic tags from the earlier releases.
* Safeguard against simultaneous loading with the legacy ZIP release.
* WordPress core handles updates after the plugin is accepted and released in the official directory; no third-party update service is required.

= 1.1.0 =
* Added an optional custom login URL for single-site installations.
* Added confirmed, batched deletion of spam comments or all comments.
* Replaced native browser color inputs with the WordPress color picker.
* Fixed Add Field icon alignment.

= 1.0.0 =
* Initial release with dynamic data, custom fields, login styling, menu cleanup, comments module and revision limit.
