<?php
/**
 * Admin tab: Cookies — approve new cookies, see the list visitors see, scan, add by hand.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$sccm_edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$sccm_is_new  = isset( $_GET['new'] );
// phpcs:enable

$sccm_counts     = SCCM_Cookies::counts();
$sccm_pending    = SCCM_Cookies::query( array( 'status' => 'pending' ) );
$sccm_ignored    = SCCM_Cookies::query( array( 'status' => 'ignored' ) );
$sccm_grouped    = SCCM_Cookies::grouped();
$sccm_categories = SCCM_Admin::category_options();
$sccm_optional   = SCCM_Admin::category_options( true );
$sccm_editing    = $sccm_edit_id ? SCCM_Cookies::get( $sccm_edit_id ) : null;
$sccm_scan       = get_option( SCCM_Scanner::RESULT_OPTION );
$sccm_types      = array(
	'cookie'         => __( 'Cookie', 'smart-cookie-consent-manager' ),
	'localStorage'   => __( 'Local storage', 'smart-cookie-consent-manager' ),
	'sessionStorage' => __( 'Session storage', 'smart-cookie-consent-manager' ),
);
$sccm_sources    = array(
	'scanner' => __( 'Automatic', 'smart-cookie-consent-manager' ),
	'library' => __( 'Automatic', 'smart-cookie-consent-manager' ),
	'default' => __( 'Automatic', 'smart-cookie-consent-manager' ),
	'manual'  => __( 'Added by you', 'smart-cookie-consent-manager' ),
	'import'  => __( 'Imported', 'smart-cookie-consent-manager' ),
);
?>

<?php if ( $sccm_editing || $sccm_is_new ) : ?>
	<?php
	$sccm_row = $sccm_editing ? $sccm_editing : array(
		'name'     => '',
		'type'     => 'cookie',
		'category' => 'analytics',
		'provider' => '',
		'purpose'  => '',
		'duration' => '',
		'status'   => 'active',
	);
	?>
	<div class="sccm-panel">
		<h2><?php echo $sccm_editing ? esc_html__( 'Edit cookie', 'smart-cookie-consent-manager' ) : esc_html__( 'Add a cookie by hand', 'smart-cookie-consent-manager' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php SCCM_Admin::action_fields( 'cookie_save' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $sccm_edit_id; ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="sccm-c-name"><?php esc_html_e( 'Name', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-name" class="regular-text" name="cookie[name]" value="<?php echo esc_attr( $sccm_row['name'] ); ?>" required>
						<p class="description"><?php esc_html_e( 'Use * as a wildcard, for example _ga_* matches _ga_ABC123.', 'smart-cookie-consent-manager' ); ?></p></td></tr>
				<tr><th scope="row"><label for="sccm-c-cat"><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><select id="sccm-c-cat" name="cookie[category]">
						<?php foreach ( $sccm_categories as $sccm_value => $sccm_label ) : ?>
							<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_row['category'], $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="sccm-c-provider"><?php esc_html_e( 'Provider', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-provider" class="regular-text" name="cookie[provider]" value="<?php echo esc_attr( $sccm_row['provider'] ); ?>" placeholder="<?php esc_attr_e( 'Who sets it, e.g. Google LLC', 'smart-cookie-consent-manager' ); ?>"></td></tr>
				<tr><th scope="row"><label for="sccm-c-purpose"><?php esc_html_e( 'What it does', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><textarea id="sccm-c-purpose" class="large-text" rows="2" name="cookie[purpose]"><?php echo esc_textarea( $sccm_row['purpose'] ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="sccm-c-duration"><?php esc_html_e( 'How long it lasts', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><input id="sccm-c-duration" class="regular-text" name="cookie[duration]" value="<?php echo esc_attr( $sccm_row['duration'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1 year, Session', 'smart-cookie-consent-manager' ); ?>"></td></tr>
				<tr><th scope="row"><label for="sccm-c-type"><?php esc_html_e( 'Type', 'smart-cookie-consent-manager' ); ?></label></th>
					<td><select id="sccm-c-type" name="cookie[type]">
						<?php foreach ( $sccm_types as $sccm_value => $sccm_label ) : ?>
							<option value="<?php echo esc_attr( $sccm_value ); ?>" <?php selected( $sccm_row['type'], $sccm_value ); ?>><?php echo esc_html( $sccm_label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<?php if ( $sccm_editing ) : ?>
					<tr><th scope="row"><label for="sccm-c-status"><?php esc_html_e( 'Status', 'smart-cookie-consent-manager' ); ?></label></th>
						<td><select id="sccm-c-status" name="cookie[status]">
							<option value="active" <?php selected( $sccm_row['status'], 'active' ); ?>><?php esc_html_e( 'Shown to visitors', 'smart-cookie-consent-manager' ); ?></option>
							<option value="pending" <?php selected( $sccm_row['status'], 'pending' ); ?>><?php esc_html_e( 'Needs review', 'smart-cookie-consent-manager' ); ?></option>
							<option value="ignored" <?php selected( $sccm_row['status'], 'ignored' ); ?>><?php esc_html_e( 'Ignored (hidden, not reported again)', 'smart-cookie-consent-manager' ); ?></option>
						</select></td></tr>
				<?php else : ?>
					<input type="hidden" name="cookie[status]" value="active">
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Save cookie', 'smart-cookie-consent-manager' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>"><?php esc_html_e( 'Cancel', 'smart-cookie-consent-manager' ); ?></a>
		</form>
	</div>
<?php endif; ?>

<?php if ( $sccm_pending ) : ?>
	<div class="sccm-panel sccm-panel--attention">
		<div class="sccm-panel__head">
			<h2>
				<?php
				/* translators: %d: number of cookies */
				printf( esc_html( _n( '%d cookie needs your review', '%d cookies need your review', count( $sccm_pending ), 'smart-cookie-consent-manager' ) ), (int) count( $sccm_pending ) );
				?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php SCCM_Admin::action_fields( 'ignore_all' ); ?>
				<button class="button button-link" data-sccm-confirm><?php esc_html_e( 'Ignore all', 'smart-cookie-consent-manager' ); ?></button>
			</form>
		</div>
		<p><?php esc_html_e( 'We could not tell what these are for. Choose a category for each one and click Approve. Approved cookies appear in the list your visitors see. Click Ignore for anything that is not a cookie of your website. Known cookies (like Google Analytics) never wait here, they are sorted automatically.', 'smart-cookie-consent-manager' ); ?></p>
		<table class="widefat striped sccm-inbox">
			<tbody>
			<?php foreach ( $sccm_pending as $sccm_row ) : ?>
				<tr>
					<td class="sccm-inbox__name">
						<code><?php echo esc_html( $sccm_row['name'] ); ?></code>
						<small>
							<?php echo esc_html( $sccm_types[ $sccm_row['type'] ] ?? $sccm_row['type'] ); ?>
							<?php if ( $sccm_row['first_seen'] ) : ?>
								&middot;
								<?php
								/* translators: %s: time span like "2 days" */
								printf( esc_html__( 'found %s ago', 'smart-cookie-consent-manager' ), esc_html( human_time_diff( strtotime( $sccm_row['first_seen'] . ' UTC' ) ) ) );
								?>
							<?php endif; ?>
						</small>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-approve">
							<?php SCCM_Admin::action_fields( 'cookie_status' ); ?>
							<input type="hidden" name="id" value="<?php echo (int) $sccm_row['id']; ?>">
							<label class="screen-reader-text" for="sccm-cat-<?php echo (int) $sccm_row['id']; ?>"><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></label>
							<select id="sccm-cat-<?php echo (int) $sccm_row['id']; ?>" name="category" required>
								<option value=""><?php esc_html_e( 'Choose a category…', 'smart-cookie-consent-manager' ); ?></option>
								<?php foreach ( $sccm_categories as $sccm_value => $sccm_label ) : ?>
									<option value="<?php echo esc_attr( $sccm_value ); ?>"><?php echo esc_html( $sccm_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="button button-primary" name="status" value="active"><?php esc_html_e( 'Approve', 'smart-cookie-consent-manager' ); ?></button>
							<button class="button" name="status" value="ignored" formnovalidate><?php esc_html_e( 'Ignore', 'smart-cookie-consent-manager' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<div class="sccm-panel sccm-scanstrip">
	<div>
		<strong><?php esc_html_e( 'Cookie scan', 'smart-cookie-consent-manager' ); ?></strong>
		<span class="description">
			<?php
			if ( $sccm_scan ) {
				/* translators: %s: date and time */
				printf( esc_html__( 'Last scan: %s.', 'smart-cookie-consent-manager' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sccm_scan['time'] ) ) );
			} else {
				esc_html_e( 'Not scanned yet.', 'smart-cookie-consent-manager' );
			}
			echo ' ';
			echo esc_html(
				'off' === $settings['scan_schedule']
					? __( 'Automatic scans are off.', 'smart-cookie-consent-manager' )
					: ( 'daily' === $settings['scan_schedule'] ? __( 'Scans run every day.', 'smart-cookie-consent-manager' ) : __( 'Scans run every week.', 'smart-cookie-consent-manager' ) )
			);
			?>
		</span>
		<?php
		// Did the in-browser part run? Without it, cookies set by scripts cannot be found.
		$sccm_browser = $sccm_scan && ! empty( $sccm_scan['browser'] ) ? $sccm_scan['browser'] : null;
		if ( $sccm_browser && (int) $sccm_browser['time'] >= (int) $sccm_scan['time'] - HOUR_IN_SECONDS ) {
			$sccm_opened = max( 0, (int) $sccm_browser['pages'] - (int) $sccm_browser['blocked'] );
			echo '<p class="sccm-scanstate sccm-scanstate--' . ( $sccm_opened ? 'ok' : 'warn' ) . '">';
			if ( $sccm_opened ) {
				printf(
					/* translators: 1: pages opened, 2: names found */
					esc_html__( 'Browser part: %1$d page(s) opened with everything allowed; %2$d cookie and storage names seen.', 'smart-cookie-consent-manager' ),
					(int) $sccm_opened,
					count( (array) ( $sccm_browser['found'] ?? array() ) )
				);
			} else {
				esc_html_e( 'Browser part: no page could be opened in the hidden frame (the site may forbid being shown in a frame, or a cache served a different page). Only the server part ran, so cookies set by scripts may be missing.', 'smart-cookie-consent-manager' );
			}
			echo '</p>';
		} elseif ( $sccm_scan ) {
			echo '<p class="sccm-scanstate sccm-scanstate--warn">' . esc_html__( 'The last scan ran on the server only (scheduled scan or an old page). Click Scan now to also find cookies that scripts set.', 'smart-cookie-consent-manager' ) . '</p>';
		}
		?>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sccm-browser-scan>
		<?php SCCM_Admin::action_fields( 'scan_now' ); ?>
		<button class="button button-primary"><?php esc_html_e( 'Scan now', 'smart-cookie-consent-manager' ); ?></button>
	</form>
</div>

<div class="sccm-listhead">
	<h2>
		<?php
		/* translators: %d: number of cookies */
		printf( esc_html__( 'Your cookie list (%d)', 'smart-cookie-consent-manager' ), (int) $sccm_counts['active'] );
		?>
	</h2>
	<p class="description"><?php esc_html_e( 'This is what visitors see in the cookie banner (Details tab) and on your Cookie Policy page.', 'smart-cookie-consent-manager' ); ?></p>
</div>

<?php foreach ( SCCM_Categories::all() as $sccm_key => $sccm_cat ) : ?>
	<div class="sccm-catblock">
		<h3><span class="sccm-dot sccm-dot--<?php echo esc_attr( $sccm_key ); ?>"></span> <?php echo esc_html( $sccm_cat['label'] ); ?> <span class="sccm-count"><?php echo (int) count( $sccm_grouped[ $sccm_key ] ); ?></span></h3>
		<?php if ( ! $sccm_grouped[ $sccm_key ] ) : ?>
			<p class="description"><?php esc_html_e( 'No cookies in this category.', 'smart-cookie-consent-manager' ); ?></p>
		<?php else : ?>
			<table class="widefat striped sccm-table-admin">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'smart-cookie-consent-manager' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'smart-cookie-consent-manager' ); ?></th>
						<th><?php esc_html_e( 'What it does', 'smart-cookie-consent-manager' ); ?></th>
						<th><?php esc_html_e( 'Lasts', 'smart-cookie-consent-manager' ); ?></th>
						<th><?php esc_html_e( 'Added', 'smart-cookie-consent-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $sccm_grouped[ $sccm_key ] as $sccm_row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $sccm_row['name'] ); ?></code><?php echo 'cookie' !== $sccm_row['type'] ? '<br><small>' . esc_html( $sccm_types[ $sccm_row['type'] ] ?? $sccm_row['type'] ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $sccm_row['provider'] ); ?></td>
						<td><?php echo esc_html( $sccm_row['purpose'] ); ?></td>
						<td><?php echo esc_html( $sccm_row['duration'] ); ?></td>
						<td><small><?php echo esc_html( $sccm_sources[ $sccm_row['source'] ] ?? $sccm_row['source'] ); ?></small></td>
						<td class="sccm-row-actions">
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
		<?php endif; ?>
	</div>
<?php endforeach; ?>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Add a cookie yourself', 'smart-cookie-consent-manager' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Only needed for cookies the scan cannot see, for example ones set after a login or inside a form.', 'smart-cookie-consent-manager' ); ?></p>
	<p>
		<a class="button" href="<?php echo esc_url( SCCM_Admin::url( 'cookies', array( 'new' => 1 ) ) ); ?>"><?php esc_html_e( 'Add a cookie by hand', 'smart-cookie-consent-manager' ); ?></a>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sccm-inline-form">
		<?php SCCM_Admin::action_fields( 'add_service' ); ?>
		<label for="sccm-service"><?php esc_html_e( 'or add all cookies of a known service:', 'smart-cookie-consent-manager' ); ?></label>
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
</div>

