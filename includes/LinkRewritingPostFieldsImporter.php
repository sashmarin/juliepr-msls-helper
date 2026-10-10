<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

use lloc\Msls\ContentImport\Importers\PostFields\Duplicating;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites a private source copy before MSLS copies and saves its fields.
 */
final class LinkRewritingPostFieldsImporter extends Duplicating {
	/**
	 * @param array<string, mixed> $data Imported post fields.
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$original = $this->import_coordinates;
		if ( ! $original->source_post instanceof \WP_Post ) {
			return $data;
		}

		$coordinates = clone $original;
		$source_post = clone $original->source_post;
		$fields      = ContentLinks::rewrite_fields(
			array(
				'post_content'          => $source_post->post_content,
				'post_content_filtered' => $source_post->post_content_filtered,
				'post_excerpt'          => $source_post->post_excerpt,
			),
			$original->source_blog_id,
			$original->dest_blog_id
		);
		foreach ( $fields as $field => $value ) {
			$source_post->{$field} = $value;
		}
		$coordinates->source_post = $source_post;
		$this->import_coordinates = $coordinates;

		try {
			return parent::import( $data );
		} finally {
			$this->import_coordinates = $original;
		}
	}
}
