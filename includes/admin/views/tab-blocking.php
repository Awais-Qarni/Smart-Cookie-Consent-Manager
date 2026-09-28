<?php
/**
 * Admin tab: Blocking (Consent Mode, tags, services, custom rules).
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_disabled = (array) $settings['disabled_services'];
$sccm_rules    = (array) $settings['rules'];
$sccm_rules[]  = array(
	'pattern'  => '',
	'category' => 'marketing',
);
$sccm_optional = SCCM_Admin::category_options( true );

SCCM_Admin::form_open( 'blocking' );
?>
<h2><?php esc_html_e( 'Google Consent Mode v2', 'smart-cookie-consent-manager' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::select(
		'consent_mode',
		__( 'Mode', 'smart-cookie-consent-manager' ),
		$settings['consent_mode'],
		array(
			'basic'    => __( 'Strict: block Google tags until consent (recommended)', 'smart-cookie-consent-manager' ),
			'advanced' => __( 'Advanced: load Google tags, cookieless until consent', 'smart-cookie-consent-manager' ),
			'off'      => __( 'Off: do not send Consent Mode signals', 'smart-cookie-consent-manager' ),
		),
		__( 'In both Strict and Advanced mode, consent defaults to "denied" before any tag runs and is updated when the visitor chooses.', 'smart-cookie-consent-manager' )
	);
	SCCM_Admin::checkbox( 'ads_data_redaction', __( 'Redact ads data', 'smart-cookie-consent-manager' ), $settings['ads_data_redaction'], __( 'Removes ad click identifiers when advertising consent is denied.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::checkbox( 'url_passthrough', __( 'URL passthrough', 'smart-cookie-consent-manager' ), $settings['url_passthrough'], __( 'Passes ad click information through URLs when cookies are denied. Leave off unless your marketing team needs it.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::input( 'gtm_id', __( 'Google Tag Manager ID', 'smart-cookie-consent-manager' ), $settings['gtm_id'], 'text', __( 'Optional, e.g. GTM-XXXXXXX. Let the plugin load GTM with correct consent handling. Leave empty if GTM is already added by your theme or another plugin (it will still be blocked).', 'smart-cookie-consent-manager' ), array( 'placeholder' => 'GTM-XXXXXXX' ) );
	SCCM_Admin::input( 'ga4_id', __( 'Google Analytics 4 ID', 'smart-cookie-consent-manager' ), $settings['ga4_id'], 'text', __( 'Optional, e.g. G-XXXXXXXXXX. Only if GA4 is not added in another way.', 'smart-cookie-consent-manager' ), array( 'placeholder' => 'G-XXXXXXXXXX' ) );
	?>
</table>

<h2><?php esc_html_e( 'Automatic blocking', 'smart-cookie-consent-manager' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::checkbox( 'blocker_enabled', __( 'Block scripts, iframes and fonts until consent', 'smart-cookie-consent-manager' ), $settings['blocker_enabled'], __( 'Rewrites matching tags in the page HTML so they only load after the visitor allows their category. You can also tag scripts yourself: <code>&lt;script type="text/plain" data-sccm-category="analytics"&gt;</code>', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::checkbox( 'iframe_placeholder', __( 'Placeholder for blocked videos/maps', 'smart-cookie-consent-manager' ), $settings['iframe_placeholder'], __( 'Shows a message with an "Allow and load" button instead of an empty space.', 'smart-cookie-consent-manager' ) );
	?>
</table>

<h3><?php esc_html_e( 'Known services', 'smart-cookie-consent-manager' ); ?></h3>
<p class="description"><?php esc_html_e( 'Blocked automatically when found on your pages. Untick a service only if you are sure it does not need consent.', 'smart-cookie-consent-manager' ); ?></p>
<table class="widefat striped sccm-services">
	<thead><tr>
		<th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Block', 'smart-cookie-consent-manager' ); ?></span></th>
		<th><?php esc_html_e( 'Service', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Matches', 'smart-cookie-consent-manager' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( SCCM_Services::active_candidates() as $sccm_key => $sccm_service ) : ?>
		<tr>
			<td><input type="checkbox" name="sccm_services[]" value="<?php echo esc_attr( $sccm_key ); ?>" id="sccm-svc-<?php echo esc_attr( $sccm_key ); ?>" <?php checked( ! in_array( $sccm_key, $sccm_disabled, true ) ); ?>></td>
			<td><label for="sccm-svc-<?php echo esc_attr( $sccm_key ); ?>"><strong><?php echo esc_html( $sccm_service['name'] ); ?></strong></label><?php echo ! empty( $sccm_service['google'] ) ? ' <span class="sccm-tag">Consent Mode</span>' : ''; ?></td>
			<td><?php echo esc_html( SCCM_Categories::label( $sccm_service['category'] ) ); ?></td>
			<td>
				<?php foreach ( array_slice( $sccm_service['patterns'], 0, 3 ) as $sccm_pattern ) : ?>
					<code><?php echo esc_html( $sccm_pattern ); ?></code>
				<?php endforeach; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?php esc_html_e( 'Custom blocking rules', 'smart-cookie-consent-manager' ); ?></h3>
<p class="description"><?php esc_html_e( 'Block any other script, iframe or stylesheet whose URL or inline code contains the text you enter (e.g. a domain name).', 'smart-cookie-consent-manager' ); ?></p>
<table class="widefat sccm-rules" id="sccm-rules">
	<thead><tr>
		<th><?php esc_html_e( 'URL or code contains', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th>
		<th></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $sccm_rules as $sccm_i => $sccm_rule ) : ?>
		<tr>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( SCCM_Admin::name( array( 'rules', $sccm_i, 'pattern' ) ) ); ?>" value="<?php echo esc_attr( $sccm_rule['pattern'] ); ?>" placeholder="example-tracker.com/script.js"></td>
			<td><select name="<?php echo esc_attr( SCCM_Admin::name( array( 'rules', $sccm_i, 'category' ) ) ); ?>">
				<?php foreach ( $sccm_optional as $sccm_value => $sccm_label ) : ?>
					<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_rule['category'], $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<td><button type="button" class="button-link sccm-remove-row"><?php esc_html_e( 'Remove', 'smart-cookie-consent-manager' ); ?></button></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<p><button type="button" class="button" id="sccm-add-rule"><?php esc_html_e( 'Add rule', 'smart-cookie-consent-manager' ); ?></button></p>
<?php
SCCM_Admin::form_close();
