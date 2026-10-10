<?php
/**
 * Plugin Name: Juliepr MSLS Helper
 * Plugin URI:        https://github.com/sashmarin/juliepr-msls-helper
 * Description: Extends MSLS with shared media, source date/slug/author copying, imported link rewriting and 302 redirects to original content.
 * Version: 1.0.0
 * Author: Sergei Ashmarin
 * Author URI: https://github.com/sashmarin
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Network: true
 * Text Domain: juliepr-msls-helper
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace Juliepr\MslsHelper;

use lloc\Msls\ContentImport\ImportCoordinates;
use lloc\Msls\ContentImport\Importers\BaseImporter;
use lloc\Msls\ContentImport\Importers\PostFields\Duplicating;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Settings.php';
Settings::register();

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'juliepr-msls-helper',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$settings = Settings::get();
		if ( $settings['fallback_redirect'] ) {
			require_once __DIR__ . '/includes/FallbackRedirect.php';
			add_action( 'template_redirect', array( FallbackRedirect::class, 'redirect' ), 1 );
		}

		if ( ! class_exists( BaseImporter::class ) ) {
			return;
		}

		if ( $settings['rewrite_links'] ) {
			require_once __DIR__ . '/includes/ContentLinks.php';
			require_once __DIR__ . '/includes/LinkRewritingPostFieldsImporter.php';

			add_filter(
				'msls_quick_create_post_data',
				static function ( array $data, \WP_Post $source_post, int $source_blog_id, int $target_blog_id ): array {
					return ContentLinks::rewrite_fields( $data, $source_blog_id, $target_blog_id );
				},
				30,
				4
			);

			add_filter(
				'msls_content_import_post-fields_importers_map',
				static function ( array $map ): array {
					if ( isset( $map['duplicating'] ) && Duplicating::class === $map['duplicating'] ) {
						$map['duplicating'] = LinkRewritingPostFieldsImporter::class;
					}

					return $map;
				}
			);
		}

		if ( $settings['copy_post_fields'] ) {
			require_once __DIR__ . '/includes/TranslationPostFields.php';

			add_filter(
				'wp_insert_post_data',
				array( TranslationPostFields::class, 'set_translation_modified_date' ),
				20,
				2
			);

			add_action(
				'wp_insert_post',
				array( TranslationPostFields::class, 'remember_new_draft' ),
				10,
				3
			);

			add_filter(
				'msls_content_import_data_before_import',
				array( TranslationPostFields::class, 'copy_to_new_import' ),
				20,
				2
			);

			add_filter(
				'msls_quick_create_post_data',
				array( TranslationPostFields::class, 'copy_from_source' ),
				20,
				2
			);
		}

		if ( $settings['shared_media'] ) {
			require_once __DIR__ . '/includes/SharedMediaThumbnailImporter.php';

			add_filter(
				'msls_content_import_post-thumbnail_importer',
				static function ( $importer, ImportCoordinates $coordinates ) {
					if ( 'linking' !== $coordinates->get_importer_for( 'post-thumbnail' ) ) {
						return $importer;
					}

					return new SharedMediaThumbnailImporter( $coordinates );
				},
				10,
				2
			);
		}
	},
	20
);
