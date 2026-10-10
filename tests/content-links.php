<?php
/**
 * Run in a disposable WordPress environment: wp eval-file tests/content-links.php
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/includes/ContentLinks.php';

$source_home = 'https://source.example';
$target_home = 'https://translated.example';
$cases       = array(
	array( 'https://source.example/article/', 'https://translated.example/article/' ),
	array( 'http://source.example/article/', 'https://translated.example/article/' ),
	array( 'https://SOURCE.example/article/', 'https://translated.example/article/' ),
	array( '//source.example/article/', '//translated.example/article/' ),
	array( 'https://source.example/article/?a=1&b=2#part', 'https://translated.example/article/?a=1&b=2#part' ),
	array( 'https://source.example/', 'https://translated.example/' ),
	array( 'https://source.example/wp-content/uploads/photo.jpg', 'https://source.example/wp-content/uploads/photo.jpg' ),
	array( 'https://source.example/wp-content/uploads/document.pdf?download=1#page=2', 'https://source.example/wp-content/uploads/document.pdf?download=1#page=2' ),
	array( '//source.example/wp-content/uploads/photo.jpg', '//source.example/wp-content/uploads/photo.jpg' ),
	array( 'https://source.example/wp-content/%75ploads/photo.jpg', 'https://source.example/wp-content/%75ploads/photo.jpg' ),
	array( 'https://source.example/article/?file=/wp-content/uploads/photo.jpg', 'https://translated.example/article/?file=/wp-content/uploads/photo.jpg' ),
	array( 'https://external.example/article/', 'https://external.example/article/' ),
	array( 'https://source.example.evil.example/article/', 'https://source.example.evil.example/article/' ),
	array( 'https://source.example:8443/article/', 'https://source.example:8443/article/' ),
	array( 'https://user@source.example/article/', 'https://user@source.example/article/' ),
	array( '/article/', '/article/' ),
	array( 'article/', 'article/' ),
	array( '#part', '#part' ),
	array( 'mailto:author@source.example', 'mailto:author@source.example' ),
);

$check = static function ( string $input, string $expected, string $source, string $target ): void {
	$actual = \Juliepr\MslsHelper\ContentLinks::rewrite( $input, $source, $target );
	if ( $actual !== $expected ) {
		throw new \RuntimeException( "Content link assertion failed.\nInput: " . $input . "\nExpected: " . $expected . "\nActual: " . $actual );
	}
};

foreach ( $cases as $case ) {
	$check(
		'<a href="' . esc_attr( $case[0] ) . '">Text</a>',
		'<a href="' . esc_attr( $case[1] ) . '">Text</a>',
		$source_home,
		$target_home
	);
}

// Preserve every media attribute and Gutenberg block comment byte-for-byte.
$image = '<img src="https://source.example/wp-content/uploads/photo.jpg" srcset="https://source.example/wp-content/uploads/small.jpg 300w, https://source.example/wp-content/uploads/photo.jpg 900w" />';
$block = '<!-- wp:image {"id":12,"linkDestination":"media"} --><figure><a href="https://source.example/wp-content/uploads/photo.jpg">' . $image . '</a></figure><!-- /wp:image -->';
$check( $block, $block, $source_home, $target_home );
$check(
	'<a href="https://source.example/article/">Text</a>' . $block,
	'<a href="https://translated.example/article/">Text</a>' . $block,
	$source_home,
	$target_home
);

// Map source/destination home paths while honoring site boundaries.
foreach ( array(
	array( '/ru/article/?a=1#part', '/en/article/?a=1#part' ),
	array( '/ru/', '/en/' ),
	array( '/russian/article/', '/russian/article/' ),
	array( '/another/article/', '/another/article/' ),
	array( '/ru/wp-content/uploads/photo.jpg', '/ru/wp-content/uploads/photo.jpg' ),
) as $case ) {
	$expected_host = 0 === strpos( $case[1], '/en/' ) ? 'translated.example' : 'source.example';
	$check(
		'<a href="https://source.example' . $case[0] . '">Text</a>',
		'<a href="https://' . $expected_host . $case[1] . '">Text</a>',
		'https://source.example/ru',
		'https://translated.example/en'
	);
}

echo "Content link checks passed (26 cases).\n";
