# Agent instructions: Juliepr MSLS Helper

## Scope and purpose

These instructions cover this directory and its descendants. While this plugin
lives inside the MSLS checkout, also follow the parent repository's `AGENTS.md`
and `CLAUDE.md`. If extracted into its own repository, this file supplies the
helper-specific context without requiring the parent checkout.

Juliepr MSLS Helper is a standalone, network-activated WordPress plugin by
Sergei Ashmarin (`sashmarin`, https://github.com/sashmarin). Its purpose is to
integrate Multisite Language Switcher (MSLS) with a global media library.
The implementation handles featured images during Content Import and copies
source publication dates, slugs, and authors during Quick Create and new Content Import.

The user's shared media plugin is **Network Media Library**. Attachments belong
to a central media site and are accessed from other sites through that plugin.
The same attachment ID must therefore be retained across translated posts.
The helper does not implement the shared media library or resolve image URLs.

## Requirements and installation

- WordPress Multisite 6.5 or later; PHP 7.4 or later.
- MSLS exposing the namespaced Content Import classes and hooks listed below.
- MSLS Content Import enabled, with Featured Image set to Linking for the import.
- A shared media library plugin resolving the same attachment ID on both sites.

These are the helper's requirements; installed versions of MSLS and Network
Media Library may have higher requirements.

Install this directory directly as `wp-content/plugins/juliepr-msls-helper/`,
alongside MSLS, and activate it for the network. It is not an MSLS source module.
Do not modify the host MSLS plugin or Network Media Library for helper changes
unless the user explicitly requests that scope.

## Files and architecture

- `juliepr-msls-helper.php`: plugin header, translation registration, dependency
  check, importer class loading, and hook registration.
- `includes/SharedMediaThumbnailImporter.php`: custom featured image importer.
- `includes/TranslationPostFields.php`: source date/slug/author filters for new translations.
- `includes/Settings.php`: network settings page and option handling.
- `includes/OrphanedOptions.php`: daily batched cleanup of orphaned local MSLS options.
- `tests/orphaned-options.php`: disposable WordPress option cleanup checks.
- `includes/FallbackRedirect.php`: exact-path 404 fallback to a configured site.
- `includes/ContentLinks.php`: imported HTML anchor URL rewriting.
- `includes/LinkRewritingPostFieldsImporter.php`: standard post-fields importer
  subclass that processes a private source copy before saving.
- `tests/content-links.php`: focused checks using the WordPress HTML processor.
- `tests/fallback-redirect.php`: disposable multisite source lookup checks.
- `uninstall.php`: optional removal of helper settings, per network.
- `README.md`: English GitHub documentation and localization rebuild command.
- `readme.txt`: English WordPress-style plugin documentation.
- `languages/juliepr-msls-helper-ru_RU.po`: editable Russian translation source.
- `languages/juliepr-msls-helper-ru_RU.l10n.php`: native PHP translation artifact.

PHP namespace: `Juliepr\MslsHelper`. There is no helper-specific Composer/npm
configuration, autoloader, or build pipeline. Focused WordPress-based checks
run with `wp eval-file tests/content-links.php` from this directory.

At `plugins_loaded`, priority 20, the bootstrap checks for
`lloc\Msls\ContentImport\Importers\BaseImporter`. If unavailable, it returns
without loading the subclass or registering the importer filter.
The settings page is registered independently and remains available without MSLS.
The network option `juliepr_msls_helper_settings` contains six boolean keys:
`shared_media`, `copy_post_fields`, `rewrite_links`, `cleanup_orphans`, and `fallback_redirect` default
to true; `delete_on_uninstall` defaults to false. The integer `fallback_blog_id`
defaults to 1 and must be positive. When fallback is enabled, saving validates
that the source site is active and in the current network.
Register each integration's hooks only when its setting is
enabled. The network Settings submenu requires `manage_network_options`;
the save handler also checks that capability and a nonce. Missing checkboxes
save as false, whereas missing stored keys use their defaults.

The filter `msls_content_import_post-thumbnail_importer` receives the existing
importer and an `lloc\Msls\ContentImport\ImportCoordinates` object. When
`get_importer_for( 'post-thumbnail' )` is exactly `linking`, it returns a
`SharedMediaThumbnailImporter` instance. For any other value, including an empty
string or null, it returns the incoming importer unchanged. Do not silently
force featured image import when the user selected no import.

The subclass extends MSLS `BaseImporter` and uses its coordinates and logger.
It reads the source post's raw `_thumbnail_id` in the source blog context,
then writes that integer ID to the destination post in the destination context.
It returns the incoming post-fields array unchanged.

## Behavior to preserve

- Never create a destination attachment or copy image files for this importer.
  Do not call `wp_insert_attachment()`, sideload media, or generate thumbnails.
- Use `update_post_meta()` for `_thumbnail_id`. Do not replace it with
  `set_post_thumbnail()` without verifying shared-library compatibility:
  that function validates the attachment through WordPress image APIs.
- A positive source ID replaces the destination's existing featured image.
  A missing/nonpositive source ID leaves the destination image unchanged.
- Pair each `switch_to_blog()` with `restore_current_blog()` in `finally`.
  Restore the previous context, which may already be switched by MSLS;
  do not assume the request site is the current site.
- A false return from `update_post_meta()` can mean the value was already equal.
  The importer reads it back before deciding whether to log an error.
- Log via the MSLS logger using `post-thumbnail/set`, so existing MSLS notices
  continue to work. Success means the metadata was assigned, not that the file
  or a particular image size has been verified.
- Quick Create / Add from Translation uses `msls_quick_create_post_data` at
  priority 20 to copy `post_date`, `post_date_gmt`, and `post_name` verbatim,
  plus `post_author` as an integer. Retain the source network user ID; do not
  add site membership or change roles.
  Preserve its draft status and all other prepared data. Do not force slug
  uniqueness overrides: WordPress may suffix an occupied slug when publishing.
  This filter does not copy featured images or update existing translations.
- Content Import copies the same fields using
  `msls_content_import_data_before_import` only for destination drafts inserted
  during the current request. `wp_insert_post` tracks inserts (`$update = false`)
  with draft/auto-draft status, keyed by blog and post ID. Consume the entry on
  import so a second import cannot overwrite subsequently edited fields.
  Do not infer newness from draft status alone or store persistent tracking data.
  Returning the fields before import lets the post-fields importer and MSLS's
  final save of its newly created draft persist them, even if post-fields import
  is disabled. Existing posts/drafts remain unchanged.
- `copy_from_source()` adds a request-only explicit-date flag to the post data.
  It also sets `edit_date` so `wp_update_post()` retains the copied date when
  updating the initially floating Content Import draft.
  `wp_insert_post_data` uses that flag to set current local/UTC modification
  dates only for these draft/auto-draft writes. WordPress otherwise makes a new
  post's publication and modification dates equal; Gutenberg treats that as a
  floating date and labels it Immediately. Do not change the publication date
  or add persistent metadata for this display behavior. Equal timestamps to the
  second can still produce Immediately until a subsequent save.
- Apart from the standard post-fields subclass described below, other MSLS
  importers retain their behavior. There is no migration
  or bulk repair of post fields. Deactivation preserves settings. Uninstall removes only the
  helper option for networks that opted in, without touching posts, links, media,
  or other plugins' settings.
- Text link rewriting is controlled by `rewrite_links`, enabled by default,
  independently of media integration and post-field copying. Missing stored
  keys use the enabled default, including settings saved before this feature.
  When disabled, register neither the Quick Create callback nor the importer map
  filter; subsequent imports retain source links without rewriting existing posts.
  Quick Create filters prepared text fields at priority 30. Content Import maps
  only the standard `duplicating` post-fields class to the helper subclass;
  preserve custom importer classes and the choice to skip text import. The
  subclass clones coordinates and source post for its import, restoring the
  original coordinates in `finally`, and uses the parent whitelist/save/logger.
  Rewrite only HTTP(S)/protocol-relative anchor hrefs on the source home host
  and port, respecting home-path boundaries. Exclude decoded uploads paths.
  Preserve image attributes, block comments, other attributes, external/relative
  URLs, query strings and fragments. No translation lookup during import.
  Existing destination text changes only when importing selected text fields.
- Fallback redirects run on `template_redirect` at priority 1 before core
  canonical guessing. Enable independently of MSLS with `fallback_redirect`.
  Only frontend 404 GET/HEAD requests qualify. Match published, publicly viewable
  posts/pages by exact permalink path on `fallback_blog_id` in this network.
  Exclude the source site, uploads, service paths, previews, feeds, REST and
  post-ID query requests. Temporarily use a source-context WP_Rewrite object,
  restoring both rewrite and blog context in `finally`. Never regenerate missing
  rewrite rules. Temporarily permit only the verified source host for
  `wp_safe_redirect()` with status 302; restore the host filter afterward.
  Preserve query parameters and send no-cache headers. No persistent lookup cache.

In the parent checkout, inspect `includes/ContentImport/Importers/ImportersBaseFactory.php`,
`includes/ContentImport/ImportCoordinates.php`, and
`includes/ContentImport/Importers/PostThumbnail/Linking.php` to verify hook behavior.
For Quick Create, inspect `includes/RestApi/RestApi.php`.

- Orphan cleanup runs daily on the network main site, independently of MSLS.
  Process one active site and up to 100 MSLS option candidates per cron invocation,
  continuing in single events. Match only exact numeric MSLS post/term option
  names and confirm local ownership through database tables, regardless of status.
  For existing owners, resolve languages by WPLANG (empty means en_US) across
  active network sites. Remove only positive numeric references to missing local
  target objects; preserve unknown/ambiguous languages and malformed values.
  Delete emptied arrays. Use MSLS Post/Tax delete() for whole arrays where available
  and update_option() for partial arrays; preserve valid values and autoload.
  Scan all numeric MSLS options, not only missing owners. Clear cron/progress/locks on disable, deactivate and uninstall.

## Coding and localization

Keep changes small, use WordPress coding conventions (tabs and spaced function
calls), declare strict types, and retain PHP 7.4 syntax compatibility.
Guard directly accessible implementation files with `ABSPATH`. Keep documentation
and code comments in English. Keep author credit and the plugin name intact.

Text domain: `juliepr-msls-helper`; domain path: `/languages`.
Register translations on `init`, not by invoking translation functions during
early plugin loading. The plugin metadata is translated by WordPress; import
notices use MSLS's own text domain and translation files.

Maintain the Russian PO source and the matching `.l10n.php` artifact together
when changing translated strings, including the plugin description. Do not
generate or commit `.mo` files. Rebuild from this directory with WP-CLI 2.10+:

```sh
wp i18n make-php languages/juliepr-msls-helper-ru_RU.po
```

Keep the plugin header, both README files, and translations consistent when
changing requirements, version, description, or supported behavior.

## Verification and troubleshooting

For PHP changes, run from this directory when PHP is available:

```sh
php -l juliepr-msls-helper.php
php -l includes/SharedMediaThumbnailImporter.php
php -l includes/TranslationPostFields.php
php -l includes/Settings.php
php -l includes/OrphanedOptions.php
php -l tests/orphaned-options.php
php -l includes/FallbackRedirect.php
php -l includes/ContentLinks.php
php -l includes/LinkRewritingPostFieldsImporter.php
php -l tests/content-links.php
php -l tests/fallback-redirect.php
php -l uninstall.php
php -l languages/juliepr-msls-helper-ru_RU.l10n.php
```

Check the diff for whitespace errors. If files are untracked, inspect them too:
`git diff --check` alone does not cover untracked files. The parent MSLS test
suite does not automatically validate this separate plugin. Add meaningful
focused tests for behavioral changes when practical; avoid unrelated parent edits.

On a disposable multisite installation, verify:

1. Linking imports the exact source ID and creates no attachment/file copy.
2. Selecting no featured image import preserves the destination image.
3. No source image preserves the destination image.
4. Reimporting the same ID succeeds; an existing different ID is replaced.
5. Blog context is restored, including when reads or writes throw.
6. The image renders in the editor and frontend through Network Media Library.
7. Missing MSLS causes no class-loading fatal; Quick Create copies source local
   and UTC publication dates, slug, and author, preserving draft status and other data.
   Check publication with both a free and an occupied slug on the target site.
8. Russian plugin metadata loads from `.l10n.php` without a `.mo` file.
9. Content Import into a newly created draft copies source date/UTC date/slug/author,
   including when post-fields import is disabled. Existing drafts and published
   posts retain their date/slug/author. Tracking must distinguish identical post IDs on
   different sites, ignore updates, and be consumed after the first import.
10. Opening a new translation in Gutenberg shows the copied publication date
    before a manual save when the source date differs from creation time.
    Ordinary post inserts without the helper flag retain WordPress behavior.
11. The settings page renders defaults (on, on, on, on, source ID 1, off), saves unchecked boxes as
    false, and preserves values on reload. Check independent integration toggles,
    nonce/capability rejection, and the page with MSLS inactive.
12. Deactivation preserves settings; deleting the plugin preserves them by
    default and removes only opted-in network options. Check multiple networks.
13. Run `wp eval-file tests/content-links.php` on a disposable WordPress install.
    Verify Quick Create and Content Import saved text in new/existing drafts,
    skip-text behavior, source isolation, untouched image URLs, and Gutenberg
    block validity. Link rewriting works with field copying disabled too.
14. On a disposable multisite run `wp eval-file tests/fallback-redirect.php
    SOURCE_ID TARGET_ID`. Check actual frontend status/Location with GET and HEAD:
    exact published original -> 302; missing/draft/private original and uploads
    -> no helper redirect; existing translation -> unchanged. Check non-1 source
    ID, subdomains/subdirectories, disabled fallback, bad source ID, and restored
    blog/rewrite context.

An attachment missing from the destination site's posts table is expected.
When diagnosing an empty preview, compare source/destination `_thumbnail_id`,
inspect the attachment and its metadata on the configured central media site,
then inspect image API results in the destination site context. Do not hardcode
the central blog ID, table prefix, post IDs, or URLs from a particular installation.

A preview can be empty because a requested thumbnail size/file is missing even
when the shared ID is correct. This occurred during the user's verification;
the image subsequently displayed, so that symptom alone is not evidence that
the importer needs to create local attachments or change its ID assignment.

Report which checks actually ran. PHP, WP-CLI, and a live WordPress environment
were unavailable during the initial implementation in this workspace; verify
current tool availability rather than treating that as a permanent limitation.
