=== Juliepr MSLS Helper ===
Contributors: sashmarin
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0

Extends Multisite-Language-Switcher (https://github.com/lloc/Multisite-Language-Switcher/) with shared media,
source date/slug/author copying, imported link rewriting, 302 redirects to original content and daily orphaned option cleanup.


== Description ==

Juliepr MSLS Helper replaces the Featured Image Linking importer in Multisite
Language Switcher. It assigns the source post's attachment ID to the destination
post without creating a local attachment or copying image files.

A shared media library plugin (for example https://github.com/humanmade/network-media-library/)
must resolve the same attachment ID on both sites.
MSLS Content Import must be enabled for featured image linking. Quick Create
(Add from Translation) also copies the source publication date, slug and author to the
new draft. Content Import copies the same fields for a destination draft created
during the import request, preserving dates, slugs and authors of previously existing posts
and drafts. Other importers retain their existing behavior.

Russian metadata translations use the native .l10n.php format. No .mo file is included.

== Network Settings ==

Open Network Admin -> Settings -> MSLS Helper.

* Enable global media library integration: enabled by default. Disable to use
  the standard MSLS featured image importer.
* Copy the source date, slug and author: enabled by default. Controls both new
  translation creation paths and the helper's publication date display adjustment.
* Rewrite imported links to the translation site: enabled by default. Controls
  text link rewriting in both Quick Create and Content Import.
* Redirect missing translations to the source site: enabled by default.
* Source site ID for 404 redirects: defaults to 1. Choose an active site in this
  network. This value only controls the 404 fallback.
* Clean up orphaned MSLS options daily: enabled by default. Processes all
  active sites in this network in batches through WP-Cron.
* Delete settings when uninstalling the plugin: disabled by default. Deletes
  only the helper's network settings when the plugin is removed. Deactivation,
  posts, translation links and media are unaffected.

Settings apply to all sites in the current network. Only users with permission
to manage network options can save them. The page is available without MSLS.
Each network has its own settings and uninstall preference.

== Imported Text Links ==

Quick Create and the standard post-fields Duplicating importer replace the
source site with the destination site in absolute HTTP(S) and protocol-relative
anchor href URLs. Paths, query parameters and fragments are preserved.
Subdirectory site home paths are mapped to the destination home path.

Paths containing /wp-content/uploads/ are excluded, including image links and
PDF links. Image src/srcset, block comments, external links, relative links and
plain-text URLs remain unchanged. Translation existence is not checked.

This applies whenever text is imported, including into an existing draft or
post. Skipping post-fields import preserves existing content. Source posts are
unchanged. This behavior has its own network setting and is independent of the
media integration and source date/slug/author settings.

== Missing Translation Fallback ==

Frontend GET/HEAD requests returning 404 are checked against the configured
source site. If an exact path matches a published, publicly viewable post or
page, redirect with HTTP 302 to its permalink, retaining query parameters.
Works with subdomain and subdirectory networks. It applies to any qualifying
404, independently of imported link rewriting or MSLS availability.

The source site itself, uploads, WordPress service paths, previews, feeds, REST
requests and post-ID query requests are excluded. Missing or unpublished
originals leave the 404 unchanged. The source must be an active site in this
network. The helper does not regenerate missing rewrite rules during a lookup.

== Orphaned MSLS Options ==

Daily cleanup inspects only options named exactly msls_NUMBER or
msls_term_NUMBER. It deletes the option if its local post or term is absent.
For an existing owner, it removes individual language references whose post or
term no longer exists on the destination site. An array emptied by this pruning
is deleted; valid remaining references retain their original values.

Languages are resolved to active sites in the current network by WPLANG, using
MSLS's en_US default for an empty locale. Unknown languages, duplicate locales,
malformed values and non-array options with existing owners are preserved.
Existing drafts, private posts and trashed posts count as valid objects.
Shared attachment records on other sites do not count as local owners.

Whole-option deletion uses MSLS Post/Tax delete() where available, with a
WordPress Options API fallback. Partial arrays use update_option() to preserve
values and autoload settings; MSLS save() would delete and recreate the row.
WordPress object caches are invalidated through these APIs.

The network's main site hosts the daily WP-Cron event. Each invocation processes
one active site and up to 100 MSLS option candidates; further batches run at least a
minute apart. Deleted, archived and spam sites are skipped. The first run is
scheduled one minute after the next request with the plugin enabled. WP-Cron
needs traffic on the network's main site or an external cron runner targeting
that site, so these are scheduled times.
Database errors stop a batch rather than treating owners or translations as missing.

Disabling cleanup removes its scheduled events and progress. Deactivation and
uninstall also remove this runtime state, while saved settings follow the
uninstall preference. Cleanup works without MSLS being active.

On a disposable multisite, run wp eval-file tests/orphaned-options.php from
this directory. This test creates disposable fixtures and performs real option writes.

== Installation ==

1. Copy juliepr-msls-helper into wp-content/plugins/ as a standalone plugin.
2. Network activate it alongside MSLS and your shared media library plugin.
3. Enable MSLS Content Import and select Featured Image -> Linking when importing.

== Frequently Asked Questions ==

= Does it respect the option to skip featured image import? =

Yes. If the source has no featured image, the destination image is also preserved.

= Does it affect Add from Translation / Quick Create? =

Yes. New translations copy post_date, post_date_gmt, post_name and post_author from the source
and remain drafts. This requires MSLS with the msls_quick_create_post_data hook.
WordPress may add a suffix to an occupied slug when publishing. Dates are copied
verbatim; matching site timezones are recommended.
The source author's network user ID is retained without adding site membership
or changing user roles.
The modification date is set to the current time so Gutenberg can display the
copied publication date before the first manual save. If both timestamps match
to the second, Gutenberg may still display Immediately until a later save.

= Does Content Import copy the source date, slug and author? =

Yes, when the destination draft is created during the import request. Importing
into a previously existing post or draft preserves its date, slug and author. This does
not depend on the selected post-fields importer.

== Changelog ==

= 1.1.0 =
* Clean up orphaned msls_NUMBER and msls_term_NUMBER options daily across active network sites.
* Remove references to missing translations, preserve valid links and delete emptied options.
* Process up to 100 MSLS options per WP-Cron batch and resume from a saved cursor.
* Add an enabled-by-default network setting; clear scheduled cleanup and progress on disable, deactivation or uninstall.

= 1.0.0 =
* Add a configurable source site and optional HTTP 302 fallback for missing translations.
* Add a separate enabled-by-default switch for imported text link rewriting.
* Rewrite imported text links to the destination site while preserving uploads URLs.
* Add network settings for both integrations and optional settings cleanup on uninstall.
* Copy the source publication date and slug during Quick Create.
* Copy the source date and slug to drafts created during Content Import.
* Retain the source author for new translations in both creation paths.
* Use the current modification time to show the copied date in Gutenberg.
* Reuse source featured image IDs for shared network media libraries.
* Include Russian translations in the native PHP translation format.
