<?php
/**
 * Disposable multisite only:
 * wp eval-file tests/fallback-redirect.php SOURCE_ID TARGET_ID
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/includes/Settings.php';
require_once dirname( __DIR__ ) . '/includes/FallbackRedirect.php';

$source_id = (int) ( $args[0] ?? 0 );
$target_id = (int) ( $args[1] ?? 0 );
if ( ! is_multisite() || $source_id < 1 || $target_id < 1 || $source_id === $target_id
	|| ! get_site( $source_id ) || ! get_site( $target_id ) ) {
	throw new \RuntimeException( 'Provide two distinct existing site IDs on a disposable multisite.' );
}

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
};

// Cover subdomain and subdirectory home mapping without depending on fixtures.
foreach ( array(
	array( '/article/', 'https://en.example', 'https://example', 'https://example/article/' ),
	array( '/en/article/', 'https://example/en', 'https://example/ru', 'https://example/ru/article/' ),
	array( '/en/parent/child/', 'https://example/en', 'https://example', 'https://example/parent/child/' ),
	array( '/english/article/', 'https://example/en', 'https://example', null ),
	array( '/en/', 'https://example/en', 'https://example', null ),
	array( '/en/wp-content/uploads/photo.jpg', 'https://example/en', 'https://example', null ),
	array( '/wp-content/%75ploads/photo.jpg', 'https://en.example', 'https://example', null ),
	array( '/wp-json/posts', 'https://en.example', 'https://example', null ),
) as $case ) {
	$check( \Juliepr\MslsHelper\FallbackRedirect::map_path( $case[0], $case[1], $case[2] ) === $case[3], 'Path mapping mismatch.' );
}

$post_ids = array();
$slug     = 'msls-helper-fallback-' . wp_generate_uuid4();
$relative = '';
$expected = '';

try {
	switch_to_blog( $source_id );
	try {
		$check( (bool) get_option( 'rewrite_rules' ), 'Source needs saved pretty permalink rewrite rules.' );
		foreach ( array( 'publish', 'draft', 'private', 'trash' ) as $status ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => $status,
					'post_title'  => 'MSLS Helper fallback test ' . $status,
					'post_name'   => $slug . '-' . $status,
				),
				true
			);
			$check( ! is_wp_error( $id ) && $id > 0, 'Fixture creation failed.' );
			$post_ids[ $status ] = $id;
		}
		// Resolve with a source-context rewrite object, just like the implementation.
		$previous_rewrite = $GLOBALS['wp_rewrite'];
		try {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
			$expected = get_permalink( $post_ids['publish'] );
		} finally {
			$GLOBALS['wp_rewrite'] = $previous_rewrite;
		}
		$source_base = rtrim( (string) wp_parse_url( get_home_url(), PHP_URL_PATH ), '/' );
		$relative    = substr( (string) wp_parse_url( $expected, PHP_URL_PATH ), strlen( $source_base ) );
	} finally {
		restore_current_blog();
	}

	switch_to_blog( $target_id );
	try {
		$target_base = rtrim( (string) wp_parse_url( get_home_url(), PHP_URL_PATH ), '/' );
		$rewrite     = $GLOBALS['wp_rewrite'];
		$check( \Juliepr\MslsHelper\FallbackRedirect::find_destination( $source_id, $target_base . $relative ) === $expected, 'Published original not resolved.' );
		$check( get_current_blog_id() === $target_id && $GLOBALS['wp_rewrite'] === $rewrite, 'Context not restored after lookup.' );

		foreach ( array( 'draft', 'private', 'trash', 'missing' ) as $status ) {
			$path = $target_base . '/' . $slug . '-' . $status . '/';
			$check( null === \Juliepr\MslsHelper\FallbackRedirect::find_destination( $source_id, $path ), 'Unavailable original should not redirect.' );
			$check( get_current_blog_id() === $target_id && $GLOBALS['wp_rewrite'] === $rewrite, 'Context not restored after unsuccessful lookup.' );
		}
		$check( null === \Juliepr\MslsHelper\FallbackRedirect::find_destination( $target_id, $target_base . $relative ), 'Self-redirect must be excluded.' );
		$check( null === \Juliepr\MslsHelper\FallbackRedirect::find_destination( $source_id, $target_base . '/wp-content/uploads/photo.jpg' ), 'Uploads must be excluded.' );
	} finally {
		restore_current_blog();
	}
} finally {
	switch_to_blog( $source_id );
	try {
		foreach ( $post_ids as $id ) {
			wp_delete_post( $id, true );
		}
	} finally {
		restore_current_blog();
	}
}

echo "Fallback path, publication status, self-redirect and context restoration checks passed.\n";
