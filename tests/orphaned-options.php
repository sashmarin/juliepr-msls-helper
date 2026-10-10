<?php
/**
 * Disposable multisite only: wp eval-file tests/orphaned-options.php
 * Performs real cleanup of orphaned options on the current site.
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
$value = array( 'ru_RU' => 123, 'en_US' => 456 );

try {
	// Remove pre-existing orphans first so batch counts reflect only our fixtures.
	$cursor = 0;
	do {
		$result = OrphanedOptions::clean_site( $cursor );
		$check( null !== $result, 'Initial cleanup query failed.' );
		$cursor = $result['cursor'];
	} while ( ! $result['complete'] );

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
	$first = OrphanedOptions::clean_site();
	$check( null !== $first && $first['deleted'] > 0 && $first['deleted'] <= 100 && ! $first['complete'], 'Batch must stop after 100 candidates.' );
	$second = OrphanedOptions::clean_site( $first['cursor'] );
	$check( null !== $second && 105 === $first['deleted'] + $second['deleted'] && $second['complete'], 'Cursor did not finish remaining orphans.' );
	foreach ( $orphans as $name ) {
		$check( false === get_option( $name ), 'Orphan retained or stale option cache: ' . $name );
	}
	foreach ( $preserved as $name ) {
		$check( $value === get_option( $name ), 'Existing owner or unrelated option changed: ' . $name );
	}
	$check( get_current_blog_id() === $blog_id, 'Cleanup changed blog context.' );

	// Permanent removal makes the same names eligible; trash alone did not.
	wp_delete_post( $post_ids[0], true );
	wp_delete_term( $term_id, 'category' );
	// MSLS may already remove these options through its own deletion hooks.
	add_option( 'msls_' . $post_ids[0], $value, '', false );
	add_option( 'msls_term_' . $term_id, $value, '', false );
	$final = OrphanedOptions::clean_site();
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

echo "Orphan detection, existing owners, exact names, batching and cache checks passed.\n";
