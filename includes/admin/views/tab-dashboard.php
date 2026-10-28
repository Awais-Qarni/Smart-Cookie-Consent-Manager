<?php
/**
 * Admin tab: Dashboard — status, what needs doing, and charts of visitor choices and cookies.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

$sccm_counts  = SCCM_Cookies::counts();
$sccm_grouped = SCCM_Cookies::grouped();
$sccm_stats   = SCCM_Consent_Log::stats( 30 );
$sccm_total   = (int) array_sum( $sccm_stats );
$sccm_scan    = get_option( SCCM_Scanner::RESULT_OPTION );
$sccm_on      = ! empty( $settings['enabled'] );
$sccm_policy  = SCCM_Frontend::policy_url();
$sccm_cats    = SCCM_Categories::all();

/*
 * "Needs your attention": every item is one sentence and one button.
 * type: warn (needs action) | info (recommended).
 */
$sccm_todo   = array();
$sccm_others = SCCM_Admin::other_consent_plugins();
if ( $sccm_others && $sccm_on ) {
	$sccm_todo[] = array(
		'type'   => 'warn',
		'text'   => sprintf(
			/* translators: %s: plugin names */
			__( 'Another cookie consent plugin is active: %s. Visitors would see two banners and the two plugins would block each other. Deactivate it once this banner is set up.', 'smart-cookie-consent-manager' ),
			implode( ', ', $sccm_others )
		),
		'label'  => __( 'Open Plugins', 'smart-cookie-consent-manager' ),
		'url'    => admin_url( 'plugins.php?plugin_status=active' ),
		'action' => '',
	);
}
if ( $sccm_counts['pending'] ) {
	$sccm_todo[] = array(
		'type'   => 'warn',
		'text'   => sprintf(
			/* translators: %d: number of cookies */
			_n( '%d cookie was found and needs a category before visitors can see it in the list.', '%d cookies were found and need a category before visitors can see them in the list.', $sccm_counts['pending'], 'smart-cookie-consent-manager' ),
			(int) $sccm_counts['pending']
		),
		'label'  => __( 'Review cookies', 'smart-cookie-consent-manager' ),
		'url'    => SCCM_Admin::url( 'cookies' ),
		'action' => '',
	);
}
if ( ! $sccm_scan ) {
	$sccm_todo[] = array(
		'type'   => 'info',
		'text'   => __( 'Your website has not been scanned yet. A scan finds the cookies and services your pages use and fills the cookie list for you.', 'smart-cookie-consent-manager' ),
		'label'  => __( 'Scan now', 'smart-cookie-consent-manager' ),
		'url'    => '',
		'action' => 'scan_now',
	);
}
if ( ! $sccm_policy ) {
	$sccm_todo[] = array(
		'type'   => 'info',
		'text'   => __( 'You do not have a Cookie Policy page yet. Visitors expect a page that lists your cookies. We can create it for you; it updates itself.', 'smart-cookie-consent-manager' ),
		'label'  => __( 'Create the page', 'smart-cookie-consent-manager' ),
		'url'    => '',
		'action' => 'create_policy_page',
	);
}
if ( 'off' === $settings['consent_mode'] ) {
	$sccm_todo[] = array(
		'type'   => 'info',
		'text'   => __( 'Google Consent Mode is switched off. If you use Google Analytics or Google Ads, turn it on so Google respects your visitors\' choices.', 'smart-cookie-consent-manager' ),
		'label'  => __( 'Open settings', 'smart-cookie-consent-manager' ),
		'url'    => SCCM_Admin::url( 'settings' ),
		'action' => '',
	);
}

