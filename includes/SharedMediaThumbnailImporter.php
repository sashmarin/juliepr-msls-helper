<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

use lloc\Msls\ContentImport\Importers\BaseImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SharedMediaThumbnailImporter extends BaseImporter {

	/**
	 * Reuse the shared attachment ID without creating a local attachment.
	 *
	 * @param array<string, mixed> $data Imported post fields.
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$coordinates = $this->import_coordinates;

		switch_to_blog( $coordinates->source_blog_id );

		try {
			$thumbnail_id = (int) get_post_meta( $coordinates->source_post_id, '_thumbnail_id', true );
		} finally {
			restore_current_blog();
		}

		if ( $thumbnail_id <= 0 ) {
			return $data;
		}

		switch_to_blog( $coordinates->dest_blog_id );

		try {
			// Shared attachments may not exist locally, so bypass set_post_thumbnail's validation.
			$result = update_post_meta( $coordinates->dest_post_id, '_thumbnail_id', $thumbnail_id );
			$is_set = false !== $result || $thumbnail_id === (int) get_post_meta( $coordinates->dest_post_id, '_thumbnail_id', true );

			if ( $is_set ) {
				$this->logger->log_success( 'post-thumbnail/set', $thumbnail_id );
			} else {
				$this->logger->log_error( 'post-thumbnail/set', $thumbnail_id );
			}
		} finally {
			restore_current_blog();
		}

		return $data;
	}
}
