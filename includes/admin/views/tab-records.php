<?php
/**
 * Admin tab: Consent Records — the proof of every visitor's choice.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_filters = SCCM_Admin::log_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sccm_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sccm_result  = SCCM_Consent_Log::query( array_merge( $sccm_filters, array( 'page' => $sccm_page, 'per_page' => 50 ) ) );
$sccm_db_error = $GLOBALS['wpdb']->last_error;
$sccm_pages   = max( 1, (int) ceil( $sccm_result['total'] / 50 ) );
$sccm_choices = array(
	''           => __( 'All choices', 'smart-cookie-consent-manager' ),
	'accept_all' => __( 'Allowed all', 'smart-cookie-consent-manager' ),
	'reject_all' => __( 'Denied', 'smart-cookie-consent-manager' ),
	'custom'     => __( 'Allowed a selection', 'smart-cookie-consent-manager' ),
	'gpc'        => __( 'GPC signal', 'smart-cookie-consent-manager' ),
);
?>
<p><?php esc_html_e( 'Every time a visitor chooses, a record is saved here: their consent ID, the date and time (UTC), what they chose, which categories they allowed and on which page. A visitor can read their consent ID in the About tab of the cookie banner, so you can find their record by pasting it in the search box.', 'smart-cookie-consent-manager' ); ?></p>

<?php
$sccm_problem = get_option( SCCM_REST::REST_PROBLEM_OPTION );
if ( is_array( $sccm_problem ) && time() - (int) $sccm_problem['time'] < WEEK_IN_SECONDS ) :
	?>
	<div class="notice notice-info inline"><p>
		<?php
		printf(
			/* translators: 1: HTTP status code or "blocked", 2: date and time */
			esc_html__( 'Your site blocks the WordPress REST API for visitors (%1$s, last on %2$s), so records are saved through admin-ajax.php instead. That is fine. If new records do not appear below, click "Test record saving".', 'smart-cookie-consent-manager' ),
			$sccm_problem['status'] ? 'HTTP ' . (int) $sccm_problem['status'] : esc_html__( 'blocked', 'smart-cookie-consent-manager' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_problem['time'] ) )
		);
		?>
	</p></div>
<?php endif; ?>
<?php if ( $sccm_db_error ) : ?>
	<div class="notice notice-error inline"><p>
		<?php
		printf(
			/* translators: 1: database error, 2: WordPress version */
			esc_html__( 'The records could not be read from the database: %1$s (WordPress %2$s; the plugin needs 6.2 or later).', 'smart-cookie-consent-manager' ),
			esc_html( $sccm_db_error ),
			esc_html( get_bloginfo( 'version' ) )
		);
		?>
	</p></div>
<?php endif; ?>
<?php
$sccm_failure = get_option( SCCM_REST::CONSENT_PROBLEM_OPTION );
if ( is_array( $sccm_failure ) && time() - (int) $sccm_failure['time'] < WEEK_IN_SECONDS ) :
	?>
	<div class="notice notice-error inline"><p>
		<?php
		printf(
			/* translators: 1: date and time, 2: reason */
			esc_html__( 'A visitor\'s record could not be saved (last on %1$s): %2$s', 'smart-cookie-consent-manager' ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_failure['time'] ) ),
			esc_html( $sccm_failure['message'] )
		);
		?>
	</p></div>
<?php endif; ?>
<?php if ( ! $sccm_result['total'] && ! array_filter( $sccm_filters ) ) : ?>
	<div class="notice notice-info inline"><p>
		<?php
		if ( empty( $settings['log_enabled'] ) ) {
			esc_html_e( 'Records are switched off: Settings → Consent records → "Keep a record of every choice".', 'smart-cookie-consent-manager' );
		} else {
			esc_html_e( 'A record is saved when a visitor clicks Allow all, Deny or Allow selection. To test: open your website in a private window, click a button, then reload this page. If you just updated the plugin, clear your page cache first, because old cached pages still carry the old script.', 'smart-cookie-consent-manager' );
		}
		?>
	</p></div>
<?php endif; ?>

