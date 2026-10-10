<?php

declare(strict_types=1);

namespace Juliepr\MslsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network-wide helper settings, independent of MSLS availability.
 */
final class Settings {
	public const OPTION = 'juliepr_msls_helper_settings';
	private const PAGE = 'juliepr-msls-helper';
	private const SAVE_ACTION = 'juliepr_msls_helper_save';
	private const DEFAULTS = array(
		'shared_media'        => true,
		'copy_post_fields'    => true,
		'rewrite_links'       => true,
		'fallback_redirect'   => true,
		'fallback_blog_id'    => 1,
		'delete_on_uninstall' => false,
	);

	public static function register(): void {
		add_action( 'network_admin_menu', array( self::class, 'add_page' ) );
		add_action( 'network_admin_edit_' . self::SAVE_ACTION, array( self::class, 'save' ) );
	}

	/**
	 * @return array<string, bool|int>
	 */
	public static function get(): array {
		$stored = get_site_option( self::OPTION, array() );
		$result = self::DEFAULTS;

		if ( is_array( $stored ) ) {
			foreach ( $result as $key => $default ) {
				if ( array_key_exists( $key, $stored ) ) {
					$result[ $key ] = 'fallback_blog_id' === $key
						? ( filter_var( $stored[ $key ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ?: 1 )
						: in_array( $stored[ $key ], array( true, 1, '1' ), true );
				}
			}
		}

		return $result;
	}

	public static function add_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'Juliepr MSLS Helper', 'juliepr-msls-helper' ),
			'MSLS Helper',
			'manage_network_options',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'juliepr-msls-helper' ) );
		}

		$settings = self::get();
		$fields   = array(
			'shared_media'        => array(
				'label'       => __( 'Enable global media library integration', 'juliepr-msls-helper' ),
				'description' => __( 'Use shared featured image IDs during MSLS Content Import. When disabled, MSLS uses its standard importer.', 'juliepr-msls-helper' ),
			),
			'copy_post_fields'    => array(
				'label'       => __( 'Copy the source date, slug and author', 'juliepr-msls-helper' ),
				'description' => __( 'Apply to new translations created through Quick Create or Content Import.', 'juliepr-msls-helper' ),
			),
			'rewrite_links'       => array(
				'label'       => __( 'Rewrite imported links to the translation site', 'juliepr-msls-helper' ),
				'description' => __( 'Replace the source site in text links during Quick Create and Content Import. Links to uploads and image URLs are preserved.', 'juliepr-msls-helper' ),
			),
			'fallback_redirect'   => array(
				'label'       => __( 'Redirect missing translations to the source site', 'juliepr-msls-helper' ),
				'description' => __( 'On a 404 page, redirect with HTTP 302 only if a published post or page with the same path exists on the configured source site. Uploads are excluded.', 'juliepr-msls-helper' ),
			),
			'fallback_blog_id'    => array(
				'label'       => __( 'Source site ID for 404 redirects', 'juliepr-msls-helper' ),
				'description' => __( 'Default: 1. Choose an active site in this network. Redirects are never applied on the source site itself.', 'juliepr-msls-helper' ),
			),
			'delete_on_uninstall' => array(
				'label'       => __( 'Delete settings when uninstalling the plugin', 'juliepr-msls-helper' ),
				'description' => __( 'Remove only the helper settings when the plugin is deleted. Posts and media are preserved.', 'juliepr-msls-helper' ),
			),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Juliepr MSLS Helper', 'juliepr-msls-helper' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect notice.
			if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'juliepr-msls-helper' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SAVE_ACTION ) ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php if ( 'fallback_blog_id' === $key ) : ?>
									<input type="number" min="1" step="1" class="small-text" id="<?php echo esc_attr( $key ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" required />
								<?php else : ?>
									<input type="checkbox" id="<?php echo esc_attr( $key ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $settings[ $key ] ); ?> />
								<?php endif; ?>
								<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'juliepr-msls-helper' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$posted = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$values = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( 'fallback_blog_id' === $key ) {
				$values[ $key ] = filter_var( $posted[ $key ] ?? 1, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			} else {
				$values[ $key ] = isset( $posted[ $key ] ) && '1' === $posted[ $key ];
			}
		}
		$source_site = $values['fallback_blog_id'] ? get_site( $values['fallback_blog_id'] ) : null;
		if ( false === $values['fallback_blog_id']
			|| ( $values['fallback_redirect'] && ( ! $source_site
				|| (int) $source_site->site_id !== get_current_network_id()
				|| $source_site->deleted || $source_site->archived || $source_site->spam ) ) ) {
			wp_die( esc_html__( 'Enter a positive source site ID. When redirects are enabled, the source must be an active site in this network.', 'juliepr-msls-helper' ) );
		}

		update_site_option( self::OPTION, $values );
		wp_safe_redirect( network_admin_url( 'settings.php?page=' . self::PAGE . '&updated=1' ) );
		exit;
	}
}
