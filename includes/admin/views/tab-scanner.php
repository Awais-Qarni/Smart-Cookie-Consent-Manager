<?php
/**
 * Admin tab: Scanner.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_scan     = get_option( SCCM_Scanner::RESULT_OPTION );
$sccm_queue    = (array) get_option( 'sccm_pending_alert', array() );
$sccm_optional = SCCM_Admin::category_options( true );

SCCM_Admin::form_open( 'scanner' );
?>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::checkbox( 'scanner_client', __( 'Detect cookies in visitors\' browsers', 'smart-cookie-consent-manager' ), $settings['scanner_client'], __( 'Browsers report the names (never the values) of cookies and local storage items that are not in your list yet. Catches cookies set by JavaScript.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::select(
		'scan_schedule',
		__( 'Automatic page scan', 'smart-cookie-consent-manager' ),
		$settings['scan_schedule'],
		array(
			'daily'  => __( 'Daily', 'smart-cookie-consent-manager' ),
			'weekly' => __( 'Weekly', 'smart-cookie-consent-manager' ),
			'off'    => __( 'Off', 'smart-cookie-consent-manager' ),
		),
		__( 'Fetches your home page, cookie policy and latest pages/posts, and looks for cookies and third-party services.', 'smart-cookie-consent-manager' )
	);
	SCCM_Admin::checkbox( 'alerts_enabled', __( 'Email alerts', 'smart-cookie-consent-manager' ), $settings['alerts_enabled'], __( 'A summary email, at most once a day, when new cookies or services are found.', 'smart-cookie-consent-manager' ) );
	SCCM_Admin::input( 'alert_email', __( 'Alert email address', 'smart-cookie-consent-manager' ), $settings['alert_email'], 'email', __( 'Leave empty to use the site admin email.', 'smart-cookie-consent-manager' ), array( 'placeholder' => get_option( 'admin_email' ) ) );
	?>
</table>
<?php SCCM_Admin::form_close(); ?>

<div class="sccm-inline-forms">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'scan_now' ); ?>
		<button class="button button-primary"><?php esc_html_e( 'Scan now', 'smart-cookie-consent-manager' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'send_digest' ); ?>
		<button class="button" <?php disabled( empty( $sccm_queue ) ); ?>>
			<?php
			/* translators: %d: items waiting */
			printf( esc_html__( 'Send alert email now (%d waiting)', 'smart-cookie-consent-manager' ), count( $sccm_queue ) );
			?>
		</button>
	</form>
</div>

<?php if ( $sccm_scan ) : ?>
	<h2>
		<?php
		/* translators: %s: date */
		printf( esc_html__( 'Last scan: %s', 'smart-cookie-consent-manager' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_scan['time'] ) ) );
		?>
	</h2>

	<?php foreach ( (array) $sccm_scan['notes'] as $sccm_note ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $sccm_note ); ?></p></div>
	<?php endforeach; ?>

	<h3><?php esc_html_e( 'Services detected', 'smart-cookie-consent-manager' ); ?></h3>
	<?php if ( empty( $sccm_scan['services'] ) ) : ?>
		<p><?php esc_html_e( 'No known third-party services found.', 'smart-cookie-consent-manager' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Service', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Found in', 'smart-cookie-consent-manager' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $sccm_scan['services'] as $sccm_service ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $sccm_service['name'] ); ?></strong></td>
					<td><?php echo esc_html( SCCM_Categories::label( $sccm_service['category'] ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $sccm_service['examples'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Other third-party resources', 'smart-cookie-consent-manager' ); ?></h3>
	<p class="description"><?php esc_html_e( 'External scripts, frames or stylesheets that are not in the service library and have no custom rule. If one of them tracks visitors, add a blocking rule.', 'smart-cookie-consent-manager' ); ?></p>
	<?php if ( empty( $sccm_scan['unclassified'] ) ) : ?>
		<p><?php esc_html_e( 'None found.', 'smart-cookie-consent-manager' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Host', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Example', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Block as', 'smart-cookie-consent-manager' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $sccm_scan['unclassified'] as $sccm_item ) : ?>
				<tr>
					<td><code><?php echo esc_html( $sccm_item['host'] ); ?></code> <small>(<?php echo esc_html( $sccm_item['tag'] ); ?>)</small></td>
					<td class="sccm-break"><?php echo esc_html( $sccm_item['url'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
							<?php SCCM_Admin::action_fields( 'add_rule' ); ?>
							<input type="hidden" name="pattern" value="<?php echo esc_attr( $sccm_item['host'] ); ?>">
							<select name="category" aria-label="<?php esc_attr_e( 'Category', 'smart-cookie-consent-manager' ); ?>">
								<?php foreach ( $sccm_optional as $sccm_value => $sccm_label ) : ?>
									<option value="<?php echo esc_attr( $sccm_value ); ?>"><?php echo esc_html( $sccm_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="button button-small"><?php esc_html_e( 'Add rule', 'smart-cookie-consent-manager' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Pages checked', 'smart-cookie-consent-manager' ); ?></h3>
	<ul class="sccm-list">
		<?php foreach ( (array) $sccm_scan['pages'] as $sccm_url => $sccm_code ) : ?>
			<li><code><?php echo esc_html( (string) $sccm_code ); ?></code> <?php echo esc_html( $sccm_url ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( ! empty( $sccm_scan['cookies'] ) ) : ?>
		<p>
			<?php
			/* translators: %s: cookie names */
			printf( esc_html__( 'Cookies set by the server: %s', 'smart-cookie-consent-manager' ), esc_html( implode( ', ', $sccm_scan['cookies'] ) ) );
			?>
		</p>
	<?php endif; ?>
<?php endif; ?>
