<?php
/**
 * Disposable multisite only: wp eval-file tests/orphaned-options.php
 * Creates disposable fixtures and performs real option writes on the current site.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/includes/Settings.php';
require_once dirname( __DIR__ ) . '/includes/OrphanedOptions.php';

use Juliepr\MslsHelper\OrphanedOptions;

if ( ! is_multisite() ) {
	throw new \RuntimeException( 'Run on a disposable multisite installation.' );
}

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
};
$post_ids = array();
$options = array();
$term_id = 0;
$blog_id = get_current_blog_id();
$value = array( '__helper_unknown_a__' => 123, '__helper_unknown_b__' => 456 );

try {
	// Restrict scans to options inserted after the fixture boundary.
	global $wpdb;
	$fixture_cursor = (int) $wpdb->get_var( "SELECT MAX(option_id) FROM {$wpdb->options}" );
	$check( ! $wpdb->last_error, 'Fixture boundary lookup failed.' );

	foreach ( array( 'publish', 'draft', 'private', 'trash' ) as $status ) {
		$id = wp_insert_post( array( 'post_title' => 'MSLS orphan cleanup test', 'post_status' => $status ), true );
		$check( ! is_wp_error( $id ) && $id > 0, 'Post fixture failed.' );
		$post_ids[] = $id;
		$name = 'msls_' . $id;
		$check( add_option( $name, $value, '', true ), 'Post option fixture failed.' );
		$options[] = $name;
	}
	$term = wp_insert_term( 'MSLS cleanup ' . wp_generate_uuid4(), 'category' );
	$check( ! is_wp_error( $term ), 'Term fixture failed.' );
	$term_id = (int) $term['term_id'];
	$name = 'msls_term_' . $term_id;
	$check( add_option( $name, $value, '', true ), 'Term option fixture failed.' );
	$options[] = $name;
	$preserved = $options;

	// Generate unused IDs, without assuming any production table prefix or object IDs.
	$missing_id = 1000000000;
	$orphans = array();
	while ( count( $orphans ) < 105 ) {
		++$missing_id;
		if ( get_post( $missing_id ) || term_exists( $missing_id ) ) {
			continue;
		}
		$name = ( count( $orphans ) % 2 ? 'msls_' : 'msls_term_' ) . $missing_id;
		if ( add_option( $name, $value, '', 0 === count( $orphans ) % 2 ) ) {
			$options[] = $name;
			$orphans[] = $name;
		}
	}
	foreach ( array( 'msls_author_', 'msls_term_extra_', 'MSLS_', 'msls_' . $missing_id . '_extra_' ) as $prefix ) {
		$name = $prefix . $missing_id;
		$check( add_option( $name, $value, '', false ), 'Unrelated option fixture failed.' );
		$options[] = $name;
		$preserved[] = $name;
	}
	// Prime option caches before deletion, including autoloaded and individual entries.
	foreach ( $options as $name ) {
		get_option( $name );
	}
	$first = OrphanedOptions::clean_site( $fixture_cursor );
	$check( null !== $first && $first['deleted'] > 0 && $first['deleted'] <= 100 && ! $first['complete'], 'Batch must stop after 100 candidates.' );
	$deleted = $first['deleted'];
	$result = $first;
	do {
		$result = OrphanedOptions::clean_site( $result['cursor'] );
		$check( null !== $result, 'Continuation query failed.' );
		$deleted += $result['deleted'];
	} while ( ! $result['complete'] );
	$check( 105 === $deleted, 'Cursor did not finish remaining orphans.' );
	foreach ( $orphans as $name ) {
		$check( false === get_option( $name ), 'Orphan retained or stale option cache: ' . $name );
	}
	foreach ( $preserved as $name ) {
		$check( $value === get_option( $name ), 'Existing owner or unrelated option changed: ' . $name );
	}
	$check( get_current_blog_id() === $blog_id, 'Cleanup changed blog context.' );

	// Exercise reference pruning with a controlled language-to-site map.
	$sites = array( 'known' => $blog_id, 'ambiguous' => null );
	$links = array( 'known' => $missing_id, 'unknown' => $missing_id, 'ambiguous' => $missing_id );
	$check( array( 'unknown' => $missing_id, 'ambiguous' => $missing_id ) === OrphanedOptions::prune_links( $links, false, $sites ), 'Unknown or ambiguous language must survive.' );
	$check( array() === OrphanedOptions::prune_links( array( 'known' => $missing_id ), false, $sites ), 'Missing post reference survived.' );
	$check( array() === OrphanedOptions::prune_links( array( 'known' => $missing_id ), true, $sites ), 'Missing term reference survived.' );
	foreach ( array( true, 1.0, array( 1 ), 'bad', 0, -1 ) as $invalid ) {
		$links = array( 'known' => $invalid );
		$check( $links === OrphanedOptions::prune_links( $links, false, $sites ), 'Malformed value changed.' );
	}
	foreach ( $post_ids as $id ) {
		$links = array( 'known' => $id );
		$check( $links === OrphanedOptions::prune_links( $links, false, $sites ), 'Existing draft/private/trash reference changed.' );
	}
	$links = array( 'known' => $term_id );
	$check( $links === OrphanedOptions::prune_links( $links, true, $sites ), 'Existing term reference changed.' );
	$check( get_current_blog_id() === $blog_id, 'Reference pruning did not restore blog context.' );

	// Cross-site reads must restore an already switched context.
	$other_id = 0;
	foreach ( get_sites( array( 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 100 ) ) as $candidate ) {
		if ( (int) $candidate !== $blog_id ) {
			$other_id = (int) $candidate;
			break;
		}
	}
	if ( $other_id ) {
		$target_post = 0;
		switch_to_blog( $other_id );
		try {
			$target_post = wp_insert_post( array( 'post_title' => 'MSLS cross-site cleanup test', 'post_status' => 'draft' ), true );
			$check( ! is_wp_error( $target_post ) && $target_post > 0, 'Target post fixture failed.' );
		} finally {
			restore_current_blog();
		}
		try {
			$links = array( 'target' => $target_post );
			$check( $links === OrphanedOptions::prune_links( $links, false, array( 'target' => $other_id ) ), 'Existing cross-site draft was pruned.' );
			switch_to_blog( $other_id );
			try {
				wp_delete_post( $target_post, true );
			} finally {
				restore_current_blog();
			}
			$check( array() === OrphanedOptions::prune_links( $links, false, array( 'target' => $other_id ) ), 'Deleted cross-site translation survived.' );
			$check( get_current_blog_id() === $blog_id, 'Cross-site pruning did not restore context.' );
		} finally {
			switch_to_blog( $other_id );
			try {
				wp_delete_post( $target_post, true );
			} finally {
				restore_current_blog();
			}
		}
	}

	// A missing target table must abort pruning, not mark its records as absent.
	$bad_site = 1000000000;
	while ( get_site( $bad_site ) ) {
		++$bad_site;
	}
	$previous_suppression = $wpdb->suppress_errors( true );
	try {
		$check( null === OrphanedOptions::prune_links( array( 'broken_db' => 1 ), false, array( 'broken_db' => $bad_site ) ), 'Database error interpreted as missing translation.' );
		$check( get_current_blog_id() === $blog_id, 'Error path did not restore blog context.' );
	} finally {
		$wpdb->suppress_errors( $previous_suppression );
	}

	// Exercise saved partial and empty arrays with actual uniquely mapped locales.
	$map = OrphanedOptions::language_sites();
	$check( null !== $map, 'Language lookup failed.' );
	$language = array_search( $blog_id, $map, true );
	if ( false !== $language ) {
		$name = 'msls_' . $post_ids[1];
		$options[] = $name;
		update_option( $name, array( $language => $missing_id, '__helper_unknown__' => 77 ) );
		$cursor = $fixture_cursor;
		do {
			$result = OrphanedOptions::clean_site( $cursor );
			$check( null !== $result, 'Partial link cleanup failed.' );
			$cursor = $result['cursor'];
		} while ( ! $result['complete'] );
		$check( array( '__helper_unknown__' => 77 ) === get_option( $name ), 'Partial cleanup did not preserve remaining links.' );
		update_option( $name, array( $language => $missing_id ) );
		$cursor = $fixture_cursor;
		do {
			$result = OrphanedOptions::clean_site( $cursor );
			$check( null !== $result, 'Empty link cleanup failed.' );
			$cursor = $result['cursor'];
		} while ( ! $result['complete'] );
		$check( false === get_option( $name ), 'Empty option survived with an existing local owner.' );
	} else {
		echo "Saved-link checks skipped: current site locale is ambiguous in this network.\n";
	}

	// Permanent removal makes the same names eligible; trash alone did not.
	wp_delete_post( $post_ids[0], true );
	wp_delete_term( $term_id, 'category' );
	// MSLS may already remove these options through its own deletion hooks.
	add_option( 'msls_' . $post_ids[0], $value, '', false );
	add_option( 'msls_term_' . $term_id, $value, '', false );
	$final = OrphanedOptions::clean_site( $fixture_cursor );
	$check( null !== $final && 2 === $final['deleted'], 'Permanently deleted owners were not cleaned.' );
	$check( false === get_option( 'msls_' . $post_ids[0] ) && false === get_option( 'msls_term_' . $term_id ), 'Deleted-owner options survived.' );
} finally {
	foreach ( $options as $name ) {
		delete_option( $name );
	}
	foreach ( $post_ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
}

echo "Orphan owners, translation references, exact names, batching and cache checks passed.\n";