<?php if ( $sccm_ignored ) : ?>
	<details class="sccm-panel sccm-fold">
		<summary>
			<?php
			/* translators: %d: number of cookies */
			printf( esc_html__( 'Ignored cookies (%d)', 'smart-cookie-consent-manager' ), (int) count( $sccm_ignored ) );
			?>
		</summary>
		<p class="description"><?php esc_html_e( 'Hidden from visitors and never reported again. Click Edit to bring one back.', 'smart-cookie-consent-manager' ); ?></p>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $sccm_ignored as $sccm_row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $sccm_row['name'] ); ?></code></td>
					<td class="sccm-row-actions">
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
	</details>
<?php endif; ?>

<?php if ( $sccm_scan ) : ?>
	<details class="sccm-panel sccm-fold">
		<summary><?php esc_html_e( 'Scan report (details)', 'smart-cookie-consent-manager' ); ?></summary>

		<?php foreach ( (array) $sccm_scan['notes'] as $sccm_note ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $sccm_note ); ?></p></div>
		<?php endforeach; ?>

		<h3><?php esc_html_e( 'Services found on your pages', 'smart-cookie-consent-manager' ); ?></h3>
		<?php if ( empty( $sccm_scan['services'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'No known third-party services found.', 'smart-cookie-consent-manager' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Service', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></th><th><?php esc_html_e( 'Found in', 'smart-cookie-consent-manager' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $sccm_scan['services'] as $sccm_service ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $sccm_service['name'] ); ?></strong></td>
						<td><?php echo esc_html( SCCM_Categories::label( $sccm_service['category'] ) ); ?></td>
						<td class="sccm-break"><?php echo esc_html( implode( ', ', $sccm_service['examples'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Other third-party files', 'smart-cookie-consent-manager' ); ?></h3>
		<p class="description"><?php esc_html_e( 'External scripts, frames or stylesheets we do not know yet, so they are not blocked. If one of them tracks visitors, add a blocking rule.', 'smart-cookie-consent-manager' ); ?></p>
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
				printf( esc_html__( 'Cookies the server sent: %s', 'smart-cookie-consent-manager' ), esc_html( implode( ', ', $sccm_scan['cookies'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $sccm_scan['browser']['found'] ) ) : ?>
			<p>
				<?php
				/* translators: %s: cookie and storage names */
				printf( esc_html__( 'Cookies and storage seen in your browser: %s', 'smart-cookie-consent-manager' ), esc_html( implode( ', ', (array) $sccm_scan['browser']['found'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</details>
<?php endif; ?>