<form method="get" class="sccm-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( SCCM_Admin::SLUG ); ?>">
	<input type="hidden" name="tab" value="records">
	<label><span class="screen-reader-text"><?php esc_html_e( 'Consent ID or IP address', 'smart-cookie-consent-manager' ); ?></span>
		<input type="search" name="search" value="<?php echo esc_attr( $sccm_filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Consent ID or IP address', 'smart-cookie-consent-manager' ); ?>"></label>
	<label><span class="screen-reader-text"><?php esc_html_e( 'Choice', 'smart-cookie-consent-manager' ); ?></span>
		<select name="choice">
			<?php foreach ( $sccm_choices as $sccm_value => $sccm_label ) : ?>
				<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_filters['choice'] ?? '', $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
			<?php endforeach; ?>
		</select></label>
	<label><?php esc_html_e( 'From', 'smart-cookie-consent-manager' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $sccm_filters['from'] ?? '' ); ?>"></label>
	<label><?php esc_html_e( 'To', 'smart-cookie-consent-manager' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $sccm_filters['to'] ?? '' ); ?>"></label>
	<button class="button"><?php esc_html_e( 'Filter', 'smart-cookie-consent-manager' ); ?></button>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
	<?php SCCM_Admin::action_fields( 'export_log' ); ?>
	<?php foreach ( $sccm_filters as $sccm_key => $sccm_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $sccm_key ); ?>" value="<?php echo esc_attr( $sccm_value ); ?>">
	<?php endforeach; ?>
	<button class="button button-primary">
		<?php
		/* translators: %d: number of records */
		printf( esc_html__( 'Export CSV (%d records)', 'smart-cookie-consent-manager' ), (int) $sccm_result['total'] );
		?>
	</button>
</form>

<table class="widefat striped sccm-table-admin">
	<thead><tr>
		<th><?php esc_html_e( 'Date / time (UTC)', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Consent ID', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Choice', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Categories', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'GPC', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Version', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'Page', 'smart-cookie-consent-manager' ); ?></th>
		<th><?php esc_html_e( 'IP', 'smart-cookie-consent-manager' ); ?></th>
	</tr></thead>
	<tbody>
	<?php if ( ! $sccm_result['rows'] ) : ?>
		<tr><td colspan="8"><?php esc_html_e( 'No consent records yet.', 'smart-cookie-consent-manager' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $sccm_result['rows'] as $sccm_row ) : ?>
		<tr>
			<td><?php echo esc_html( $sccm_row['created_at'] ); ?></td>
			<td><code><?php echo esc_html( $sccm_row['consent_id'] ); ?></code></td>
			<td><?php echo esc_html( $sccm_choices[ $sccm_row['choice'] ] ?? $sccm_row['choice'] ); ?></td>
			<td><?php echo esc_html( implode( ', ', array_map( array( 'SCCM_Categories', 'label' ), explode( ',', $sccm_row['categories'] ) ) ) ); ?></td>
			<td><?php echo $sccm_row['gpc'] ? esc_html__( 'Yes', 'smart-cookie-consent-manager' ) : esc_html__( 'No', 'smart-cookie-consent-manager' ); ?></td>
			<td><?php echo (int) $sccm_row['consent_version']; ?></td>
			<td class="sccm-break"><?php echo esc_html( wp_parse_url( $sccm_row['url'], PHP_URL_PATH ) ?? '' ); ?></td>
			<td><?php echo esc_html( $sccm_row['ip'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $sccm_pages > 1 ) : ?>
	<div class="tablenav"><div class="tablenav-pages">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $sccm_page,
					'total'   => $sccm_pages,
				)
			)
		);
		?>
	</div></div>
<?php endif; ?>

<p class="description">
	<?php
	printf(
		/* translators: %s: link to the settings tab */
		esc_html__( 'How long records are kept, and how the IP address is stored, is set in %s.', 'smart-cookie-consent-manager' ),
		'<a href="' . esc_url( SCCM_Admin::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'smart-cookie-consent-manager' ) . '</a>'
	);
	?>
</p>

<div class="sccm-inline-forms">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'test_record' ); ?>
		<button class="button"><?php esc_html_e( 'Test record saving', 'smart-cookie-consent-manager' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'purge_log' ); ?>
		<button class="button"><?php esc_html_e( 'Delete records older than the retention period now', 'smart-cookie-consent-manager' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'delete_log' ); ?>
		<button class="button button-link-delete" data-sccm-confirm><?php esc_html_e( 'Delete all consent records', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>
