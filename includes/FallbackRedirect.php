<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves missing translated paths against a configured source site.
 */
final class FallbackRedirect {
	public static function redirect(): void {
		if ( ! is_multisite() || is_admin() || ! is_404() || is_preview() || is_feed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$settings = Settings::get();
		if ( ! $settings['fallback_redirect'] ) {
			return;
		}

		$request = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( ! is_array( $request ) || empty( $request['path'] ) ) {
			return;
		}
		$query = array();
		wp_parse_str( $request['query'] ?? '', $query );
		foreach ( array( 'p', 'page_id', 'attachment_id', 'preview', 'rest_route' ) as $key ) {
			if ( isset( $query[ $key ] ) ) {
				return;
			}
		}

		$target = self::find_destination( $settings['fallback_blog_id'], $request['path'] );
		if ( null === $target ) {
			return;
		}
		if ( isset( $request['query'] ) && '' !== $request['query'] ) {
			$target .= '?' . $request['query'];
		}

		// Permit only this verified source host for this one redirect.
		$host       = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}
		$allow_host = static function ( array $hosts ) use ( $host ): array {
			$hosts[] = $host;
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow_host );
		try {
			nocache_headers();
			$redirected = wp_safe_redirect( $target, 302, 'Juliepr MSLS Helper' );
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allow_host );
		}
		if ( $redirected ) {
			exit;
		}
	}

	/**
	 * Maps only content paths within the requested site's home directory.
	 */
	public static function map_path( string $path, string $current_home, string $source_home ): ?string {
		$base = rtrim( (string) wp_parse_url( $current_home, PHP_URL_PATH ), '/' );
		if ( '' === $path || '/' !== $path[0]
			|| ( '' !== $base && $path !== $base && 0 !== strpos( $path, $base . '/' ) ) ) {
			return null;
		}
		$relative = substr( $path, strlen( $base ) );
		$decoded  = rawurldecode( $relative );
		if ( '' === trim( $relative, '/' )
			|| preg_match( '~(?:^|/)wp-content/uploads(?:/|$)~i', $decoded )
			|| preg_match( '~^/(?:wp-admin|wp-json|wp-content|wp-includes)(?:/|$)|^/(?:wp-login|wp-cron|xmlrpc)\.php(?:/|$)~i', $decoded ) ) {
			return null;
		}

		return rtrim( $source_home, '/' ) . $relative;
	}

	/**
	 * Returns a published post/page permalink only for an exact path match.
	 */
	public static function find_destination( int $source_blog_id, string $path ): ?string {
		global $wp_rewrite;

		if ( ! is_multisite() || $source_blog_id === get_current_blog_id() ) {
			return null;
		}
		$site = get_site( $source_blog_id );
		if ( ! $site || (int) $site->site_id !== get_current_network_id()
			|| $site->deleted || $site->archived || $site->spam ) {
			return null;
		}
		$source_home = get_home_url( $source_blog_id );
		$candidate   = self::map_path( $path, get_home_url(), $source_home );
		if ( null === $candidate ) {
			return null;
		}

		$previous_rewrite = $wp_rewrite;
		switch_to_blog( $source_blog_id );
		try {
			// Do not generate or persist rewrite rules during a 404 lookup.
			if ( ! get_option( 'rewrite_rules' ) ) {
				return null;
			}
			// switch_to_blog() does not reinitialize the request's rewrite object.
			$wp_rewrite = new \WP_Rewrite();
			$post_id    = url_to_postid( $candidate );
			if ( ! $post_id ) {
				return null;
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status
				|| ! in_array( $post->post_type, array( 'post', 'page' ), true )
				|| ! is_post_publicly_viewable( $post ) ) {
				return null;
			}
			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink )
				|| wp_parse_url( $permalink, PHP_URL_HOST ) !== wp_parse_url( $source_home, PHP_URL_HOST )
				|| untrailingslashit( (string) wp_parse_url( $permalink, PHP_URL_PATH ) )
					!== untrailingslashit( (string) wp_parse_url( $candidate, PHP_URL_PATH ) ) ) {
				return null;
			}

			return $permalink;
		} finally {
			$wp_rewrite = $previous_rewrite;
			restore_current_blog();
		}
	}
}
