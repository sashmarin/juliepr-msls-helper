# Juliepr MSLS Helper

Extends Multisite-Language-Switcher (https://github.com/lloc/Multisite-Language-Switcher/) with shared media, source date/slug/author copying, imported link rewriting, 302 redirects to original content and daily orphaned option cleanup.


## Requirements

- WordPress Multisite 6.5 or later and PHP 7.4 or later.
- Multisite Language Switcher with Content Import enabled.
- A shared media library plugin (for example https://github.com/humanmade/network-media-library/)
  that resolves the same attachment ID on both the source and destination sites.

## Installation

1. Copy the `juliepr-msls-helper` directory into `wp-content/plugins/` as a
   standalone plugin, alongside MSLS.
2. Network activate **Juliepr MSLS Helper**, MSLS, and your shared media library plugin.
3. When importing content through MSLS, select **Featured Image → Linking**.

## Network settings

Open **Network Admin → Settings → MSLS Helper**. Settings apply to all
sites in the current network and can only be changed by a user with permission
to manage network options.

- **Enable global media library integration** — enabled by default. Turning it
  off restores the standard MSLS featured image importer.
- **Copy the source date, slug and author** — enabled by default. Turning it off
  disables helper field copying in both Quick Create and Content Import, along
  with the helper's publication date display adjustment.
- **Rewrite imported links to the translation site** — enabled by default.
  Controls text link rewriting in both Quick Create and Content Import.
- **Redirect missing translations to the source site** — enabled by default.
  Sends an HTTP 302 redirect for missing content when the original exists.
- **Source site ID for 404 redirects** — defaults to `1`. Choose an active site
  in the current network; this setting only controls the 404 fallback.
- **Clean up orphaned MSLS options daily** — enabled by default. Processes all
  active sites in this network through WP-Cron.
- **Delete settings when uninstalling the plugin** — disabled by default.
  When enabled, deleting the plugin removes only its network settings. Posts,
  translation links, and media remain intact. Deactivation preserves settings.

The page remains available when MSLS is inactive. On installations with multiple
networks, each network has its own settings and uninstall preference.

## Behavior

With global media library integration enabled, the helper replaces the MSLS
featured image importer and writes the source post's
`_thumbnail_id` directly to the destination post. It creates no attachment records
or image files and replaces an existing destination featured image when the source
has one.

Selecting “Do not import” is respected. If the source has no featured image,
the destination featured image is preserved. Without MSLS, the import handler
is not registered.

With field copying enabled, Quick Create (“Add from Translation”) copies the source post's publication date
(`post_date` and `post_date_gmt`), slug (`post_name`), and author (`post_author`)
into the new translation. The source author's network user ID is retained;
the helper does not add that user to the destination site or change their roles.
The translation remains a draft. WordPress may add a suffix to an occupied slug
when publishing. Dates are copied verbatim, so matching site timezones are
recommended for consistent local/UTC date interpretation. This requires MSLS
with the `msls_quick_create_post_data` hook.

For these new translations, the modification date is set to the current time
while the publication date is retained. Gutenberg infers an unset publication
date when a draft's publication and modification dates match; keeping them
separate lets it display the copied date immediately, before the first manual
save. A source date equal to the creation time (to the second) may still display
“Immediately” until a subsequent save.

Content Import also copies these four fields when the destination draft was
created during the same request. Importing into a post or draft that already
existed before the request preserves its date, slug, and author. The helper tracks new
draft IDs in memory, separately for each site; it stores no persistent metadata.
This works independently of the selected post-fields importer.

Other MSLS importers, including the separate attachments importer, retain their
existing behavior.

## Imported text links

Quick Create and the standard Content Import post-fields Duplicating importer
rewrite absolute HTTP(S) and protocol-relative `<a href>` URLs from the source
site to the destination site. Paths, query parameters, and fragments are
preserved; home paths are mapped for subdirectory networks.

URLs whose decoded path contains `/wp-content/uploads/` are excluded, including
links around images and links to PDFs. Image `src`/`srcset`, block comments,
external URLs, relative URLs, and plain-text URLs are unchanged. Translation
existence is not checked during import. The separate 404 fallback below can
redirect requests to existing original content.

This runs whenever text is imported, including into existing destination posts.
Selecting no post-fields import does not rewrite existing content. The source
post is not modified. Link rewriting has its own network setting and works
independently of media integration and source date/slug/author copying.

On a disposable WordPress installation, run the focused HTML checks from this
directory with `wp eval-file tests/content-links.php`. Also create a translation
through Quick Create and import text into an existing draft to verify saved
content and the Gutenberg editor (no block validation errors).

## Missing translation fallback

When enabled, a frontend GET/HEAD request returning 404 is checked against the
configured source site. Only an exact path match to a published, publicly
viewable post or page results in an HTTP 302 redirect to its permalink. Query
parameters are retained. Both subdomain and subdirectory networks are supported.

The source site itself, uploads, WordPress service paths, previews, feeds, REST
requests, and post-ID query requests are excluded. Missing, draft, private, or
deleted originals leave the 404 unchanged. This applies to any qualifying 404,
not only links created by the importer, and is independent of MSLS availability
and the link rewriting setting. The chosen source must be active and belong to
the current network. Missing source rewrite rules cause the lookup to be skipped;
the helper does not regenerate them during frontend requests.

For a disposable multisite test, run `wp eval-file tests/fallback-redirect.php
SOURCE_ID TARGET_ID` from this directory. Then check actual HTTP responses:
an existing original must produce 302 and the correct Location; a missing or
unpublished original, uploads, and requests on the source site must not produce
a helper redirect. Confirm that published translations still return normally.

## Orphaned MSLS options

Daily cleanup deletes only options named exactly `msls_NUMBER` or
`msls_term_NUMBER` whose corresponding local post or term is absent from that
site's database. Existing posts of every type and status (including drafts and
trash), existing terms, and other option names are preserved. The option values
and translation references on other sites are not edited. Shared attachment
records on other sites do not count as local owners of these MSLS options.

The network's main site hosts the daily WP-Cron event. Each invocation processes
one active site and up to 100 orphan candidates; further batches run at least a
minute apart. Deleted, archived and spam sites are skipped. The first run is
scheduled one minute after the next request with the plugin enabled. WP-Cron
needs traffic on the network's main site or an external cron runner targeting
that site, so these are scheduled times.
Database errors stop a batch rather than treating owners as missing. Options
are removed with `delete_option()` to invalidate WordPress object caches.

Disabling cleanup removes its scheduled events and progress. Deactivation and
uninstall also remove this runtime state, while saved settings follow the
uninstall preference. Cleanup works without MSLS being active.

On a disposable multisite, run `wp eval-file tests/orphaned-options.php` from
this directory. This test performs real option cleanup on the current site.

## Changelog

### 1.1.0

- Add daily cleanup of orphaned `msls_NUMBER` and `msls_term_NUMBER` options
  across active network sites, preserving options for existing posts and terms.
- Process up to 100 candidates per WP-Cron batch and resume from a saved cursor.
- Add an enabled-by-default network setting. Remove cleanup events and progress
  when disabled, deactivated or uninstalled.

## Russian localization

Russian metadata translations are included in
`languages/juliepr-msls-helper-ru_RU.l10n.php`. This native PHP translation format
requires WordPress 6.5 or later; no `.mo` file is required. Log notices are supplied
and translated by MSLS itself. The plugin name retains its original spelling.

The editable translation source is `languages/juliepr-msls-helper-ru_RU.po`.
To rebuild the PHP translation file using WP-CLI 2.10 or later, run this from
the plugin directory:

```sh
wp i18n make-php languages/juliepr-msls-helper-ru_RU.po
```

See the [WordPress translation format announcement](https://make.wordpress.org/core/2024/02/27/i18n-improvements-6-5-performant-translations/).
