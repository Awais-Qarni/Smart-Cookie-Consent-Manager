<?php
/**
 * Uninstall: remove data only if the site owner enabled "Delete all data on uninstall".
 *
 * @package SmartCookieConsentManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-sccm-settings.php';
require_once __DIR__ . '/includes/class-sccm-install.php';

/**
 * Remove this site's data if its owner opted in.
 */
function sccm_uninstall_site() {
	$settings = get_option( 'sccm_settings', array() );
	if ( ! empty( $settings['delete_on_uninstall'] ) ) {
		SCCM_Install::remove_all_data();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sccm_site_id ) {
		switch_to_blog( $sccm_site_id );
		sccm_uninstall_site();
		restore_current_blog();
	}
} else {
	sccm_uninstall_site();
}
