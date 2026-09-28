<?php
/**
 * Admin tab: Appearance.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

SCCM_Admin::form_open( 'appearance' );
?>
<p><?php esc_html_e( 'Accept and Reject always use the same button style so neither choice is pushed.', 'smart-cookie-consent-manager' ); ?>
	<a href="<?php echo esc_url( home_url( '/#sccm-banner' ) ); ?>" target="_blank"><?php esc_html_e( 'Preview banner', 'smart-cookie-consent-manager' ); ?></a></p>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::input( 'color_background', __( 'Background', 'smart-cookie-consent-manager' ), $settings['color_background'], 'color' );
	SCCM_Admin::input( 'color_text', __( 'Text', 'smart-cookie-consent-manager' ), $settings['color_text'], 'color' );
	SCCM_Admin::input( 'color_button_bg', __( 'Buttons', 'smart-cookie-consent-manager' ), $settings['color_button_bg'], 'color' );
	SCCM_Admin::input( 'color_button_text', __( 'Button text', 'smart-cookie-consent-manager' ), $settings['color_button_text'], 'color' );
	SCCM_Admin::input( 'color_link', __( 'Links', 'smart-cookie-consent-manager' ), $settings['color_link'], 'color' );
	SCCM_Admin::input( 'color_toggle_on', __( 'Switch (on)', 'smart-cookie-consent-manager' ), $settings['color_toggle_on'], 'color' );
	SCCM_Admin::input( 'border_radius', __( 'Corner radius (px)', 'smart-cookie-consent-manager' ), $settings['border_radius'], 'number', '', array( 'min' => 0, 'max' => 32 ) );
	SCCM_Admin::textarea( 'custom_css', __( 'Custom CSS', 'smart-cookie-consent-manager' ), $settings['custom_css'], __( 'Optional. Elements use the <code>sccm-</code> prefix, e.g. <code>.sccm-banner</code>, <code>.sccm-btn</code>, <code>.sccm-modal</code>.', 'smart-cookie-consent-manager' ), '', 6 );
	?>
</table>
<?php
SCCM_Admin::form_close();
