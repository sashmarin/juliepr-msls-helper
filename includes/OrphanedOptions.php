<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily, bounded cleanup of options whose local MSLS owner no longer exists.
 */
final class OrphanedOptions {
	public const DAILY_HOOK = 'juliepr_msls_helper_cleanup_daily';
	public const BATCH_HOOK = 'juliepr_msls_helper_cleanup_batch';
	private const STATE = 'juliepr_msls_helper_cleanup_state';
	private const LOCK = 'juliepr_msls_helper_cleanup_lock';
	private const LIMIT = 100;

	public static function register(): void {
		add_action( 'init', array( self::class, 'synchronize' ), 20 );
		add_action( self::DAILY_HOOK, array( self::class, 'run' ) );
		add_action( self::BATCH_HOOK, array( self::class, 'run' ) );
	}

	/** Schedule once per network, on its main site, even from a subsite request. */
	public static function synchronize(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$enabled = Settings::get()['cleanup_orphans'];
		switch_to_blog( get_main_site_id( get_current_network_id() ) );
		try {
			if ( ! $enabled ) {
				self::clear_current_schedule();
			} elseif ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::DAILY_HOOK );
			}
		} finally {
			restore_current_blog();
		}
	}

	/** One site and at most 100 options per invocation; resume after one minute. */
	public static function run(): void {
		global $wpdb;

		if ( ! is_multisite() || ! Settings::get()['cleanup_orphans'] ) {
			return;
		}
		$network_id = get_current_network_id();
		switch_to_blog( get_main_site_id( $network_id ) );
		try {
			$lock = (int) get_option( self::LOCK, 0 );
			if ( $lock && $lock < time() - 15 * MINUTE_IN_SECONDS ) {
				delete_option( self::LOCK );
			}
			if ( ! add_option( self::LOCK, time(), '', false ) ) {
				return;
			}
			try {
				$state = get_option( self::STATE, array( 'blog' => 0, 'option' => 0 ) );
				$blog_id = (int) $state['blog'];
				$site = $blog_id ? get_site( $blog_id ) : null;
				if ( ! empty( $state['complete'] ) || ! $site || (int) $site->site_id !== $network_id || $site->deleted || $site->archived || $site->spam ) {
					$blog_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d AND deleted = 0 AND archived = 0 AND spam = 0 ORDER BY blog_id LIMIT 1",
						$network_id,
						$blog_id
					) );
					if ( $wpdb->last_error ) {
						return;
					}
					$state = array( 'blog' => $blog_id, 'option' => 0 );
				}
				if ( ! $blog_id ) {
					delete_option( self::STATE );
					wp_unschedule_hook( self::BATCH_HOOK );
					return;
				}

				switch_to_blog( $blog_id );
				try {
					$result = self::clean_site( (int) $state['option'] );
				} finally {
					restore_current_blog();
				}
				if ( null === $result ) {
					return; // A database error is not evidence that an owner is missing.
				}
				if ( $result['complete'] ) {
					// Move to the next site on the next invocation.
					$state = array( 'blog' => $blog_id, 'option' => 0, 'complete' => true );
				} else {
					$state['option'] = $result['cursor'];
				}
				update_option( self::STATE, $state, false );
				if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK );
				}
			} finally {
				delete_option( self::LOCK );
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Read database tables directly: shared-media filters must not invent a local owner.
	 * Delete via the Options API so persistent object caches are invalidated.
	 *
	 * @return array<string, int|bool>|null Null means a database error; retry later.
	 */
	public static function clean_site( int $cursor = 0 ): ?array {
		global $wpdb;

		$missing = "((o.option_name REGEXP '^msls_[0-9]+$' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = CAST(SUBSTRING(o.option_name, 6) AS UNSIGNED)))
			OR (o.option_name REGEXP '^msls_term_[0-9]+$' AND NOT EXISTS (SELECT 1 FROM {$wpdb->terms} t WHERE t.term_id = CAST(SUBSTRING(o.option_name, 11) AS UNSIGNED))))";
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.option_id, o.option_name FROM {$wpdb->options} o WHERE o.option_id > %d AND {$missing} ORDER BY o.option_id LIMIT %d",
			$cursor,
			self::LIMIT
		) );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			$cursor = (int) $row->option_id;
			if ( ! preg_match( '/\Amsls_(?:term_)?[0-9]+\z/', $row->option_name ) ) {
				continue;
			}
			// Recheck immediately before deletion, including the exact option name.
			$orphan = $wpdb->get_var( $wpdb->prepare(
				"SELECT o.option_id FROM {$wpdb->options} o WHERE o.option_id = %d AND BINARY o.option_name = %s AND {$missing}",
				(int) $row->option_id,
				$row->option_name
			) );
			if ( $wpdb->last_error ) {
				return null;
			}
			if ( $orphan && delete_option( $row->option_name ) ) {
				++$deleted;
			}
		}
		return array( 'cursor' => $cursor, 'deleted' => $deleted, 'complete' => count( $rows ) < self::LIMIT );
	}

	public static function clear_current_schedule(): void {
		wp_unschedule_hook( self::DAILY_HOOK );
		wp_unschedule_hook( self::BATCH_HOOK );
		delete_option( self::STATE );
		delete_option( self::LOCK );
	}

	/** Remove this network's runtime state without removing saved settings. */
	public static function deactivate(): void {
		if ( ! is_multisite() ) {
			return;
		}
		switch_to_blog( get_main_site_id( get_current_network_id() ) );
		try {
			self::clear_current_schedule();
		} finally {
			restore_current_blog();
		}
	}
}
