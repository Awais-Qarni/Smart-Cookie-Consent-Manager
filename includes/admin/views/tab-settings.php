<?php
/**
 * Admin tab: Settings — how long consent lasts, privacy signals, blocking, scanner and email.
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
$sccm_queue    = (array) get_option( 'sccm_pending_alert', array() );

// How long a visitor's choice is remembered. 0 = until the browser is closed.
$sccm_expiry_presets = array(
	0   => __( 'Until the browser is closed', 'smart-cookie-consent-manager' ),
	7   => __( '1 week', 'smart-cookie-consent-manager' ),
	30  => __( '1 month', 'smart-cookie-consent-manager' ),
	90  => __( '3 months', 'smart-cookie-consent-manager' ),
	180 => __( '6 months', 'smart-cookie-consent-manager' ),
	365 => __( '12 months (most common)', 'smart-cookie-consent-manager' ),
	395 => __( '13 months (the longest browsers allow)', 'smart-cookie-consent-manager' ),
);
$sccm_expiry         = (int) $settings['consent_expiry_days'];

SCCM_Admin::form_open( 'settings' );
?>
<input type="hidden" name="sccm_services_form" value="1">

<details class="sccm-panel sccm-fold" open>
	<summary><?php esc_html_e( 'When should visitors be asked again?', 'smart-cookie-consent-manager' ); ?></summary>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="sccm-expiry-preset"><?php esc_html_e( 'Remember the choice for', 'smart-cookie-consent-manager' ); ?></label></th>
			<td>
				<select id="sccm-expiry-preset" data-sccm-expiry-preset>
					<?php foreach ( $sccm_expiry_presets as $sccm_days => $sccm_label ) : ?>
						<option value="<?php echo (int) $sccm_days; ?>" <?php selected( isset( $sccm_expiry_presets[ $sccm_expiry ] ) ? $sccm_expiry : -1, $sccm_days ); ?>><?php echo esc_html( $sccm_label ); ?></option>
					<?php endforeach; ?>
					<option value="custom" <?php selected( ! isset( $sccm_expiry_presets[ $sccm_expiry ] ), true ); ?>><?php esc_html_e( 'Another number of days…', 'smart-cookie-consent-manager' ); ?></option>
				</select>
				<span class="sccm-expiry-days" <?php echo isset( $sccm_expiry_presets[ $sccm_expiry ] ) ? 'hidden' : ''; ?>>
					<input type="number" id="sccm-consent_expiry_days" class="small-text" name="sccm[consent_expiry_days]" min="0" max="395" value="<?php echo (int) $sccm_expiry; ?>"> <?php esc_html_e( 'days', 'smart-cookie-consent-manager' ); ?>
				</span>
				<p class="description"><?php esc_html_e( 'After this time the banner is shown again. Regulators often suggest 6 to 12 months. 395 days (13 months) is the maximum, because browsers do not keep a cookie longer. 0 means the choice is forgotten when the browser is closed.', 'smart-cookie-consent-manager' ); ?></p>
			</td>
		</tr>
		<?php
		SCCM_Admin::checkbox( 'reask_on_change', __( 'Ask again when the cookie list changes', 'smart-cookie-consent-manager' ), $settings['reask_on_change'], __( 'When a new cookie is added or moved to another category, every visitor is asked again, because they agreed to an older list. A scan that finds nothing new never asks again.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::input( 'reject_grace_days', __( 'Do not ask again after a "Reject" for (days)', 'smart-cookie-consent-manager' ), $settings['reject_grace_days'], 'number', __( 'Optional. 0 = no waiting period. Some rules ask you not to bother visitors again for about 6 months (180 days) after they refuse.', 'smart-cookie-consent-manager' ), array( 'min' => 0, 'max' => 395 ) );
		SCCM_Admin::checkbox( 'reload_on_withdraw', __( 'Reload the page when consent is withdrawn', 'smart-cookie-consent-manager' ), $settings['reload_on_withdraw'], __( 'A script that already ran cannot be stopped, so the page reloads to stop it.', 'smart-cookie-consent-manager' ) );
		?>
	</table>
</details>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Browser privacy signal (Global Privacy Control)', 'smart-cookie-consent-manager' ); ?></summary>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::checkbox( 'gpc_enabled', __( 'Respect the signal', 'smart-cookie-consent-manager' ), $settings['gpc_enabled'], __( 'Some browsers tell websites "do not track me". When that happens, the chosen categories count as refused, no banner is needed, and the choice is recorded. <a href="https://globalprivacycontrol.org/" target="_blank" rel="noopener">About GPC</a>', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::select(
			'gpc_scope',
			__( 'The signal applies to', 'smart-cookie-consent-manager' ),
			$settings['gpc_scope'],
			array(
				'all'       => __( 'All non-essential cookies (recommended)', 'smart-cookie-consent-manager' ),
				'marketing' => __( 'Marketing only', 'smart-cookie-consent-manager' ),
			)
		);
		?>
	</table>
</details>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Blocking and Google', 'smart-cookie-consent-manager' ); ?></summary>

	<h3><?php esc_html_e( 'Google Consent Mode v2', 'smart-cookie-consent-manager' ); ?></h3>
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
			__( 'In Strict and Advanced mode, consent is "denied" before any Google tag runs and is updated when the visitor chooses.', 'smart-cookie-consent-manager' )
		);
		SCCM_Admin::checkbox( 'ads_data_redaction', __( 'Redact ads data', 'smart-cookie-consent-manager' ), $settings['ads_data_redaction'], __( 'Removes ad click identifiers when marketing consent is denied.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::checkbox( 'url_passthrough', __( 'URL passthrough', 'smart-cookie-consent-manager' ), $settings['url_passthrough'], __( 'Passes ad click information through URLs when cookies are denied. Leave off unless your marketing team needs it.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::input( 'gtm_id', __( 'Google Tag Manager ID', 'smart-cookie-consent-manager' ), $settings['gtm_id'], 'text', __( 'Optional, e.g. GTM-XXXXXXX. The plugin then loads GTM with correct consent handling. Leave empty if your theme or another plugin already adds it (it is still blocked until consent).', 'smart-cookie-consent-manager' ), array( 'placeholder' => 'GTM-XXXXXXX' ) );
		SCCM_Admin::input( 'ga4_id', __( 'Google Analytics 4 ID', 'smart-cookie-consent-manager' ), $settings['ga4_id'], 'text', __( 'Optional, e.g. G-XXXXXXXXXX. Only if GA4 is not added in another way.', 'smart-cookie-consent-manager' ), array( 'placeholder' => 'G-XXXXXXXXXX' ) );
		?>
	</table>

	<h3><?php esc_html_e( 'Automatic blocking', 'smart-cookie-consent-manager' ); ?></h3>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::checkbox( 'blocker_enabled', __( 'Block scripts, videos and fonts until consent', 'smart-cookie-consent-manager' ), $settings['blocker_enabled'], __( 'Matching tags in the page are switched off until the visitor allows their category. You can also tag a script yourself: <code>&lt;script type="text/plain" data-sccm-category="analytics"&gt;</code>', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::checkbox( 'iframe_placeholder', __( 'Show a message instead of a blocked video or map', 'smart-cookie-consent-manager' ), $settings['iframe_placeholder'], __( 'Visitors see a short text with an "Allow and load" button instead of an empty space.', 'smart-cookie-consent-manager' ) );
		?>
	</table>

	<h3><?php esc_html_e( 'Known services', 'smart-cookie-consent-manager' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Blocked automatically when found on your pages. Untick a service only if you are sure it does not need consent.', 'smart-cookie-consent-manager' ); ?></p>
	<table class="widefat striped sccm-services">
		<thead><tr>
			<th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Block', 'smart-cookie-consent-manager' ); ?></span></th>
			<th><?php esc_html_e( 'Service', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Recognised by', 'smart-cookie-consent-manager' ); ?></th>
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

	<h3><?php esc_html_e( 'Your own blocking rules', 'smart-cookie-consent-manager' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Block any other script, video or stylesheet whose address or code contains the text you enter (for example a domain name).', 'smart-cookie-consent-manager' ); ?></p>
	<table class="widefat sccm-rules" id="sccm-rules">
		<thead><tr>
			<th><?php esc_html_e( 'Address or code contains', 'smart-cookie-consent-manager' ); ?></th>
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
	<p><button type="button" class="button" id="sccm-add-rule"><?php esc_html_e( 'Add a rule', 'smart-cookie-consent-manager' ); ?></button></p>
</details>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Cookie scan and email alerts', 'smart-cookie-consent-manager' ); ?></summary>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::select(
			'scan_schedule',
			__( 'Scan my website', 'smart-cookie-consent-manager' ),
			$settings['scan_schedule'],
			array(
				'daily'  => __( 'Every day', 'smart-cookie-consent-manager' ),
				'weekly' => __( 'Every week', 'smart-cookie-consent-manager' ),
				'off'    => __( 'Never (only when I click "Scan now")', 'smart-cookie-consent-manager' ),
			),
			__( 'The scan opens your home page, cookie policy and latest pages and posts, and looks for cookies and third-party services.', 'smart-cookie-consent-manager' )
		);
		SCCM_Admin::checkbox( 'scanner_client', __( 'Also learn from visitors', 'smart-cookie-consent-manager' ), $settings['scanner_client'], __( 'Catches cookies that only JavaScript sets, which a scan cannot see. Only cookie names are sent (never values), never from logged-in users, and a cookie is listed only after several different visitors reported it, so your list stays short.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::checkbox( 'alerts_enabled', __( 'Email about scans and cookie changes', 'smart-cookie-consent-manager' ), $settings['alerts_enabled'], __( 'A report after each scan ("Scan now" and scheduled scans). Cookies learned from visitors between scans are emailed a few minutes after they are added to the banner.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::select(
			'alert_mode',
			__( 'Scan report', 'smart-cookie-consent-manager' ),
			$settings['alert_mode'],
			array(
				'every_scan' => __( 'After every scan, also when nothing changed', 'smart-cookie-consent-manager' ),
				'changes'    => __( 'Only when a scan found something new', 'smart-cookie-consent-manager' ),
			)
		);
		$sccm_recipients = str_replace( ', ', "\n", (string) $settings['alert_email'] );
		SCCM_Admin::textarea(
			'alert_email',
			__( 'Send the email to', 'smart-cookie-consent-manager' ),
			$sccm_recipients,
			sprintf(
				/* translators: %s: the site admin email address */
				__( 'One email address per line (up to 10). Leave empty to use the site admin email: %s', 'smart-cookie-consent-manager' ),
				'<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
			),
			"name@example.com\nteam@example.com",
			3
		);
		SCCM_Admin::checkbox(
			'alert_include_admin',
			__( 'Also send to the site admin', 'smart-cookie-consent-manager' ),
			$settings['alert_include_admin'],
			sprintf(
				/* translators: %s: the site admin email address */
				__( 'The admin email address from Settings → General: %s', 'smart-cookie-consent-manager' ),
				'<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
			)
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Try it', 'smart-cookie-consent-manager' ); ?></th>
			<td>
				<button type="submit" class="button" form="sccm-sample-email-form"><?php esc_html_e( 'Send me a sample email', 'smart-cookie-consent-manager' ); ?></button>
				<button type="submit" class="button" form="sccm-digest-form" <?php disabled( empty( $sccm_queue ) ); ?>>
					<?php
					/* translators: %d: items waiting */
					printf( esc_html__( 'Send waiting alerts now (%d)', 'smart-cookie-consent-manager' ), count( $sccm_queue ) );
					?>
				</button>
				<p class="description"><?php esc_html_e( 'Save your changes first. The sample goes to the addresses saved above.', 'smart-cookie-consent-manager' ); ?></p>
				<?php $sccm_mail = get_option( SCCM_Scanner::MAIL_OPTION ); ?>
				<?php if ( is_array( $sccm_mail ) ) : ?>
					<?php $sccm_when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_mail['time'] ); ?>
					<?php if ( ! empty( $sccm_mail['ok'] ) ) : ?>
						<p class="sccm-mail-status sccm-mail-status--ok">
							<?php
							/* translators: 1: date and time, 2: email addresses */
							echo esc_html( sprintf( __( 'Last email: %1$s, handed to your server for %2$s. If it did not arrive, check the spam folder; some hosts need an SMTP plugin (e.g. WP Mail SMTP) to deliver mail.', 'smart-cookie-consent-manager' ), $sccm_when, implode( ', ', (array) $sccm_mail['to'] ) ) );
							?>
						</p>
					<?php else : ?>
						<p class="sccm-mail-status sccm-mail-status--error">
							<?php
							/* translators: 1: date and time, 2: error message */
							echo esc_html( sprintf( __( 'Last email failed (%1$s): %2$s Your server could not send email. Install an SMTP plugin (e.g. WP Mail SMTP) or ask your host.', 'smart-cookie-consent-manager' ), $sccm_when, $sccm_mail['error'] ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</details>

<details class="sccm-panel sccm-fold">
	<summary><?php esc_html_e( 'Consent records (the proof)', 'smart-cookie-consent-manager' ); ?></summary>
	<table class="form-table" role="presentation">
		<?php
		SCCM_Admin::checkbox( 'log_enabled', __( 'Keep a record of every choice', 'smart-cookie-consent-manager' ), $settings['log_enabled'], __( 'Each choice is saved with its consent ID, date, categories and page. Keep this on if you may have to prove consent.', 'smart-cookie-consent-manager' ) );
		SCCM_Admin::select(
			'ip_mode',
			__( 'Visitor IP address', 'smart-cookie-consent-manager' ),
			$settings['ip_mode'],
			array(
				'anonymize' => __( 'Save it shortened (last part removed)', 'smart-cookie-consent-manager' ),
				'hash'      => __( 'Save it as a one-way code', 'smart-cookie-consent-manager' ),
				'none'      => __( 'Do not save it', 'smart-cookie-consent-manager' ),
			)
		);
		SCCM_Admin::input( 'retention_months', __( 'Keep records for (months)', 'smart-cookie-consent-manager' ), $settings['retention_months'], 'number', __( 'Older records are deleted automatically every day. 0 = keep forever.', 'smart-cookie-consent-manager' ), array( 'min' => 0, 'max' => 120 ) );
		?>
	</table>
</details>

<?php SCCM_Admin::form_close(); ?>

<?php // Small forms that the buttons above point to with the "form" attribute (forms cannot be nested). ?>
<form id="sccm-sample-email-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php SCCM_Admin::action_fields( 'send_test_email' ); ?>
</form>
<form id="sccm-digest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php SCCM_Admin::action_fields( 'send_digest' ); ?>
</form>
