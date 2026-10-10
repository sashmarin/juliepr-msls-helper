<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

use lloc\Msls\ContentImport\ImportCoordinates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves source publication fields when MSLS creates a translation.
 */
final class TranslationPostFields {
	/**
	 * Request-only flag passed through wp_insert_post(), never stored as meta.
	 */
	private const EXPLICIT_DATE_FLAG = '_juliepr_msls_helper_explicit_date';

	/**
	 * Drafts inserted during this request, indexed by blog ID and post ID.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private static array $new_drafts = array();

	/**
	 * Tracks actual inserts, not updates to an existing draft.
	 */
	public static function remember_new_draft( int $post_id, \WP_Post $post, bool $update ): void {
		if ( $update || ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true ) ) {
			return;
		}

		self::$new_drafts[ get_current_blog_id() ][ $post_id ] = true;
	}

	/**
	 * Adds fields before the importers run and MSLS saves its new draft.
	 *
	 * @param array<string, mixed> $post_data Data for the destination post.
	 * @param ImportCoordinates   $coordinates Validated import coordinates.
	 * @return array<string, mixed>
	 */
	public static function copy_to_new_import( array $post_data, ImportCoordinates $coordinates ): array {
		if ( ! isset( self::$new_drafts[ $coordinates->dest_blog_id ][ $coordinates->dest_post_id ] )
			|| ! $coordinates->source_post instanceof \WP_Post ) {
			return $post_data;
		}

		unset( self::$new_drafts[ $coordinates->dest_blog_id ][ $coordinates->dest_post_id ] );

		return self::copy_from_source( $post_data, $coordinates->source_post );
	}

	/**
	 * @param array<string, mixed> $post_data   Data for the new translation.
	 * @param \WP_Post             $source_post Source post from its original blog.
	 * @return array<string, mixed>
	 */
	public static function copy_from_source( array $post_data, \WP_Post $source_post ): array {
		$post_data['post_author']   = (int) $source_post->post_author;
		$post_data['post_date']     = $source_post->post_date;
		$post_data['post_date_gmt'] = $source_post->post_date_gmt;
		$post_data['post_name']     = $source_post->post_name;
		// Keep wp_update_post() from clearing an explicitly copied draft date.
		$post_data['edit_date'] = true;

		$post_data[ self::EXPLICIT_DATE_FLAG ] = true;

		return $post_data;
	}

	/**
	 * Separates creation time from the copied date for Gutenberg's date label.
	 *
	 * @param array<string, mixed> $data    Processed database fields.
	 * @param array<string, mixed> $postarr Original insertion/update fields.
	 * @return array<string, mixed>
	 */
	public static function set_translation_modified_date( array $data, array $postarr ): array {
		if ( empty( $postarr[ self::EXPLICIT_DATE_FLAG ] )
			|| ! in_array( $data['post_status'], array( 'draft', 'auto-draft' ), true ) ) {
			return $data;
		}

		$data['post_modified']     = current_time( 'mysql' );
		$data['post_modified_gmt'] = current_time( 'mysql', true );

		return $data;
	}
}
