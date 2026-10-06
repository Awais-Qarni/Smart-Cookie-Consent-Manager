<?php
/**
 * Plugin Name:       Smart Cookie Consent Manager
 * Plugin URI:        https://github.com/Awais-Qarni/Smart-Cookie-Consent-Manager
 * Description:       Plug-and-play cookie consent: Accept / Reject / Manage Preferences banner, blocking until consent, Google Consent Mode v2, GPC, consent records, cookie scanner and an automatic cookie policy.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Muhammad Awais
 * Author URI:        https://github.com/Awais-Qarni
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-cookie-consent-manager
 * Domain Path:       /languages
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

define( 'SCCM_VERSION', '0.1.0' );
define( 'SCCM_FILE', __FILE__ );
define( 'SCCM_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCCM_URL', plugin_dir_url( __FILE__ ) );
define( 'SCCM_BASENAME', plugin_basename( __FILE__ ) );

require_once SCCM_PATH . 'includes/class-sccm-install.php';
require_once SCCM_PATH . 'includes/class-sccm-settings.php';
require_once SCCM_PATH . 'includes/class-sccm-categories.php';
require_once SCCM_PATH . 'includes/class-sccm-services.php';
require_once SCCM_PATH . 'includes/class-sccm-cookies.php';
require_once SCCM_PATH . 'includes/class-sccm-consent-log.php';
require_once SCCM_PATH . 'includes/class-sccm-blocker.php';
require_once SCCM_PATH . 'includes/class-sccm-frontend.php';
require_once SCCM_PATH . 'includes/class-sccm-rest.php';
require_once SCCM_PATH . 'includes/class-sccm-scanner.php';
require_once SCCM_PATH . 'includes/class-sccm-shortcodes.php';
require_once SCCM_PATH . 'includes/class-sccm-cache.php';
require_once SCCM_PATH . 'includes/class-sccm-plugin.php';

if ( is_admin() ) {
	require_once SCCM_PATH . 'includes/admin/class-sccm-admin.php';
}

register_activation_hook( __FILE__, array( 'SCCM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCCM_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SCCM_Plugin', 'instance' ) );
