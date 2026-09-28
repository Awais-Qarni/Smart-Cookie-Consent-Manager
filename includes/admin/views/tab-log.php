<?php
/**
 * Admin tab: Consent Log.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_filters = SCCM_Admin::log_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sccm_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sccm_result  = SCCM_Consent_Log::query( array_merge( $sccm_filters, array( 'page' => $sccm_page, 'per_page' => 50 ) ) );
$sccm_pages   = max( 1, (int) ceil( $sccm_result['total'] / 50 ) );
$sccm_choices = array(
	''           => __( 'All choices', 'smart-cookie-consent-manager' ),
	'accept_all' => __( 'Accept all', 'smart-cookie-consent-manager' ),
	'reject_all' => __( 'Reject non-essential', 'smart-cookie-consent-manager' ),
	'custom'     => __( 'Custom', 'smart-cookie-consent-manager' ),
	'gpc'        => __( 'GPC signal', 'smart-cookie-consent-manager' ),
);
?>
<p><?php esc_html_e( 'Evidence of every choice: consent ID (shown to the visitor in the preferences window), date and time (UTC), choice, allowed categories, GPC signal, consent version and page.', 'smart-cookie-consent-manager' ); ?></p>

<form method="get" class="sccm-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( SCCM_Admin::SLUG ); ?>">
	<input type="hidden" name="tab" value="log">
	<label><span class="screen-reader-text"><?php esc_html_e( 'Consent ID', 'smart-cookie-consent-manager' ); ?></span>
		<input type="search" name="search" value="<?php echo esc_attr( $sccm_filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Consent ID', 'smart-cookie-consent-manager' ); ?>"></label>
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

<h2><?php esc_html_e( 'Log settings', 'smart-cookie-consent-manager' ); ?></h2>
<?php SCCM_Admin::form_open( 'log' ); ?>
<table class="form-table" role="presentation">
	<?php
	SCCM_Admin::checkbox( 'log_enabled', __( 'Record consents', 'smart-cookie-consent-manager' ), $settings['log_enabled'] );
	SCCM_Admin::select(
		'ip_mode',
		__( 'Visitor IP address', 'smart-cookie-consent-manager' ),
		$settings['ip_mode'],
		array(
			'anonymize' => __( 'Store anonymised (last part removed)', 'smart-cookie-consent-manager' ),
			'hash'      => __( 'Store as a one-way hash', 'smart-cookie-consent-manager' ),
			'none'      => __( 'Do not store', 'smart-cookie-consent-manager' ),
		)
	);
	SCCM_Admin::input( 'retention_months', __( 'Keep records for (months)', 'smart-cookie-consent-manager' ), $settings['retention_months'], 'number', __( 'Older records are deleted automatically every day. 0 = keep forever.', 'smart-cookie-consent-manager' ), array( 'min' => 0, 'max' => 120 ) );
	?>
</table>
<?php SCCM_Admin::form_close(); ?>

<div class="sccm-inline-forms">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'purge_log' ); ?>
		<button class="button"><?php esc_html_e( 'Delete records older than the retention period now', 'smart-cookie-consent-manager' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php SCCM_Admin::action_fields( 'delete_log' ); ?>
		<button class="button button-link-delete" data-sccm-confirm><?php esc_html_e( 'Delete all consent records', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>
