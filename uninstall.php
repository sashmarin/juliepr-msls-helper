<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Settings.php';

// A plugin can be active in multiple networks; respect each network's choice.
$offset = 0;
do {
	$network_ids = is_multisite()
		? get_networks( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) )
		: array( get_current_network_id() );

	foreach ( $network_ids as $network_id ) {
		$settings = get_network_option( (int) $network_id, \Juliepr\MslsHelper\Settings::OPTION, array() );
		if ( is_array( $settings ) && isset( $settings['delete_on_uninstall'] )
			&& in_array( $settings['delete_on_uninstall'], array( true, 1, '1' ), true ) ) {
			delete_network_option( (int) $network_id, \Juliepr\MslsHelper\Settings::OPTION );
		}
	}

	$offset += 100;
} while ( is_multisite() && 100 === count( $network_ids ) );