/* ---- Numbers for the charts. */
$sccm_days    = SCCM_Consent_Log::daily( 30 );
$sccm_allowed = SCCM_Consent_Log::category_counts( 30 );
$sccm_version = (int) get_option( 'sccm_consent_version', 1 );
$sccm_rate    = $sccm_total ? (int) round( 100 * $sccm_stats['accept_all'] / $sccm_total ) : 0;
$sccm_modes   = array(
	'basic'    => __( 'Strict', 'smart-cookie-consent-manager' ),
	'advanced' => __( 'Advanced', 'smart-cookie-consent-manager' ),
	'off'      => __( 'Off', 'smart-cookie-consent-manager' ),
);
// Choice series: label and colour (bottom of the stacked bars first).
$sccm_series = array(
	'accept_all' => array( __( 'Allowed all', 'smart-cookie-consent-manager' ), '#16a34a' ),
	'custom'     => array( __( 'Allowed a selection', 'smart-cookie-consent-manager' ), '#2563eb' ),
	'reject_all' => array( __( 'Denied', 'smart-cookie-consent-manager' ), '#94a3b8' ),
	'gpc'        => array( __( 'Privacy signal (GPC)', 'smart-cookie-consent-manager' ), '#7c3aed' ),
);
$sccm_cat_colors = array(
	'necessary'  => '#6b7280',
	'functional' => '#2563eb',
	'analytics'  => '#16a34a',
	'marketing'  => '#ea580c',
);
?>
<div class="sccm-hero sccm-hero--<?php echo $sccm_on ? 'ok' : 'off'; ?>">
	<span class="dashicons <?php echo $sccm_on ? 'dashicons-yes-alt' : 'dashicons-warning'; ?> sccm-hero__icon" aria-hidden="true"></span>
	<div class="sccm-hero__text">
		<h2>
			<?php echo $sccm_on ? esc_html__( 'Your cookie banner is live', 'smart-cookie-consent-manager' ) : esc_html__( 'Your cookie banner is switched off', 'smart-cookie-consent-manager' ); ?>
		</h2>
		<p>
			<?php
			echo $sccm_on
				? esc_html__( 'New visitors see the banner. Analytics, marketing and other non-essential scripts stay blocked until they choose.', 'smart-cookie-consent-manager' )
				: esc_html__( 'Visitors see no banner and nothing is blocked. Switch it on to start collecting consent.', 'smart-cookie-consent-manager' );
			?>
		</p>
		<ul class="sccm-status">
			<li>
				<?php esc_html_e( 'Google Consent Mode', 'smart-cookie-consent-manager' ); ?>:
				<strong><?php echo esc_html( $sccm_modes[ $settings['consent_mode'] ] ?? $settings['consent_mode'] ); ?></strong>
			</li>
			<li>
				<?php esc_html_e( 'Privacy signal (GPC)', 'smart-cookie-consent-manager' ); ?>:
				<strong><?php echo ! empty( $settings['gpc_enabled'] ) ? esc_html__( 'honoured', 'smart-cookie-consent-manager' ) : esc_html__( 'off', 'smart-cookie-consent-manager' ); ?></strong>
			</li>
			<li>
				<a href="<?php echo esc_url( SCCM_Admin::url( 'records' ) ); ?>">
					<?php
					/* translators: %d: banner version */
					printf( esc_html__( 'Banner version %d', 'smart-cookie-consent-manager' ), (int) $sccm_version );
					?>
				</a>
				<?php if ( SCCM_Cookies::version_note( $sccm_version ) ) : ?>
					<small>(<?php echo esc_html( SCCM_Cookies::version_note( $sccm_version ) ); ?>)</small>
				<?php endif; ?>
			</li>
		</ul>
	</div>
	<div class="sccm-hero__actions">
		<a class="button button-primary" href="<?php echo esc_url( home_url( '/#sccm-banner' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview banner', 'smart-cookie-consent-manager' ); ?></a>
		<?php SCCM_Admin::form_open( 'dashboard' ); ?>
			<input type="hidden" name="sccm[enabled]" value="<?php echo $sccm_on ? '0' : '1'; ?>">
			<button type="submit" class="button" <?php echo $sccm_on ? 'data-sccm-confirm' : ''; ?>><?php echo $sccm_on ? esc_html__( 'Switch off', 'smart-cookie-consent-manager' ) : esc_html__( 'Switch on', 'smart-cookie-consent-manager' ); ?></button>
		</form>
	</div>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'Needs your attention', 'smart-cookie-consent-manager' ); ?></h2>
	<?php if ( ! $sccm_todo ) : ?>
		<p class="sccm-allgood"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Everything looks good. Nothing to do right now.', 'smart-cookie-consent-manager' ); ?></p>
	<?php else : ?>
		<ul class="sccm-todo">
			<?php foreach ( $sccm_todo as $sccm_item ) : ?>
				<li class="sccm-todo--<?php echo esc_attr( $sccm_item['type'] ); ?>">
					<span class="dashicons <?php echo 'warn' === $sccm_item['type'] ? 'dashicons-flag' : 'dashicons-lightbulb'; ?>" aria-hidden="true"></span>
					<span class="sccm-todo__text"><?php echo esc_html( $sccm_item['text'] ); ?></span>
					<?php if ( $sccm_item['action'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo 'scan_now' === $sccm_item['action'] ? 'data-sccm-browser-scan' : ''; ?>>
							<?php SCCM_Admin::action_fields( $sccm_item['action'] ); ?>
							<button class="button <?php echo 'warn' === $sccm_item['type'] ? 'button-primary' : ''; ?>"><?php echo esc_html( $sccm_item['label'] ); ?></button>
						</form>
					<?php else : ?>
						<a class="button <?php echo 'warn' === $sccm_item['type'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $sccm_item['url'] ); ?>"><?php echo esc_html( $sccm_item['label'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<div class="sccm-panel sccm-dash-choices">
	<div class="sccm-dash-head">
		<h2><?php esc_html_e( 'Visitor choices, last 30 days', 'smart-cookie-consent-manager' ); ?></h2>
		<a href="<?php echo esc_url( SCCM_Admin::url( 'records' ) ); ?>"><?php esc_html_e( 'See every record', 'smart-cookie-consent-manager' ); ?> &rarr;</a>
	</div>
	<?php if ( $sccm_total ) : ?>
		<div class="sccm-kpis">
			<div class="sccm-kpi"><span class="sccm-kpi__value"><?php echo (int) $sccm_total; ?></span><span class="sccm-kpi__label"><?php esc_html_e( 'choices made', 'smart-cookie-consent-manager' ); ?></span></div>
			<div class="sccm-kpi sccm-kpi--good"><span class="sccm-kpi__value"><?php echo (int) $sccm_rate; ?>%</span><span class="sccm-kpi__label"><?php esc_html_e( 'allowed all (acceptance rate)', 'smart-cookie-consent-manager' ); ?></span></div>
			<div class="sccm-kpi"><span class="sccm-kpi__value"><?php echo (int) round( 100 * $sccm_stats['custom'] / $sccm_total ); ?>%</span><span class="sccm-kpi__label"><?php esc_html_e( 'allowed a selection', 'smart-cookie-consent-manager' ); ?></span></div>
			<div class="sccm-kpi"><span class="sccm-kpi__value"><?php echo (int) round( 100 * ( $sccm_stats['reject_all'] + $sccm_stats['gpc'] ) / $sccm_total ); ?>%</span><span class="sccm-kpi__label"><?php esc_html_e( 'denied (incl. privacy signal)', 'smart-cookie-consent-manager' ); ?></span></div>
		</div>
		<div class="sccm-dash-chartrow">
			<div class="sccm-dash-chartrow__main">
				<?php echo SCCM_Admin_Charts::stacked_days( $sccm_days, $sccm_series, __( 'Visitor choices per day, last 30 days', 'smart-cookie-consent-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values. ?>
				<ul class="sccm-legend sccm-legend--row">
					<?php foreach ( $sccm_series as $sccm_key => $sccm_info ) : ?>
						<li><span class="sccm-dot" style="background:<?php echo esc_attr( $sccm_info[1] ); ?>"></span> <?php echo esc_html( $sccm_info[0] ); ?> <strong><?php echo (int) $sccm_stats[ $sccm_key ]; ?></strong></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="sccm-dash-chartrow__side">
				<?php
				$sccm_segments = array();
				foreach ( $sccm_series as $sccm_key => $sccm_info ) {
					$sccm_segments[] = array( $sccm_info[0], $sccm_stats[ $sccm_key ], $sccm_info[1] );
				}
				echo SCCM_Admin_Charts::donut( $sccm_segments, $sccm_rate . '%', __( 'allowed all', 'smart-cookie-consent-manager' ), __( 'Share of each choice, last 30 days', 'smart-cookie-consent-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values.
				?>
			</div>
		</div>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No choices recorded in the last 30 days. They appear here as soon as visitors use the banner. To test, open your website in a private window and click a button.', 'smart-cookie-consent-manager' ); ?></p>
	<?php endif; ?>
</div>

<div class="sccm-cards">
	<div class="sccm-card">
		<h3><?php esc_html_e( 'Allowed per category', 'smart-cookie-consent-manager' ); ?></h3>
		<?php if ( $sccm_allowed['total'] ) : ?>
			<?php
			$sccm_rows = array();
			foreach ( $sccm_cats as $sccm_key => $sccm_cat ) {
				if ( isset( $sccm_allowed[ $sccm_key ] ) ) {
					$sccm_rows[] = array( $sccm_cat['label'], $sccm_allowed[ $sccm_key ], $sccm_allowed['total'], $sccm_cat_colors[ $sccm_key ] );
				}
			}
			echo SCCM_Admin_Charts::percent_bars( $sccm_rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped values.
			?>
			<p class="description"><?php esc_html_e( 'Out of all choices in the last 30 days. Necessary cookies are always allowed.', 'smart-cookie-consent-manager' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No choices yet.', 'smart-cookie-consent-manager' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="sccm-card">
		<h3><?php esc_html_e( 'Cookies on your site', 'smart-cookie-consent-manager' ); ?></h3>
		<div class="sccm-card__chart">
			<?php
			$sccm_segments = array();
			foreach ( $sccm_cats as $sccm_key => $sccm_cat ) {
				$sccm_segments[] = array( $sccm_cat['label'], count( $sccm_grouped[ $sccm_key ] ), $sccm_cat_colors[ $sccm_key ] );
			}
			echo SCCM_Admin_Charts::donut( $sccm_segments, (string) (int) $sccm_counts['active'], _n( 'cookie', 'cookies', (int) $sccm_counts['active'], 'smart-cookie-consent-manager' ), __( 'Cookies in your banner by category', 'smart-cookie-consent-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values.
			?>
			<ul class="sccm-legend">
				<?php foreach ( $sccm_cats as $sccm_key => $sccm_cat ) : ?>
					<li><span class="sccm-dot sccm-dot--<?php echo esc_attr( $sccm_key ); ?>"></span> <?php echo esc_html( $sccm_cat['label'] ); ?> <strong><?php echo (int) count( $sccm_grouped[ $sccm_key ] ); ?></strong></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<p><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>"><?php esc_html_e( 'Open the cookie list', 'smart-cookie-consent-manager' ); ?> &rarr;</a></p>
	</div>

	<div class="sccm-card">
		<h3><?php esc_html_e( 'Cookie scan', 'smart-cookie-consent-manager' ); ?></h3>
		<?php if ( $sccm_scan ) : ?>
			<p class="sccm-big sccm-big--text">
				<?php
				/* translators: %s: time span like "4 hours" */
				printf( esc_html__( '%s ago', 'smart-cookie-consent-manager' ), esc_html( human_time_diff( (int) $sccm_scan['time'] ) ) );
				?>
			</p>
			<ul class="sccm-facts">
				<li><?php esc_html_e( 'Pages checked', 'smart-cookie-consent-manager' ); ?> <strong><?php echo (int) count( (array) $sccm_scan['pages'] ); ?></strong></li>
				<li><?php esc_html_e( 'Services found', 'smart-cookie-consent-manager' ); ?> <strong><?php echo (int) count( (array) $sccm_scan['services'] ); ?></strong></li>
				<li><?php esc_html_e( 'Other third-party files', 'smart-cookie-consent-manager' ); ?> <strong><?php echo (int) count( (array) ( $sccm_scan['unclassified'] ?? array() ) ); ?></strong></li>
				<li><?php esc_html_e( 'Waiting for your review', 'smart-cookie-consent-manager' ); ?> <strong><?php echo (int) $sccm_counts['pending']; ?></strong></li>
				<?php $sccm_next = wp_next_scheduled( 'sccm_scan_event' ); ?>
				<?php if ( $sccm_next ) : ?>
					<li><?php esc_html_e( 'Next automatic scan', 'smart-cookie-consent-manager' ); ?> <strong><?php echo esc_html( wp_date( get_option( 'date_format' ), $sccm_next ) ); ?></strong></li>
				<?php endif; ?>
			</ul>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No scan yet.', 'smart-cookie-consent-manager' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sccm-browser-scan>
			<?php SCCM_Admin::action_fields( 'scan_now' ); ?>
			<button class="button"><?php esc_html_e( 'Scan now', 'smart-cookie-consent-manager' ); ?></button>
		</form>
	</div>
</div>

<p class="description sccm-dash-help">
	<?php
	printf(
		/* translators: %s: link to the Help tab */
		esc_html__( 'New here? The %s page explains how everything works, step by step.', 'smart-cookie-consent-manager' ),
		'<a href="' . esc_url( SCCM_Admin::url( 'help' ) ) . '">' . esc_html__( 'Help', 'smart-cookie-consent-manager' ) . '</a>'
	);
	?>
</p>
