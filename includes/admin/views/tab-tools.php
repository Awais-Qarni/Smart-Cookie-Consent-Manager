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
	<p>
		<?php
		/* translators: %d: version */
		printf( esc_html__( 'Current consent version: %d. Increasing it shows the banner again to every visitor, for example after you change your cookie use or policy.', 'smart-cookie-consent-manager' ), (int) get_option( 'sccm_consent_version', 1 ) );
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

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Developer reference', 'smart-cookie-consent-manager' ); ?></h2>
	<ul class="sccm-list">
		<li><code>[sccm_cookie_policy]</code> — <?php esc_html_e( 'cookie list grouped by category', 'smart-cookie-consent-manager' ); ?></li>
		<li><code>[sccm_cookie_settings text="Cookie settings" style="link|button"]</code> — <?php esc_html_e( 'opens the preferences window', 'smart-cookie-consent-manager' ); ?></li>
		<li><code>&lt;a href="#sccm-preferences"&gt;</code> — <?php esc_html_e( 'any link (e.g. a menu item) that opens the preferences window', 'smart-cookie-consent-manager' ); ?></li>
		<li><code>&lt;script type="text/plain" data-sccm-category="analytics"&gt;</code> — <?php esc_html_e( 'run a script only after consent', 'smart-cookie-consent-manager' ); ?></li>
		<li><code>window.SCCM.hasConsent('analytics')</code>, <code>document.addEventListener('sccm:consent', …)</code></li>
	</ul>
</div>
