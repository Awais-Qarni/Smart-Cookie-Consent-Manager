<?php
/**
 * Admin tab: Banner — where it appears, how it looks, what it says.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_pages     = get_pages( array( 'post_status' => 'publish' ) );
$sccm_defaults  = SCCM_Settings::default_texts();
$sccm_cat_defs  = SCCM_Categories::defaults();
$sccm_long        = array( 'banner_text', 'about_text', 'gpc_notice', 'placeholder_text' );
$sccm_text_groups = array(
	__( 'Words in the banner', 'smart-cookie-consent-manager' )   => array(
		'banner_title'  => __( 'Title', 'smart-cookie-consent-manager' ),
		'banner_text'   => __( 'Text (Consent tab)', 'smart-cookie-consent-manager' ),
		'tab_consent'   => __( 'Tab: Consent', 'smart-cookie-consent-manager' ),
		'tab_details'   => __( 'Tab: Details', 'smart-cookie-consent-manager' ),
		'tab_about'     => __( 'Tab: About', 'smart-cookie-consent-manager' ),
		'btn_accept'    => __( 'Accept button', 'smart-cookie-consent-manager' ),
		'btn_selection' => __( '"Allow selection" button', 'smart-cookie-consent-manager' ),
		'btn_reject'    => __( 'Reject button', 'smart-cookie-consent-manager' ),
		'btn_details'   => __( '"Show details" link', 'smart-cookie-consent-manager' ),
		'about_text'    => __( 'Text (About tab)', 'smart-cookie-consent-manager' ),
		'policy_link'   => __( 'Cookie Policy link', 'smart-cookie-consent-manager' ),
		'privacy_link'  => __( 'Privacy Policy link', 'smart-cookie-consent-manager' ),
		'close'         => __( 'Close label', 'smart-cookie-consent-manager' ),
	),
	__( 'Words in the cookie details', 'smart-cookie-consent-manager' ) => array(
		'no_cookies'          => __( 'Empty category text', 'smart-cookie-consent-manager' ),
		'provider_site'       => __( 'Provider name for your own cookies', 'smart-cookie-consent-manager' ),
		'col_duration'        => __( '"Maximum storage duration" label', 'smart-cookie-consent-manager' ),
		'col_type'            => __( '"Type" label', 'smart-cookie-consent-manager' ),
		'type_cookie'         => __( 'Type: cookie', 'smart-cookie-consent-manager' ),
		'type_localStorage'   => __( 'Type: local storage', 'smart-cookie-consent-manager' ),
		'type_sessionStorage' => __( 'Type: session storage', 'smart-cookie-consent-manager' ),
		'col_name'            => __( 'Cookie Policy table: name', 'smart-cookie-consent-manager' ),
		'col_provider'        => __( 'Cookie Policy table: provider', 'smart-cookie-consent-manager' ),
		'col_purpose'         => __( 'Cookie Policy table: purpose', 'smart-cookie-consent-manager' ),
	),
	__( 'Words about the visitor\'s consent', 'smart-cookie-consent-manager' ) => array(
		'consent_status'    => __( '"Your current choice" label', 'smart-cookie-consent-manager' ),
		'consent_date'      => __( '"Date" label', 'smart-cookie-consent-manager' ),
		'consent_id'        => __( '"Your consent ID" label', 'smart-cookie-consent-manager' ),
		'no_choice'         => __( 'No choice made yet', 'smart-cookie-consent-manager' ),
		'copy'              => __( 'Copy button', 'smart-cookie-consent-manager' ),
		'copied'            => __( 'Copied message', 'smart-cookie-consent-manager' ),
		'choice_accept_all' => __( 'Status: allowed all', 'smart-cookie-consent-manager' ),
		'choice_reject_all' => __( 'Status: denied', 'smart-cookie-consent-manager' ),
		'choice_custom'     => __( 'Status: selection', 'smart-cookie-consent-manager' ),
		'choice_gpc'        => __( 'Status: GPC honoured', 'smart-cookie-consent-manager' ),
	),
	__( 'Other messages', 'smart-cookie-consent-manager' )          => array(
		'settings_button'  => __( 'Cookie settings button: accessible name', 'smart-cookie-consent-manager' ),
		'widget_label'     => __( 'Cookie settings button: text on the edge tab', 'smart-cookie-consent-manager' ),
		'gpc_notice'       => __( 'Privacy signal (GPC) notice (%s = categories)', 'smart-cookie-consent-manager' ),
		'placeholder_text' => __( 'Blocked video/map text (%s = category)', 'smart-cookie-consent-manager' ),
		'placeholder_btn'  => __( 'Blocked video/map button', 'smart-cookie-consent-manager' ),
	),
);

SCCM_Admin::form_open( 'banner' );
?>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Where should the banner appear?', 'smart-cookie-consent-manager' ); ?></h2>
	<?php
	SCCM_Admin::picker(
		'position',
		$settings['position'],
		array(
			'bottom'       => __( 'Bar at the bottom', 'smart-cookie-consent-manager' ),
			'top'          => __( 'Bar at the top', 'smart-cookie-consent-manager' ),
			'bottom-left'  => __( 'Box, bottom left', 'smart-cookie-consent-manager' ),
			'bottom-right' => __( 'Box, bottom right', 'smart-cookie-consent-manager' ),
			'center'       => __( 'Window in the centre', 'smart-cookie-consent-manager' ),
		),
		'banner'
	);
	?>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::select(
			'button_order',
			__( 'Button order', 'smart-cookie-consent-manager' ),
			$settings['button_order'],
			array(
				'accept_first' => __( 'Allow all · Allow selection · Deny', 'smart-cookie-consent-manager' ),
				'reject_first' => __( 'Deny · Allow selection · Allow all', 'smart-cookie-consent-manager' ),
			),
			__( 'All three buttons always have the same size, colour and style, which is what the law asks for. With equal buttons either order is fine; "Deny first" is the more cautious choice that some regulators prefer.', 'smart-cookie-consent-manager' )
		);
		?>
	</table>
	<p><a href="<?php echo esc_url( home_url( '/#sccm-banner' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the banner on your website (save first)', 'smart-cookie-consent-manager' ); ?> &rarr;</a></p>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Cookie settings button', 'smart-cookie-consent-manager' ); ?></h2>
	<p class="description"><?php esc_html_e( 'After a visitor has chosen, this small button lets them change their mind at any time. In a corner it is a round icon. At the bottom centre or on the left or right edge it is a slim tab with the word "Cookies" (change the word under "Other messages").', 'smart-cookie-consent-manager' ); ?></p>
	<table class="form-table" role="presentation">
		<?php SCCM_Admin::checkbox( 'floating_button', __( 'Show the button', 'smart-cookie-consent-manager' ), $settings['floating_button'], __( 'You can also add the shortcode <code>[sccm_cookie_settings]</code> or a menu link to <code>#sccm-preferences</code>.', 'smart-cookie-consent-manager' ) ); ?>
	</table>
	<?php
	SCCM_Admin::picker(
		'floating_position',
		$settings['floating_position'],
		array(
			'bottom-left'   => __( 'Bottom left', 'smart-cookie-consent-manager' ),
			'bottom-center' => __( 'Bottom centre', 'smart-cookie-consent-manager' ),
			'bottom-right'  => __( 'Bottom right', 'smart-cookie-consent-manager' ),
			'left-center'   => __( 'Left edge, middle', 'smart-cookie-consent-manager' ),
			'right-center'  => __( 'Right edge, middle', 'smart-cookie-consent-manager' ),
		),
		'widget'
	);
	?>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Colours', 'smart-cookie-consent-manager' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Allow all, Allow selection and Deny always look the same, so no choice is pushed on the visitor. This is required for valid consent.', 'smart-cookie-consent-manager' ); ?></p>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::input( 'color_background', __( 'Background', 'smart-cookie-consent-manager' ), $settings['color_background'], 'color' );
		SCCM_Admin::input( 'color_text', __( 'Text', 'smart-cookie-consent-manager' ), $settings['color_text'], 'color' );
		SCCM_Admin::input( 'color_button_bg', __( 'Buttons', 'smart-cookie-consent-manager' ), $settings['color_button_bg'], 'color' );
		SCCM_Admin::input( 'color_button_text', __( 'Button text', 'smart-cookie-consent-manager' ), $settings['color_button_text'], 'color' );
		SCCM_Admin::input( 'color_link', __( 'Links', 'smart-cookie-consent-manager' ), $settings['color_link'], 'color' );
		SCCM_Admin::input( 'color_toggle_on', __( 'Switch (on)', 'smart-cookie-consent-manager' ), $settings['color_toggle_on'], 'color' );
		SCCM_Admin::input( 'border_radius', __( 'Rounded corners (px)', 'smart-cookie-consent-manager' ), $settings['border_radius'], 'number', '', array( 'min' => 0, 'max' => 32 ) );
		?>
	</table>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Links in the banner', 'smart-cookie-consent-manager' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sccm-policy-page"><?php esc_html_e( 'Cookie Policy page', 'smart-cookie-consent-manager' ); ?></label></th>
			<td>
				<select id="sccm-policy-page" name="sccm[policy_page_id]">
					<option value="0"><?php esc_html_e( '— None —', 'smart-cookie-consent-manager' ); ?></option>
					<?php foreach ( $sccm_pages as $sccm_page ) : ?>
						<option value="<?php echo (int) $sccm_page->ID; ?>" <?php selected( (int) $settings['policy_page_id'], (int) $sccm_page->ID ); ?>><?php echo esc_html( $sccm_page->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo wp_kses( __( 'Add the shortcode <code>[sccm_cookie_policy]</code> to that page to show your cookie list. It updates by itself. No page yet? The Dashboard can create one for you.', 'smart-cookie-consent-manager' ), array( 'code' => array() ) ); ?></p>
			</td>
		</tr>
		<?php SCCM_Admin::checkbox( 'show_privacy_link', __( 'Link to Privacy Policy', 'smart-cookie-consent-manager' ), $settings['show_privacy_link'], __( 'Uses the page chosen in Settings → Privacy.', 'smart-cookie-consent-manager' ) ); ?>
	</table>
</div>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Category names and descriptions', 'smart-cookie-consent-manager' ); ?></summary>
	<p class="description"><?php esc_html_e( 'The four categories are fixed (they work with Google Consent Mode). You can rename them, rewrite their descriptions, or hide the ones your site does not use. Leave a field empty to use the default.', 'smart-cookie-consent-manager' ); ?></p>
	<?php foreach ( SCCM_Categories::KEYS as $sccm_key ) : ?>
		<?php $sccm_cat = isset( $settings['categories'][ $sccm_key ] ) ? $settings['categories'][ $sccm_key ] : array(); ?>
		<h3><span class="sccm-dot sccm-dot--<?php echo esc_attr( $sccm_key ); ?>"></span> <?php echo esc_html( $sccm_cat_defs[ $sccm_key ]['label'] ); ?></h3>
		<table class="form-table" role="presentation">
			<?php
			if ( 'necessary' !== $sccm_key ) {
				SCCM_Admin::checkbox( array( 'categories', $sccm_key, 'enabled' ), __( 'Show to visitors', 'smart-cookie-consent-manager' ), $sccm_cat['enabled'] ?? 1, __( 'A hidden category stays refused and its content stays blocked.', 'smart-cookie-consent-manager' ) );
			}
			SCCM_Admin::input( array( 'categories', $sccm_key, 'label' ), __( 'Name', 'smart-cookie-consent-manager' ), $sccm_cat['label'] ?? '', 'text', '', array( 'placeholder' => $sccm_cat_defs[ $sccm_key ]['label'] ) );
			SCCM_Admin::textarea( array( 'categories', $sccm_key, 'description' ), __( 'Description', 'smart-cookie-consent-manager' ), $sccm_cat['description'] ?? '', '', $sccm_cat_defs[ $sccm_key ]['description'] );
			?>
		</table>
	<?php endforeach; ?>
</details>

<?php foreach ( $sccm_text_groups as $sccm_group => $sccm_fields ) : ?>
	<details class="sccm-panel sccm-fold">
		<summary><?php echo esc_html( $sccm_group ); ?></summary>
		<p class="description"><?php esc_html_e( 'Leave a field empty to use the default text (it is translated automatically for other languages). Links, bold and italic are allowed.', 'smart-cookie-consent-manager' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			foreach ( $sccm_fields as $sccm_key => $sccm_label ) {
				$sccm_value = isset( $settings['texts'][ $sccm_key ] ) ? $settings['texts'][ $sccm_key ] : '';
				if ( in_array( $sccm_key, $sccm_long, true ) ) {
					SCCM_Admin::textarea( array( 'texts', $sccm_key ), $sccm_label, $sccm_value, '', $sccm_defaults[ $sccm_key ], 4 );
				} else {
					SCCM_Admin::input( array( 'texts', $sccm_key ), $sccm_label, $sccm_value, 'text', '', array( 'placeholder' => $sccm_defaults[ $sccm_key ] ) );
				}
			}
			?>
		</table>
	</details>
<?php endforeach; ?>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Custom CSS (advanced)', 'smart-cookie-consent-manager' ); ?></summary>
	<table class="form-table" role="presentation">
		<?php SCCM_Admin::textarea( 'custom_css', __( 'CSS', 'smart-cookie-consent-manager' ), $settings['custom_css'], __( 'Optional. Elements use the <code>sccm-</code> prefix, e.g. <code>.sccm-banner</code>, <code>.sccm-btn</code>, <code>.sccm-modal</code>.', 'smart-cookie-consent-manager' ), '', 6 ); ?>
	</table>
</details>

<?php
SCCM_Admin::form_close();
