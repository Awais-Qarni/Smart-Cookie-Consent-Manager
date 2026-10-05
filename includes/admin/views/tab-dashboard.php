<?php
/**
 * Admin tab: Dashboard — what is going on, and what (if anything) needs doing.
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
$sccm_todo = array();
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

$sccm_choice_labels = array(
	'accept_all' => __( 'Allowed all', 'smart-cookie-consent-manager' ),
	'reject_all' => __( 'Denied', 'smart-cookie-consent-manager' ),
	'custom'     => __( 'Allowed a selection', 'smart-cookie-consent-manager' ),
	'gpc'        => __( 'Privacy signal (GPC)', 'smart-cookie-consent-manager' ),
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

<div class="sccm-cards">
	<div class="sccm-card">
		<h3><?php esc_html_e( 'Cookies on your site', 'smart-cookie-consent-manager' ); ?></h3>
		<p class="sccm-big"><?php echo (int) $sccm_counts['active']; ?></p>
		<ul class="sccm-legend">
			<?php foreach ( $sccm_cats as $sccm_key => $sccm_cat ) : ?>
				<li><span class="sccm-dot sccm-dot--<?php echo esc_attr( $sccm_key ); ?>"></span> <?php echo esc_html( $sccm_cat['label'] ); ?> <strong><?php echo (int) count( $sccm_grouped[ $sccm_key ] ); ?></strong></li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo esc_url( SCCM_Admin::url( 'cookies' ) ); ?>"><?php esc_html_e( 'Open the cookie list', 'smart-cookie-consent-manager' ); ?> &rarr;</a></p>
	</div>

	<div class="sccm-card">
		<h3><?php esc_html_e( 'Visitor choices, last 30 days', 'smart-cookie-consent-manager' ); ?></h3>
		<p class="sccm-big"><?php echo (int) $sccm_total; ?></p>
		<?php if ( $sccm_total ) : ?>
			<ul class="sccm-bars">
				<?php foreach ( $sccm_choice_labels as $sccm_key => $sccm_label ) : ?>
					<li>
						<span class="sccm-bars__label"><?php echo esc_html( $sccm_label ); ?></span>
						<span class="sccm-bars__track"><span class="sccm-bars__fill" style="width:<?php echo (int) round( $sccm_stats[ $sccm_key ] / $sccm_total * 100 ); ?>%"></span></span>
						<strong><?php echo (int) $sccm_stats[ $sccm_key ]; ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No choices recorded yet. They appear here as soon as visitors use the banner.', 'smart-cookie-consent-manager' ); ?></p>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( SCCM_Admin::url( 'records' ) ); ?>"><?php esc_html_e( 'See every record', 'smart-cookie-consent-manager' ); ?> &rarr;</a></p>
	</div>

	<div class="sccm-card">
		<h3><?php esc_html_e( 'Last scan', 'smart-cookie-consent-manager' ); ?></h3>
		<?php if ( $sccm_scan ) : ?>
			<p class="sccm-big sccm-big--text"><?php echo esc_html( human_time_diff( (int) $sccm_scan['time'] ) ); ?> <?php esc_html_e( 'ago', 'smart-cookie-consent-manager' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: pages, 2: services */
					esc_html__( '%1$d pages checked, %2$d services found.', 'smart-cookie-consent-manager' ),
					count( (array) $sccm_scan['pages'] ),
					count( (array) $sccm_scan['services'] )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No scan yet.', 'smart-cookie-consent-manager' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sccm-browser-scan>
			<?php SCCM_Admin::action_fields( 'scan_now' ); ?>
			<button class="button"><?php esc_html_e( 'Scan now', 'smart-cookie-consent-manager' ); ?></button>
		</form>
	</div>
</div>

<div class="sccm-panel">
	<h2><?php esc_html_e( 'How it works', 'smart-cookie-consent-manager' ); ?></h2>
	<ol class="sccm-steps">
		<li><strong><?php esc_html_e( 'We find your cookies', 'smart-cookie-consent-manager' ); ?></strong><span><?php esc_html_e( 'A scan checks your pages. Well-known cookies (Google Analytics, Meta Pixel, YouTube…) are sorted into the right category automatically.', 'smart-cookie-consent-manager' ); ?></span></li>
		<li><strong><?php esc_html_e( 'You approve the unknown ones', 'smart-cookie-consent-manager' ); ?></strong><span><?php esc_html_e( 'If a cookie is not recognised, it waits in the Cookies tab. Pick a category and click Approve. That is the only manual step.', 'smart-cookie-consent-manager' ); ?></span></li>
		<li><strong><?php esc_html_e( 'Visitors choose', 'smart-cookie-consent-manager' ); ?></strong><span><?php esc_html_e( 'Until they do, non-essential scripts are blocked. Their choice is remembered and given a consent ID.', 'smart-cookie-consent-manager' ); ?></span></li>
		<li><strong><?php esc_html_e( 'You have the proof', 'smart-cookie-consent-manager' ); ?></strong><span><?php esc_html_e( 'Every choice is saved in Consent Records, and your cookie policy page stays up to date by itself.', 'smart-cookie-consent-manager' ); ?></span></li>
	</ol>
	<p><button type="button" class="button" data-sccm-open-help><?php esc_html_e( 'Open the full guide', 'smart-cookie-consent-manager' ); ?></button></p>
</div>
