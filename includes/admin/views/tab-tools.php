<?php
/**
 * Admin tab: Tools.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sccm-panel">
	<h2><?php esc_html_e( 'Ask everyone again', 'smart-cookie-consent-manager' ); ?></h2>
	<?php $sccm_version = (int) get_option( 'sccm_consent_version', 1 ); ?>
	<p>
		<?php
		/* translators: 1: current banner version, 2: what is known about it, 3: next version */
		printf( esc_html__( 'Current banner version: %1$d (%2$s). Asking everyone again starts banner version %3$d: every visitor sees the banner again, for example after you change your cookie use or policy. Their new records show version %3$d, so you can tell them apart from older ones in Consent Records.', 'smart-cookie-consent-manager' ), (int) $sccm_version, esc_html( SCCM_Cookies::version_note( $sccm_version ) ? SCCM_Cookies::version_note( $sccm_version ) : __( 'no date known', 'smart-cookie-consent-manager' ) ), (int) $sccm_version + 1 );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'bump_version' ); ?>
		<button class="button button-primary" data-sccm-confirm><?php esc_html_e( 'Ask all visitors again', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Use these settings on another website', 'smart-cookie-consent-manager' ); ?></h2>
	<p><?php esc_html_e( 'Export all settings and the cookie list to a file, then import it on another website that uses this plugin.', 'smart-cookie-consent-manager' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
		<?php SCCM_Admin::action_fields( 'export_settings' ); ?>
		<button class="button"><?php esc_html_e( 'Export settings', 'smart-cookie-consent-manager' ); ?></button>
	</form>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-import">
		<?php SCCM_Admin::action_fields( 'import_settings' ); ?>
		<p><label for="sccm-import-file"><?php esc_html_e( 'Settings file (.json)', 'smart-cookie-consent-manager' ); ?></label><br>
			<input type="file" id="sccm-import-file" name="import_file" accept=".json,application/json" required></p>
		<p><label><input type="checkbox" name="with_cookies" value="1" checked> <?php esc_html_e( 'Also import the cookie list', 'smart-cookie-consent-manager' ); ?></label></p>
		<button class="button" data-sccm-confirm><?php esc_html_e( 'Import settings', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Uninstall', 'smart-cookie-consent-manager' ); ?></h2>
	<?php SCCM_Admin::form_open( 'tools' ); ?>
	<table class="form-table" role="presentation">
		<?php SCCM_Admin::checkbox( 'delete_on_uninstall', __( 'Delete all data when the plugin is deleted', 'smart-cookie-consent-manager' ), $settings['delete_on_uninstall'], __( 'Removes settings, the cookie list and all consent records. Keep this off if you may need the records as evidence.', 'smart-cookie-consent-manager' ) ); ?>
	</table>
	<?php SCCM_Admin::form_close(); ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'reset_settings' ); ?>
		<button class="button button-link-delete" data-sccm-confirm><?php esc_html_e( 'Reset settings to defaults', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>

<p class="description">
	<?php
	printf(
		/* translators: %s: link to the Help tab */
		esc_html__( 'Shortcodes, links and code for developers are on the %s page.', 'smart-cookie-consent-manager' ),
		'<a href="' . esc_url( SCCM_Admin::url( 'help' ) . '#sccm-help-shortcodes' ) . '">' . esc_html__( 'Help', 'smart-cookie-consent-manager' ) . '</a>'
	);
	?>
</p>
