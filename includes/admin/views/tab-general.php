<?php
/**
 * Admin tab: General (status overview + main settings).
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_counts  = SCCM_Cookies::counts();
$sccm_stats   = SCCM_Consent_Log::stats( 30 );
$sccm_scan    = get_option( SCCM_Scanner::RESULT_OPTION );
$sccm_version = (int) get_option( 'sccm_consent_version', 1 );
$sccm_pages   = get_pages( array( 'post_status' => 'publish' ) );
?>
<div class="sccm-cards">
	<div class="sccm-card">
		<h3><?php esc_html_e( 'Status', 'smart-cookie-consent-manager' ); ?></h3>
		<p><strong><?php echo $settings['enabled'] ? esc_html__( 'Banner active', 'smart-cookie-consent-manager' ) : esc_html__( 'Banner disabled', 'smart-cookie-consent-manager' ); ?></strong></p>
		<p>
			<?php
			/* translators: %d: version number */
			printf( esc_html__( 'Consent version: %d', 'smart-cookie-consent-manager' ), (int) $sccm_version );
			?>
		</p>
		<p><a href="<?php echo esc_url( home_url( '/#sccm-banner' ) ); ?>" target="_blank"><?php esc_html_e( 'Preview banner on the website', 'smart-cookie-consent-manager' ); ?></a></p>
	</div>
	<div class="sccm-card">
		<h3><?php esc_html_e( 'Cookies', 'smart-cookie-consent-manager' ); ?></h3>
		<p>
			<?php
			/* translators: 1: active, 2: pending */
			printf( esc_html__( '%1$d listed, %2$d waiting for review', 'smart-cookie-consent-manager' ), (int) $sccm_counts['active'], (int) $sccm_counts['pending'] );
			?>
		</p>
		<p>
			<?php
			if ( $sccm_scan ) {
				/* translators: %s: date */
				printf( esc_html__( 'Last scan: %s', 'smart-cookie-consent-manager' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_scan['time'] ) ) );
			} else {
				esc_html_e( 'No scan yet.', 'smart-cookie-consent-manager' );
			}
			?>
		</p>
		<p><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>"><?php esc_html_e( 'Manage cookies', 'smart-cookie-consent-manager' ); ?></a></p>
	</div>
	<div class="sccm-card">
		<h3><?php esc_html_e( 'Consents (last 30 days)', 'smart-cookie-consent-manager' ); ?></h3>
		<ul>
			<li><?php echo esc_html__( 'Accept all', 'smart-cookie-consent-manager' ) . ': ' . (int) $sccm_stats['accept_all']; ?></li>
			<li><?php echo esc_html__( 'Reject non-essential', 'smart-cookie-consent-manager' ) . ': ' . (int) $sccm_stats['reject_all']; ?></li>
			<li><?php echo esc_html__( 'Custom', 'smart-cookie-consent-manager' ) . ': ' . (int) $sccm_stats['custom']; ?></li>
			<li><?php echo esc_html__( 'GPC signal', 'smart-cookie-consent-manager' ) . ': ' . (int) $sccm_stats['gpc']; ?></li>
		</ul>
	</div>
</div>

