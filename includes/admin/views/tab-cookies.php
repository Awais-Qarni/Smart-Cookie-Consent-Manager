<?php
/**
 * Admin tab: Cookies (registry).
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$sccm_status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$sccm_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$sccm_edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$sccm_is_new  = isset( $_GET['new'] );
// phpcs:enable

$sccm_counts     = SCCM_Cookies::counts();
$sccm_rows       = SCCM_Cookies::query(
	array(
		'status' => in_array( $sccm_status, SCCM_Cookies::STATUSES, true ) ? $sccm_status : '',
		'search' => $sccm_search,
	)
);
$sccm_categories = SCCM_Admin::category_options();
$sccm_editing    = $sccm_edit_id ? SCCM_Cookies::get( $sccm_edit_id ) : null;
$sccm_types      = array(
	'cookie'         => __( 'Cookie', 'smart-cookie-consent-manager' ),
	'localStorage'   => __( 'Local storage', 'smart-cookie-consent-manager' ),
	'sessionStorage' => __( 'Session storage', 'smart-cookie-consent-manager' ),
);
?>
<p><?php esc_html_e( 'This list is shown to visitors in the preferences window and on the Cookie Policy page. Names may use * as a wildcard, e.g. _ga_*.', 'smart-cookie-consent-manager' ); ?></p>

<?php if ( $sccm_editing || $sccm_is_new ) : ?>
	<?php
	$sccm_row = $sccm_editing ? $sccm_editing : array(
		'name'     => '',
		'type'     => 'cookie',
		'category' => 'necessary',
		'provider' => '',
		'purpose'  => '',
		'duration' => '',
		'status'   => 'active',
	);
	?>
	<div class="sccm-panel">
		<h2><?php echo $sccm_editing ? esc_html__( 'Edit cookie', 'smart-cookie-consent-manager' ) : esc_html__( 'Add cookie', 'smart-cookie-consent-manager' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php SCCM_Admin::action_fields( 'cookie_save' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $sccm_edit_id; ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="sccm-c-name"><?php esc_html_e( 'Name', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-name" class="regular-text" name="cookie[name]" value="<?php echo esc_attr( $sccm_row['name'] ); ?>" required></td></tr>
				<tr><th scope="row"><label for="sccm-c-type"><?php esc_html_e( 'Type', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><select id="sccm-c-type" name="cookie[type]">
						<?php foreach ( $sccm_types as $sccm_value => $sccm_label ) : ?>
							<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_row['type'], $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="sccm-c-cat"><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><select id="sccm-c-cat" name="cookie[category]">
						<?php foreach ( $sccm_categories as $sccm_value => $sccm_label ) : ?>
							<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_row['category'], $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="sccm-c-provider"><?php esc_html_e( 'Provider', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-provider" class="regular-text" name="cookie[provider]" value="<?php echo esc_attr( $sccm_row['provider'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="sccm-c-purpose"><?php esc_html_e( 'Purpose', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><textarea id="sccm-c-purpose" class="large-text" rows="2" name="cookie[purpose]"><?php echo esc_textarea( $sccm_row['purpose'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="sccm-c-duration"><?php esc_html_e( 'Duration', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-duration" class="regular-text" name="cookie[duration]" value="<?php echo esc_attr( $sccm_row['duration'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1 year, Session', 'smart-cookie-consent-manager' ); ?>"></td></tr>
				<tr><th scope="row"><label for="sccm-c-status"><?php esc_html_e( 'Status', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><select id="sccm-c-status" name="cookie[status]">
						<option value="active" <?php selected( $sccm_row['status'], 'active' ); ?>><?php esc_html_e( 'Active (shown to visitors)', 'smart-cookie-consent-manager' ); ?></option>
						<option value="pending" <?php selected( $sccm_row['status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'smart-cookie-consent-manager' ); ?></option>
						<option value="ignored" <?php selected( $sccm_row['status'], 'ignored' ); ?>><?php esc_html_e( 'Ignored (hidden, not reported again)', 'smart-cookie-consent-manager' ); ?></option>
					</select></td></tr>
			</table>
			<?php submit_button( __( 'Save cookie', 'smart-cookie-consent-manager' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>"><?php esc_html_e( 'Cancel', 'smart-cookie-consent-manager' ); ?></a>
		</form>
	</div>
<?php endif; ?>

<div class="sccm-toolbar">
	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>" class="<?php echo '' === $sccm_status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'smart-cookie-consent-manager' ); ?> <span class="count">(<?php echo (int) array_sum( $sccm_counts ); ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'status' => 'active' ) ) ); ?>" class="<?php echo 'active' === $sccm_status ? 'current' : ''; ?>"><?php esc_html_e( 'Active', 'smart-cookie-consent-manager' ); ?> <span class="count">(<?php echo (int) $sccm_counts['active']; ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'status' => 'pending' ) ) ); ?>" class="<?php echo 'pending' === $sccm_status ? 'current' : ''; ?>"><?php esc_html_e( 'Pending', 'smart-cookie-consent-manager' ); ?> <span class="count">(<?php echo (int) $sccm_counts['pending']; ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'status' => 'ignored' ) ) ); ?>" class="<?php echo 'ignored' === $sccm_status ? 'current' : ''; ?>"><?php esc_html_e( 'Ignored', 'smart-cookie-consent-manager' ); ?> <span class="count">(<?php echo (int) $sccm_counts['ignored']; ?>)</span></a></li>
	</ul>
	<form method="get" class="sccm-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( SCCM_Admin::SLUG ); ?>">
		<input type="hidden" name="tab" value="cookies">
		<input type="search" name="s" value="<?php echo esc_attr( $sccm_search ); ?>" placeholder="<?php esc_attr_e( 'Search cookies', 'smart-cookie-consent-manager' ); ?>">
		<button class="button"><?php esc_html_e( 'Search', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>

<p class="sccm-actions-row">
	<a class="button button-primary" href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'new' => 1 ) ) ); ?>"><?php esc_html_e( 'Add cookie', 'smart-cookie-consent-manager' ); ?></a>
</p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
	<?php SCCM_Admin::action_fields( 'add_service' ); ?>
	<label for="sccm-service"><?php esc_html_e( 'Add known cookies of a service:', 'smart-cookie-consent-manager' ); ?></label>
	<select id="sccm-service" name="service">
		<?php foreach ( SCCM_Services::all() as $sccm_key => $sccm_service ) : ?>
			<?php
			if ( empty( $sccm_service['cookies'] ) ) {
				continue;
			}
			?>
			<option value="<?php echo esc_attr( $sccm_key ); ?>"><?php echo esc_html( $sccm_service['name'] . ' (' . SCCM_Categories::label( $sccm_service['category'] ) . ')' ); ?></option>
		<?php endforeach; ?>
	</select>
	<button class="button"><?php esc_html_e( 'Add', 'smart-cookie-consent-manager' ); ?></button>
</form>

<table class="widefat striped sccm-table-admin">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Name', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Provider', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Purpose', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Duration', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Last seen', 'smart-cookie-consent-manager' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'smart-cookie-consent-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $sccm_rows ) : ?>
		<tr><td colspan="8"><?php esc_html_e( 'No cookies found.', 'smart-cookie-consent-manager' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $sccm_rows as $sccm_row ) : ?>
		<tr class="<?php echo 'pending' === $sccm_row['status'] ? 'sccm-row-pending' : ''; ?>">
			<td><code><?php echo esc_html( $sccm_row['name'] ); ?></code><?php echo 'cookie' !== $sccm_row['type'] ? '<br><small>' . esc_html( $sccm_types[ $sccm_row['type'] ] ?? $sccm_row['type'] ) . '</small>' : ''; ?></td>
			<td><?php echo 'pending' === $sccm_row['status'] ? '&mdash;' : esc_html( SCCM_Categories::label( $sccm_row['category'] ) ); ?></td>
			<td><?php echo esc_html( $sccm_row['provider'] ); ?></td>
			<td><?php echo esc_html( $sccm_row['purpose'] ); ?></td>
			<td><?php echo esc_html( $sccm_row['duration'] ); ?></td>
			<td><span class="sccm-status sccm-status--<?php echo esc_attr( $sccm_row['status'] ); ?>"><?php echo esc_html( ucfirst( $sccm_row['status'] ) ); ?></span><br><small><?php echo esc_html( $sccm_row['source'] ); ?></small></td>
			<td><?php echo $sccm_row['last_seen'] ? esc_html( get_date_from_gmt( $sccm_row['last_seen'], get_option( 'date_format' ) ) ) : '&mdash;'; ?></td>
			<td class="sccm-row-actions">
				<?php if ( 'pending' === $sccm_row['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php SCCM_Admin::action_fields( 'cookie_status' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) $sccm_row['id']; ?>">
						<input type="hidden" name="status" value="active">
						<select name="category" aria-label="<?php esc_attr_e( 'Category', 'smart-cookie-consent-manager' ); ?>">
							<?php foreach ( $sccm_categories as $sccm_value => $sccm_label ) : ?>
								<option value="<?php echo esc_attr( $sccm_value ); ?>"><?php echo esc_html( $sccm_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button class="button button-small button-primary"><?php esc_html_e( 'Approve', 'smart-cookie-consent-manager' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php SCCM_Admin::action_fields( 'cookie_status' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) $sccm_row['id']; ?>">
						<input type="hidden" name="status" value="ignored">
						<button class="button button-small"><?php esc_html_e( 'Ignore', 'smart-cookie-consent-manager' ); ?></button>
					</form>
				<?php endif; ?>
				<a class="button button-small" href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'edit' => (int) $sccm_row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'smart-cookie-consent-manager' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php SCCM_Admin::action_fields( 'cookie_delete' ); ?>
					<input type="hidden" name="id" value="<?php echo (int) $sccm_row['id']; ?>">
					<button class="button button-small button-link-delete" data-sccm-confirm><?php esc_html_e( 'Delete', 'smart-cookie-consent-manager' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
