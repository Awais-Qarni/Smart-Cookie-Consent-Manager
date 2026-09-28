<?php
/**
 * Admin tab: Texts.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_defaults = SCCM_Settings::default_texts();
$sccm_long     = array( 'banner_text', 'prefs_text', 'gpc_notice', 'placeholder_text' );
$sccm_labels   = array(
	'banner_title'     => __( 'Banner title', 'smart-cookie-consent-manager' ),
	'banner_text'      => __( 'Banner text', 'smart-cookie-consent-manager' ),
	'btn_accept'       => __( 'Accept button', 'smart-cookie-consent-manager' ),
	'btn_reject'       => __( 'Reject button', 'smart-cookie-consent-manager' ),
	'btn_manage'       => __( 'Manage button', 'smart-cookie-consent-manager' ),
	'btn_save'         => __( 'Save button', 'smart-cookie-consent-manager' ),
	'prefs_title'      => __( 'Preferences title', 'smart-cookie-consent-manager' ),
	'prefs_text'       => __( 'Preferences text', 'smart-cookie-consent-manager' ),
	'always_on'        => __( '"Always active" label', 'smart-cookie-consent-manager' ),
	'show_cookies'     => __( '"Show cookies" label', 'smart-cookie-consent-manager' ),
	'no_cookies'       => __( 'Empty category text', 'smart-cookie-consent-manager' ),
	'col_name'         => __( 'Column: name', 'smart-cookie-consent-manager' ),
	'col_provider'     => __( 'Column: provider', 'smart-cookie-consent-manager' ),
	'col_purpose'      => __( 'Column: purpose', 'smart-cookie-consent-manager' ),
	'col_duration'     => __( 'Column: duration', 'smart-cookie-consent-manager' ),
	'policy_link'      => __( 'Cookie Policy link', 'smart-cookie-consent-manager' ),
	'privacy_link'     => __( 'Privacy Policy link', 'smart-cookie-consent-manager' ),
	'settings_button'  => __( 'Cookie settings button', 'smart-cookie-consent-manager' ),
	'close'            => __( 'Close label', 'smart-cookie-consent-manager' ),
	'consent_id'       => __( 'Consent ID label', 'smart-cookie-consent-manager' ),
	'gpc_notice'       => __( 'GPC notice (%s = categories)', 'smart-cookie-consent-manager' ),
	'placeholder_text' => __( 'Blocked content text (%s = category)', 'smart-cookie-consent-manager' ),
	'placeholder_btn'  => __( 'Blocked content button', 'smart-cookie-consent-manager' ),
	'saved'            => __( 'Saved message', 'smart-cookie-consent-manager' ),
);

SCCM_Admin::form_open( 'texts' );
?>
<p><?php esc_html_e( 'Leave a field empty to use the default text (which is translated automatically for other languages). Links, bold and italic are allowed.', 'smart-cookie-consent-manager' ); ?></p>
<table class="form-table" role="presentation">
	<?php
	foreach ( $sccm_labels as $sccm_key => $sccm_label ) {
		$sccm_value = isset( $settings['texts'][ $sccm_key ] ) ? $settings['texts'][ $sccm_key ] : '';
		if ( in_array( $sccm_key, $sccm_long, true ) ) {
			SCCM_Admin::textarea( array( 'texts', $sccm_key ), $sccm_label, $sccm_value, '', $sccm_defaults[ $sccm_key ], 4 );
		} else {
			SCCM_Admin::input( array( 'texts', $sccm_key ), $sccm_label, $sccm_value, 'text', '', array( 'placeholder' => $sccm_defaults[ $sccm_key ] ) );
		}
	}
	?>
</table>
<?php
SCCM_Admin::form_close();
