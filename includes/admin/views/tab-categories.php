<?php
/**
 * Admin tab: Categories.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_defaults = SCCM_Categories::defaults();

SCCM_Admin::form_open( 'categories' );
?>
<p><?php esc_html_e( 'The four categories are fixed so they work with Google Consent Mode and exports. You can rename them, change their descriptions, and hide the ones your site does not use.', 'smart-cookie-consent-manager' ); ?></p>
<?php foreach ( SCCM_Categories::KEYS as $sccm_key ) : ?>
	<?php $sccm_cat = isset( $settings['categories'][ $sccm_key ] ) ? $settings['categories'][ $sccm_key ] : array(); ?>
	<h2><?php echo esc_html( $sccm_defaults[ $sccm_key ]['label'] ); ?> <code><?php echo esc_html( $sccm_key ); ?></code></h2>
	<table class="form-table" role="presentation">
		<?php
		if ( 'necessary' !== $sccm_key ) {
			SCCM_Admin::checkbox( array( 'categories', $sccm_key, 'enabled' ), __( 'Show to visitors', 'smart-cookie-consent-manager' ), $sccm_cat['enabled'] ?? 1, __( 'Hidden categories stay refused; content in them stays blocked.', 'smart-cookie-consent-manager' ) );
		}
		SCCM_Admin::input( array( 'categories', $sccm_key, 'label' ), __( 'Label', 'smart-cookie-consent-manager' ), $sccm_cat['label'] ?? '', 'text', '', array( 'placeholder' => $sccm_defaults[ $sccm_key ]['label'] ) );
		SCCM_Admin::textarea( array( 'categories', $sccm_key, 'description' ), __( 'Description', 'smart-cookie-consent-manager' ), $sccm_cat['description'] ?? '', '', $sccm_defaults[ $sccm_key ]['description'] );
		?>
	</table>
<?php endforeach; ?>
<?php
SCCM_Admin::form_close();