<?php SCCM_Admin::form_open( 'general' ); ?>
<h2><?php esc_html_e( 'Banner', 'smart-cookie-consent-manager' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::checkbox( 'enabled', __( 'Show cookie banner', 'smart-cookie-consent-manager' ), $settings['enabled'], __( 'When disabled, nothing is shown and nothing is blocked.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::select(
		'position',
		__( 'Position', 'smart-cookie-consent-manager' ),
		$settings['position'],
		array(
			'bottom'       => __( 'Bar at the bottom', 'smart-cookie-consent-manager' ),
			'top'          => __( 'Bar at the top', 'smart-cookie-consent-manager' ),
			'bottom-left'  => __( 'Box, bottom left', 'smart-cookie-consent-manager' ),
			'bottom-right' => __( 'Box, bottom right', 'smart-cookie-consent-manager' ),
			'center'       => __( 'Window in the centre', 'smart-cookie-consent-manager' ),
		)
	);
	SCCM_Admin::checkbox( 'floating_button', __( 'Floating "Cookie settings" button', 'smart-cookie-consent-manager' ), $settings['floating_button'], __( 'Lets visitors change or withdraw consent at any time. You can also use the shortcode <code>[sccm_cookie_settings]</code> or a menu link to <code>#sccm-preferences</code>.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::select(
		'floating_position',
		__( 'Floating button position', 'smart-cookie-consent-manager' ),
		$settings['floating_position'],
		array(
			'bottom-left'  => __( 'Bottom left', 'smart-cookie-consent-manager' ),
			'bottom-right' => __( 'Bottom right', 'smart-cookie-consent-manager' ),
		)
	);
	?>
	<tr>
		<th scope="row"><label for="sccm-policy-page"><?php esc_html_e( 'Cookie Policy page', 'smart-cookie-consent-manager' ); ?></label></th>
		<td>
			<select id="sccm-policy-page" name="sccm[policy_page_id]">
				<option value="0"><?php esc_html_e( '— None —', 'smart-cookie-consent-manager' ); ?></option>
				<?php foreach ( $sccm_pages as $sccm_page ) : ?>
					<option value="<?php echo (int) $sccm_page->ID; ?>" <?php selected( (int) $settings['policy_page_id'], (int) $sccm_page->ID ); ?>><?php echo esc_html( $sccm_page->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php echo wp_kses( __( 'Linked from the banner. Add the shortcode <code>[sccm_cookie_policy]</code> to the page to show the cookie list; it updates automatically.', 'smart-cookie-consent-manager' ), array( 'code' => array() ) ); ?></p>
		</td>
	</tr>
	<?php
	SCCM_Admin::checkbox( 'show_privacy_link', __( 'Link to Privacy Policy', 'smart-cookie-consent-manager' ), $settings['show_privacy_link'], __( 'Uses the page set in Settings → Privacy.', 'smart-cookie-consent-manager' ) );
	?>
</table>

<h2><?php esc_html_e( 'Remembering and asking again', 'smart-cookie-consent-manager' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::input( 'consent_expiry_days', __( 'Remember choice for (days)', 'smart-cookie-consent-manager' ), $settings['consent_expiry_days'], 'number', __( 'After this period the banner is shown again. 365 days is common; the maximum is 395 (13 months).', 'smart-cookie-consent-manager' ), array( 'min' => 1, 'max' => 395 ) );
	SCCM_Admin::checkbox( 'reask_on_change', __( 'Ask again when the cookie list changes', 'smart-cookie-consent-manager' ), $settings['reask_on_change'], __( 'When a new cookie is added or moved to another category, every visitor is asked again.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::input( 'reject_grace_days', __( 'Do not ask again after a Reject for (days)', 'smart-cookie-consent-manager' ), $settings['reject_grace_days'], 'number', __( 'Optional. 0 = no waiting period. Some upcoming EU rules require not asking again within 6 months (180 days) after a refusal.', 'smart-cookie-consent-manager' ), array( 'min' => 0, 'max' => 395 ) );
	SCCM_Admin::checkbox( 'reload_on_withdraw', __( 'Reload page when consent is withdrawn', 'smart-cookie-consent-manager' ), $settings['reload_on_withdraw'], __( 'Scripts that already ran cannot be stopped, so the page reloads to stop them.', 'smart-cookie-consent-manager' ) );
	?>
</table>

<h2><?php esc_html_e( 'Privacy signals', 'smart-cookie-consent-manager' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::checkbox( 'gpc_enabled', __( 'Honour Global Privacy Control (GPC)', 'smart-cookie-consent-manager' ), $settings['gpc_enabled'], __( 'If a visitor\'s browser sends a GPC signal, the categories below are treated as refused and the choice is recorded. <a href="https://globalprivacycontrol.org/" target="_blank">About GPC</a>', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::select(
		'gpc_scope',
		__( 'GPC applies to', 'smart-cookie-consent-manager' ),
		$settings['gpc_scope'],
		array(
			'all'       => __( 'All non-essential cookies (recommended)', 'smart-cookie-consent-manager' ),
			'marketing' => __( 'Tracking / Advertising only', 'smart-cookie-consent-manager' ),
		)
	);
	?>
</table>
<?php SCCM_Admin::form_close(); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
	<?php SCCM_Admin::action_fields( 'create_policy_page' ); ?>
	<p><button type="submit" class="button"><?php esc_html_e( 'Create a Cookie Policy page for me', 'smart-cookie-consent-manager' ); ?></button></p>
</form>
