<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites imported HTML links without changing media URLs or block comments.
 */
final class ContentLinks {
	/**
	 * @param array<string, mixed> $data Imported fields.
	 * @return array<string, mixed>
	 */
	public static function rewrite_fields( array $data, int $source_blog_id, int $target_blog_id ): array {
		$source_home = get_home_url( $source_blog_id );
		$target_home = get_home_url( $target_blog_id );

		foreach ( array( 'post_content', 'post_content_filtered', 'post_excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				$data[ $field ] = self::rewrite( $data[ $field ], $source_home, $target_home );
			}
		}

		return $data;
	}

	/**
	 * Changes only absolute HTTP(S) and protocol-relative anchor hrefs.
	 */
	public static function rewrite( string $html, string $source_home, string $target_home ): string {
		$source = wp_parse_url( $source_home );
		$target = wp_parse_url( $target_home );
		if ( ! is_array( $source ) || empty( $source['host'] )
			|| ! is_array( $target ) || empty( $target['host'] ) ) {
			return $html;
		}

		$source_path = rtrim( $source['path'] ?? '', '/' );
		$processor   = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'A' ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) || ! preg_match( '~^(?:https?:)?//~i', $href ) ) {
				continue;
			}

			$url = wp_parse_url( $href );
			if ( ! is_array( $url ) || empty( $url['host'] )
				|| 0 !== strcasecmp( $url['host'], $source['host'] )
				|| (int) ( $url['port'] ?? 0 ) !== (int) ( $source['port'] ?? 0 )
				|| isset( $url['user'] ) || isset( $url['pass'] ) ) {
				continue;
			}

			$path = $url['path'] ?? '';
			if ( preg_match( '~(?:^|/)wp-content/uploads(?:/|$)~i', rawurldecode( $path ) ) ) {
				continue;
			}

			// Honor the source site's path boundary in subdirectory networks.
			if ( '' !== $source_path && $path !== $source_path
				&& 0 !== strpos( $path, $source_path . '/' ) ) {
				continue;
			}

			$home = rtrim( $target_home, '/' );
			if ( 0 === strpos( $href, '//' ) ) {
				$home = preg_replace( '~^https?:~i', '', $home );
			}
			$new_href = $home . substr( $path, strlen( $source_path ) );
			if ( isset( $url['query'] ) ) {
				$new_href .= '?' . $url['query'];
			}
			if ( isset( $url['fragment'] ) ) {
				$new_href .= '#' . $url['fragment'];
			}

			if ( $new_href !== $href ) {
				$processor->set_attribute( 'href', $new_href );
			}
		}

		return $processor->get_updated_html();
	}
}
