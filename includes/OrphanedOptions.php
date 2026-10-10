<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily, bounded cleanup of missing MSLS owners and broken translation references.
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
	 * Inspect every numeric MSLS option, including links with an existing owner.
	 * Read tables directly so shared-media filters cannot invent local records.
	 *
	 * @return array<string, int|bool>|null Null means a database error; retry later.
	 */
	public static function clean_site( int $cursor = 0 ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id > %d AND option_name REGEXP '^msls_(term_)?[0-9]+$' ORDER BY option_id LIMIT %d",
			$cursor,
			self::LIMIT
		) );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		$sites = self::language_sites();
		if ( null === $sites ) {
			return null;
		}
		$deleted = 0;
		$updated = 0;
		foreach ( $rows as $row ) {
			$cursor = (int) $row->option_id;
			if ( ! preg_match( '/\Amsls_(term_)?([0-9]+)\z/', $row->option_name, $match ) ) {
				continue;
			}
			$is_term = ! empty( $match[1] );
			$exists = self::owner_exists( (int) $match[2], $is_term );
			if ( null === $exists ) {
				return null;
			}
			if ( ! $exists ) {
				if ( self::delete( $row->option_name, (int) $match[2], $is_term ) ) {
					++$deleted;
				}
				if ( $wpdb->last_error ) {
					return null;
				}
				continue;
			}

			$links = get_option( $row->option_name );
			if ( ! is_array( $links ) ) {
				continue;
			}
			$remaining = self::prune_links( $links, $is_term, $sites );
			if ( null === $remaining ) {
				return null;
			}
			if ( $remaining === $links || get_option( $row->option_name ) !== $links ) {
				continue;
			}
			if ( empty( $remaining ) ) {
				if ( self::delete( $row->option_name, (int) $match[2], $is_term ) ) {
					++$deleted;
				}
			} elseif ( update_option( $row->option_name, $remaining ) ) {
				++$updated;
			}
			if ( $wpdb->last_error ) {
				return null;
			}
		}
		return array( 'cursor' => $cursor, 'deleted' => $deleted, 'updated' => $updated, 'complete' => count( $rows ) < self::LIMIT );
	}

	/**
	 * Match MSLS's WPLANG convention (empty means en_US).
	 * Unknown or duplicate languages cannot identify a destination safely.
	 *
	 * @return array<string, int|null>|null
	 */
	public static function language_sites(): ?array {
		global $wpdb;

		$map = array();
		$offset = 0;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND deleted = 0 AND archived = 0 AND spam = 0 ORDER BY blog_id LIMIT 100 OFFSET %d",
				get_current_network_id(),
				$offset
			) );
			if ( $wpdb->last_error ) {
				return null;
			}
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					$language = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'WPLANG'" );
					if ( $wpdb->last_error ) {
						return null;
					}
					$language = $language ? (string) $language : 'en_US';
					$map[ $language ] = array_key_exists( $language, $map ) ? null : (int) $id;
				} finally {
					restore_current_blog();
				}
			}
			$offset += 100;
		} while ( 100 === count( $ids ) );
		return $map;
	}

	/** Remove only positive numeric references with a uniquely identified target. */
	public static function prune_links( array $links, bool $is_term, array $sites ): ?array {
		foreach ( $links as $language => $id ) {
			if ( ! is_int( $id ) && ( ! is_string( $id ) || ! preg_match( '/\A[1-9][0-9]*\z/', $id ) ) ) {
				continue;
			}
			$target = $sites[ $language ] ?? null;
			$id = filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( ! $target || false === $id ) {
				continue;
			}
			switch_to_blog( (int) $target );
			try {
				$exists = self::owner_exists( $id, $is_term );
			} finally {
				restore_current_blog();
			}
			if ( null === $exists ) {
				return null;
			}
			if ( ! $exists ) {
				unset( $links[ $language ] );
			}
		}
		return $links;
	}

	/** A failed query must never be interpreted as a missing object. */
	private static function owner_exists( int $id, bool $is_term ): ?bool {
		global $wpdb;

		$table = $is_term ? $wpdb->terms : $wpdb->posts;
		$column = $is_term ? 'term_id' : 'ID';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$column} = %d LIMIT 1", $id ) );
		return $wpdb->last_error ? null : null !== $found;
	}

	/** MSLS delete() uses delete_option(); fall back when MSLS is inactive. */
	private static function delete( string $name, int $id, bool $is_term ): bool {
		$class = $is_term ? \lloc\Msls\Options\Tax\Tax::class : \lloc\Msls\Options\Post\Post::class;
		if ( class_exists( $class ) && is_array( get_option( $name ) ) ) {
			$options = new $class( $id );
			if ( $options->get_option_name() === $name ) {
				$options->delete();
				return false === get_option( $name );
			}
		}
		return delete_option( $name );
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
