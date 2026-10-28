<?php
/**
 * Admin tab: Help — how the plugin works, what every tab and option does, shortcodes and code
 * for developers, the banner version, and answers to common questions. All help lives here, so
 * the other tabs stay short.
 *
 * @package SmartCookieConsentManager
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sccm_help_list' ) ) {
	/**
	 * Print a list of "label → what it does".
	 *
	 * @param array $items Label => explanation (plain text).
	 */
	function sccm_help_list( array $items ) {
		echo '<dl class="sccm-help__list">';
		foreach ( $items as $label => $text ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl>';
	}
}

if ( ! function_exists( 'sccm_help_code' ) ) {
	/**
	 * Print a table of code examples: code → what it does.
	 *
	 * @param array $rows Code => explanation (both plain text).
	 */
	function sccm_help_code( array $rows ) {
		echo '<table class="widefat striped sccm-help__code"><tbody>';
		foreach ( $rows as $code => $text ) {
			echo '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . esc_html( $text ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

$sccm_sections = array(
	'start'      => __( 'Getting started', 'smart-cookie-consent-manager' ),
	'how'        => __( 'How it works', 'smart-cookie-consent-manager' ),
	'tabs'       => __( 'Every tab explained', 'smart-cookie-consent-manager' ),
	'version'    => __( 'Banner version', 'smart-cookie-consent-manager' ),
	'shortcodes' => __( 'Shortcodes and links', 'smart-cookie-consent-manager' ),
	'developers' => __( 'For developers', 'smart-cookie-consent-manager' ),
	'faq'        => __( 'Questions and problems', 'smart-cookie-consent-manager' ),
	'words'      => __( 'Words you may meet', 'smart-cookie-consent-manager' ),
);
$sccm_version = (int) get_option( 'sccm_consent_version', 1 );
?>
<div class="sccm-help">
	<nav class="sccm-help__toc" aria-label="<?php esc_attr_e( 'Help contents', 'smart-cookie-consent-manager' ); ?>">
		<strong><?php esc_html_e( 'On this page', 'smart-cookie-consent-manager' ); ?></strong>
		<ol>
			<?php foreach ( $sccm_sections as $sccm_id => $sccm_title ) : ?>
				<li><a href="#sccm-help-<?php echo esc_attr( $sccm_id ); ?>"><?php echo esc_html( $sccm_title ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<div class="sccm-help__body">

		<section class="sccm-panel" id="sccm-help-start">
			<h2><?php echo esc_html( $sccm_sections['start'] ); ?></h2>
			<p><?php esc_html_e( 'The banner works as soon as the plugin is active. These steps take about ten minutes and give you a complete setup.', 'smart-cookie-consent-manager' ); ?></p>
			<ol class="sccm-help__steps">
				<li><strong><?php esc_html_e( 'Scan your website.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'Cookies → Scan now. Well-known cookies are sorted into the right category automatically.', 'smart-cookie-consent-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Review what was not recognised.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'Cookies → Needs your review: choose a category and click Approve, or Ignore what is not part of your website.', 'smart-cookie-consent-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Create the Cookie Policy page.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'Dashboard → Needs your attention → Create the page. It lists your cookies and updates itself.', 'smart-cookie-consent-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Make the banner yours.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'Banner: position, style, colours, texts and the link to your Privacy Policy.', 'smart-cookie-consent-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Check Google.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'If you use Google Analytics or Google Ads, keep Google Consent Mode on (Settings → Blocking and Google).', 'smart-cookie-consent-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Test it like a visitor.', 'smart-cookie-consent-manager' ); ?></strong> <?php esc_html_e( 'Open your website in a private window, click a button, then look for the new record in Consent Records.', 'smart-cookie-consent-manager' ); ?></li>
			</ol>
		</section>

		<section class="sccm-panel" id="sccm-help-how">
			<h2><?php echo esc_html( $sccm_sections['how'] ); ?></h2>
			<ol class="sccm-help__flow">
				<li>
					<strong><?php esc_html_e( 'A visitor arrives', 'smart-cookie-consent-manager' ); ?></strong>
					<?php esc_html_e( 'They see your banner. Until they choose, every non-essential script, video, map and font on the page stays switched off. Only the Necessary ones run.', 'smart-cookie-consent-manager' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'They make a choice', 'smart-cookie-consent-manager' ); ?></strong>
					<?php esc_html_e( 'Allow all, Deny, or switch on the categories they want and click Allow selection. What they allowed switches on immediately, without reloading the page. Closing or ignoring the banner never counts as a yes.', 'smart-cookie-consent-manager' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'The choice is remembered and recorded', 'smart-cookie-consent-manager' ); ?></strong>
					<?php esc_html_e( 'It is stored in a small cookie called sccm_consent with a unique consent ID, and saved in Consent Records as proof. The visitor can see their consent ID and banner version, and change their choice at any time with the cookie settings button.', 'smart-cookie-consent-manager' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Your cookie list keeps itself current', 'smart-cookie-consent-manager' ); ?></strong>
					<?php esc_html_e( 'Scans look at your pages; real visitors\' browsers can report cookies too. Well-known cookies are sorted automatically, anything else waits for you. Visitors see the list in the Details tab of the banner and on your Cookie Policy page.', 'smart-cookie-consent-manager' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'It works with page caching', 'smart-cookie-consent-manager' ); ?></strong>
					<?php esc_html_e( 'Every visitor gets the same page; the banner and the visitor\'s choice are handled in their browser. When you save settings or the cookie list changes, the plugin clears the page cache of WP Engine, NitroPack, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze and Hummingbird. With any other cache or a CDN, clear it once after a change.', 'smart-cookie-consent-manager' ); ?>
				</li>
			</ol>
		</section>

		<section class="sccm-panel" id="sccm-help-tabs">
			<h2><?php echo esc_html( $sccm_sections['tabs'] ); ?></h2>

			<details class="sccm-help__section" open>
				<summary><?php esc_html_e( 'Dashboard', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'Needs your attention', 'smart-cookie-consent-manager' )   => __( 'A short to-do list, shown only when something is to do. Each line says what is missing and has one button to fix it.', 'smart-cookie-consent-manager' ),
						__( 'Cookie banner', 'smart-cookie-consent-manager' )          => __( 'Whether the banner is live, Google Consent Mode, the privacy signal and the current banner version. Preview banner opens your website with the banner showing, even if you already chose yourself. Switch off turns the whole system off: no banner, nothing blocked (the Dashboard then shows a Switch on button first).', 'smart-cookie-consent-manager' ),
						__( 'Cookie scan', 'smart-cookie-consent-manager' )            => __( 'When your website was last scanned, what was found, what waits for your review and when the next automatic scan runs.', 'smart-cookie-consent-manager' ),
						__( 'Visitor choices', 'smart-cookie-consent-manager' )        => __( 'How visitors answered in the last 30 days: a chart per day and the share of each answer. "Allowed all" is the acceptance rate.', 'smart-cookie-consent-manager' ),
						__( 'Allowed per category', 'smart-cookie-consent-manager' )   => __( 'Out of all choices, how many allowed Preferences, Statistics and Marketing.', 'smart-cookie-consent-manager' ),
						__( 'Cookies on your site', 'smart-cookie-consent-manager' )   => __( 'How many cookies your banner lists, per category.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>

			<details class="sccm-help__section">
				<summary><?php esc_html_e( 'Cookies', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'Needs your review', 'smart-cookie-consent-manager' )        => __( 'Cookies the scan found but could not recognise. Nothing is shown to visitors until you decide. "Only found in your browser" means none of your pages set it during the scan: it probably comes from your admin tools or a browser extension.', 'smart-cookie-consent-manager' ),
						__( 'Approve', 'smart-cookie-consent-manager' )                  => __( 'Choose a category, then click Approve. The cookie joins the list visitors see. If "Ask again when the cookie list changes" is on, this starts a new banner version.', 'smart-cookie-consent-manager' ),
						__( 'Ignore / Ignore all', 'smart-cookie-consent-manager' )      => __( 'Hides a cookie that is not part of your website. It is not reported again.', 'smart-cookie-consent-manager' ),
						__( 'Scan now', 'smart-cookie-consent-manager' )                 => __( 'Two steps. First the server reads your pages like a first-time visitor (all pages of a small website; 40 to 80 of a big one, main pages first). Then the same pages open in hidden frames in your own browser with everything allowed, so cookies set by scripts and embedded services are found too. Cookies your browser already had count only if a page sets them. It takes one to two minutes; if you leave the page, it continues the next time you open a plugin screen.', 'smart-cookie-consent-manager' ),
						__( 'Your cookie list', 'smart-cookie-consent-manager' )         => __( 'Everything visitors see, grouped by category. "Automatic" means the plugin added it, "Added by you" means you did. Edit changes category, provider, purpose or duration; Delete removes it.', 'smart-cookie-consent-manager' ),
						__( 'Add a cookie by hand', 'smart-cookie-consent-manager' )     => __( 'For cookies a scan cannot see. A * in the name matches anything, so _ga_* covers _ga_ABC123.', 'smart-cookie-consent-manager' ),
						__( 'Add all cookies of a known service', 'smart-cookie-consent-manager' ) => __( 'Adds the usual cookies of a service such as Google Analytics in one click.', 'smart-cookie-consent-manager' ),
						__( 'Scan report (details)', 'smart-cookie-consent-manager' )    => __( 'Services found on your pages, "Other third-party files" (files from other websites the plugin does not know, so they are not blocked) and the pages checked. The daily email points here.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>

			<details class="sccm-help__section">
				<summary><?php esc_html_e( 'Banner', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'Where should the banner appear?', 'smart-cookie-consent-manager' ) => __( 'A bar at the bottom or top, a box in a corner, or a window in the centre of the page.', 'smart-cookie-consent-manager' ),
						__( 'Banner style', 'smart-cookie-consent-manager' )             => __( 'Compact (default): your text and Allow all, Deny and Customize. Detailed: the window with tabs opens straight away.', 'smart-cookie-consent-manager' ),
						__( 'What visitors see', 'smart-cookie-consent-manager' )        => __( 'The detailed window has three tabs. Consent: your text and a switch per category. Details: every category with its providers and cookies. About: what cookies are, and the visitor\'s own choice, date, consent ID and banner version.', 'smart-cookie-consent-manager' ),
						__( 'Button order', 'smart-cookie-consent-manager' )             => __( 'Four orders to choose from. Allow all, Deny and Allow selection always look exactly the same, which is what makes any order fair.', 'smart-cookie-consent-manager' ),
						__( 'Cookie settings button', 'smart-cookie-consent-manager' )   => __( 'A small button visible after the choice, so visitors can change their mind: a round icon in a corner, or a slim "Cookies" tab at the bottom or on an edge.', 'smart-cookie-consent-manager' ),
						__( 'Colours and rounded corners', 'smart-cookie-consent-manager' ) => __( 'Match the banner to your brand. Accept and Reject always share one style.', 'smart-cookie-consent-manager' ),
						__( 'Category names and descriptions', 'smart-cookie-consent-manager' ) => __( 'Rename a category, rewrite its description, or hide one your website does not need.', 'smart-cookie-consent-manager' ),
						__( 'Words visitors see', 'smart-cookie-consent-manager' )       => __( 'Every sentence and button label. Leave a field empty to keep the default, which is translated automatically.', 'smart-cookie-consent-manager' ),
						__( 'Custom CSS', 'smart-cookie-consent-manager' )               => __( 'Extra styles for the banner. Classes start with sccm-.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>

			<details class="sccm-help__section">
				<summary><?php esc_html_e( 'Settings', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'Remember the choice for', 'smart-cookie-consent-manager' )  => __( 'How long a visitor is left alone before the banner shows again (at most 395 days, browsers do not keep a cookie longer; 0 forgets the choice when the browser closes).', 'smart-cookie-consent-manager' ),
						__( 'Ask again when the cookie list changes', 'smart-cookie-consent-manager' ) => __( 'A new cookie in the list, or a cookie moved to another category, starts a new banner version, so visitors who agreed to the older list are asked again. A scan that finds nothing new never asks again.', 'smart-cookie-consent-manager' ),
						__( 'Do not ask again after a Reject', 'smart-cookie-consent-manager' ) => __( 'A waiting period (in days) after a visitor refuses, so you do not nag them.', 'smart-cookie-consent-manager' ),
						__( 'Reload the page when consent is withdrawn', 'smart-cookie-consent-manager' ) => __( 'A script that already ran cannot be stopped, so the page reloads to stop it.', 'smart-cookie-consent-manager' ),
						__( 'Browser privacy signal (GPC)', 'smart-cookie-consent-manager' ) => __( 'Some browsers send "do not sell or share my data". It is honoured: the chosen categories stay off, no banner is shown, and the choice is recorded.', 'smart-cookie-consent-manager' ),
						__( 'Google Consent Mode', 'smart-cookie-consent-manager' )      => __( 'Tells Google Analytics and Google Ads what the visitor allowed. Strict blocks Google tags until consent; Advanced lets them load without cookies. Leave it on Strict unless your marketing team asks.', 'smart-cookie-consent-manager' ),
						__( 'Google Tag Manager / Analytics ID', 'smart-cookie-consent-manager' ) => __( 'Optional. Enter an ID and the plugin loads the tag with correct consent handling. Leave it empty if your theme or another plugin already adds it.', 'smart-cookie-consent-manager' ),
						__( 'Automatic blocking and your own rules', 'smart-cookie-consent-manager' ) => __( 'Switches off scripts, videos, maps and fonts of known services until the visitor allows their category. Add an address under "Your own blocking rules" to block something the plugin does not know.', 'smart-cookie-consent-manager' ),
						__( 'Scan my website / Learn from visitors', 'smart-cookie-consent-manager' ) => __( 'How often your website is scanned, and whether visitors\' browsers may report cookies (names only, never values, never from logged-in users, only after enough different visitors report the same cookie: default 2 within 14 days).', 'smart-cookie-consent-manager' ),
						__( 'Daily email', 'smart-cookie-consent-manager' )              => __( 'Once a day, at the time you choose, one email when something changed in the last 24 hours: cookies added, cookies waiting for review, or new third-party files. No changes, no email.', 'smart-cookie-consent-manager' ),
						__( 'Consent records', 'smart-cookie-consent-manager' )          => __( 'Whether to keep a record of every choice, how the visitor\'s IP address is stored (shortened with ***, a one-way code, in full, or not at all), and how many months records are kept.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>

			<details class="sccm-help__section">
				<summary><?php esc_html_e( 'Consent Records', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'The table', 'smart-cookie-consent-manager' )                => __( 'One line per choice: when (UTC), the consent ID, the choice, the categories allowed, whether a privacy signal was used, the banner version agreed to, the page and the IP address in the form you chose.', 'smart-cookie-consent-manager' ),
						__( 'Search and filters', 'smart-cookie-consent-manager' )       => __( 'Paste a consent ID or an IP address, or filter by choice, banner version or date.', 'smart-cookie-consent-manager' ),
						__( 'Banner versions', 'smart-cookie-consent-manager' )          => __( 'The box at the top lists every banner version with records: when it started, why, and how many records it has.', 'smart-cookie-consent-manager' ),
						__( 'Export CSV', 'smart-cookie-consent-manager' )               => __( 'Downloads the records with your current filters as a spreadsheet file, for audits.', 'smart-cookie-consent-manager' ),
						__( 'Test record saving', 'smart-cookie-consent-manager' )       => __( 'Checks that your server accepts records from visitors (a firewall can block them).', 'smart-cookie-consent-manager' ),
						__( 'Delete old / Delete all', 'smart-cookie-consent-manager' )  => __( 'Removes records older than your retention period, or every record. Deleting all cannot be undone.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>

			<details class="sccm-help__section">
				<summary><?php esc_html_e( 'Tools', 'smart-cookie-consent-manager' ); ?></summary>
				<?php
				sccm_help_list(
					array(
						__( 'Ask all visitors again', 'smart-cookie-consent-manager' )   => __( 'Starts a new banner version: everyone sees the banner again, for example after you change how you use cookies.', 'smart-cookie-consent-manager' ),
						__( 'Export / Import settings', 'smart-cookie-consent-manager' ) => __( 'Copies all settings and your cookie list to a file, so you can use the same setup on another website.', 'smart-cookie-consent-manager' ),
						__( 'Delete all data when the plugin is deleted', 'smart-cookie-consent-manager' ) => __( 'Off by default, so your consent records survive. Turn it on only for a clean removal.', 'smart-cookie-consent-manager' ),
						__( 'Reset settings to defaults', 'smart-cookie-consent-manager' ) => __( 'Puts every setting back to how it was at the start. Your cookie list and records are kept.', 'smart-cookie-consent-manager' ),
					)
				);
				?>
			</details>
		</section>

		<section class="sccm-panel" id="sccm-help-version">
			<h2><?php echo esc_html( $sccm_sections['version'] ); ?></h2>
			<p>
				<?php
				/* translators: %d: current banner version */
				printf( esc_html__( 'Your current banner version is %d.', 'smart-cookie-consent-manager' ), (int) $sccm_version );
				?>
				<?php esc_html_e( 'The banner version is a number that goes up by one every time visitors must be asked again: when a new cookie joins the list or a cookie changes category (with "Ask again when the cookie list changes" on), or when you click "Ask all visitors again" in Tools. A visitor whose choice has an older version sees the banner again on their next visit.', 'smart-cookie-consent-manager' ); ?>
			</p>
			<p><?php esc_html_e( 'To match a visitor\'s choice to the cookie list they agreed to:', 'smart-cookie-consent-manager' ); ?></p>
			<ol class="sccm-help__steps">
				<li><?php esc_html_e( 'The visitor opens the cookie settings → About tab: it shows their consent ID and banner version.', 'smart-cookie-consent-manager' ); ?></li>
				<li><?php esc_html_e( 'Paste the consent ID in Consent Records: the record shows the same banner version.', 'smart-cookie-consent-manager' ); ?></li>
				<li><?php esc_html_e( 'The banner version box at the top of Consent Records says when that version started and why. Records marked "older" were given before the latest change.', 'smart-cookie-consent-manager' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'The CSV export has the same number in its banner_version column. Versions from before plugin version 1.3.0 have no start date.', 'smart-cookie-consent-manager' ); ?></p>
		</section>

		<section class="sccm-panel" id="sccm-help-shortcodes">
			<h2><?php echo esc_html( $sccm_sections['shortcodes'] ); ?></h2>
			<p><?php esc_html_e( 'Use these in pages, posts, widgets or menus. No coding needed.', 'smart-cookie-consent-manager' ); ?></p>
			<?php
			sccm_help_code(
				array(
					'[sccm_cookie_policy]'                               => __( 'The cookie list, grouped by category, as on the Cookie Policy page. It updates itself.', 'smart-cookie-consent-manager' ),
					'[sccm_cookie_policy show_empty="yes"]'              => __( 'Also shows categories that have no cookies yet.', 'smart-cookie-consent-manager' ),
					'[sccm_cookie_settings]'                             => __( 'A link that opens the cookie settings window.', 'smart-cookie-consent-manager' ),
					'[sccm_cookie_settings text="Manage cookies" style="button"]' => __( 'The same as a button, with your own text (style="link" or style="button").', 'smart-cookie-consent-manager' ),
					'#sccm-preferences'                                  => __( 'As the address of any link, for example a menu item (Appearance → Menus → Custom link): opens the cookie settings window.', 'smart-cookie-consent-manager' ),
				)
			);
			?>
		</section>

		<section class="sccm-panel" id="sccm-help-developers">
			<h2><?php echo esc_html( $sccm_sections['developers'] ); ?></h2>
			<h3><?php esc_html_e( 'Run your own code only after consent', 'smart-cookie-consent-manager' ); ?></h3>
			<?php
			sccm_help_code(
				array(
					'<script type="text/plain" data-sccm-category="analytics">…</script>' => __( 'Runs only after Statistics consent. Categories: functional (Preferences), analytics (Statistics), marketing (Marketing).', 'smart-cookie-consent-manager' ),
					'<script type="text/plain" data-sccm-category="marketing" data-sccm-src="https://…"></script>' => __( 'An external script, loaded after Marketing consent.', 'smart-cookie-consent-manager' ),
					'<iframe data-sccm-category="marketing" data-sccm-src="https://…"></iframe>' => __( 'A frame (video, map…) loaded after consent; a placeholder with a "load" button is shown before.', 'smart-cookie-consent-manager' ),
					'data-sccm-skip'                                                       => __( 'On any tag: the automatic blocking leaves it alone.', 'smart-cookie-consent-manager' ),
				)
			);
			?>
			<h3><?php esc_html_e( 'JavaScript', 'smart-cookie-consent-manager' ); ?></h3>
			<?php
			sccm_help_code(
				array(
					"window.SCCM.hasConsent('analytics')"            => __( 'True if the visitor allowed this category, false if not.', 'smart-cookie-consent-manager' ),
					'window.SCCM.getConsent()'                       => __( 'The visitor\'s choice: consent ID (id), banner version (v), time (t), categories (c), method (m); null before a choice.', 'smart-cookie-consent-manager' ),
					'window.SCCM.openPreferences()'                  => __( 'Opens the cookie settings window (optional tab: \'details\' or \'about\').', 'smart-cookie-consent-manager' ),
					"document.addEventListener('sccm:consent', …)"   => __( 'Runs the moment a visitor makes or changes their choice, e.g. to start a tool without reloading the page.', 'smart-cookie-consent-manager' ),
					'dataLayer event sccm_consent_update'            => __( 'For Google Tag Manager triggers, with sccm_categories, sccm_method and sccm_consent_id.', 'smart-cookie-consent-manager' ),
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'PHP filters and actions (for example sccm_services to add your own service, sccm_client_ip behind a proxy, sccm_cache_purged to clear a CDN) are described in docs/HOOKS.md in the plugin\'s repository.', 'smart-cookie-consent-manager' ); ?></p>
		</section>

		<section class="sccm-panel" id="sccm-help-faq">
			<h2><?php echo esc_html( $sccm_sections['faq'] ); ?></h2>
			<?php
			sccm_help_list(
				array(
					__( 'I do not see the banner on my website.', 'smart-cookie-consent-manager' ) => __( 'You probably made a choice already: use Preview banner on the Dashboard, or open the website in a private window. Check that the banner is switched on. After an update or a settings change, clear your page cache and CDN once.', 'smart-cookie-consent-manager' ),
					__( 'Consent Records stay empty.', 'smart-cookie-consent-manager' ) => __( 'Records must be switched on (Settings → Consent records). Click "Test record saving" in Consent Records: it shows whether a firewall or security plugin blocks the records. Old cached pages still carry the old script, so clear the page cache.', 'smart-cookie-consent-manager' ),
					__( '"The scan could not finish" with a reason.', 'smart-cookie-consent-manager' ) => __( '"Your login or this page has expired": reload the page. "A security plugin, firewall or the host blocked the request": allow admin-ajax.php for logged-in administrators. "The server had an error or ran out of time": try again; if it repeats, check the PHP error log. Short hiccups are retried automatically.', 'smart-cookie-consent-manager' ),
					__( 'A cookie under review says "Only found in your browser".', 'smart-cookie-consent-manager' ) => __( 'None of your pages set it during the scan, so it most likely comes from an admin tool (for example a cache plugin\'s dashboard) or a browser extension. Ignore it if your visitors do not get it. With "Learn from visitors" on, such cookies are not listed at all unless real visitors report them.', 'smart-cookie-consent-manager' ),
					__( 'The daily email reports a "new third-party file".', 'smart-cookie-consent-manager' ) => __( 'A script, frame or stylesheet from another website that the plugin does not know. Look at its full address in Cookies → Scan report (details) → Other third-party files. If it tracks visitors, add it under Settings → Your own blocking rules; otherwise there is nothing to do.', 'smart-cookie-consent-manager' ),
					__( 'A video or map shows a placeholder instead of the content.', 'smart-cookie-consent-manager' ) => __( 'That is intended: YouTube, Google Maps and similar services set cookies, so they wait until the visitor allows their category (Google Maps and YouTube: Marketing). The placeholder has a button to load the content once.', 'smart-cookie-consent-manager' ),
					__( 'How do I test the privacy signal (GPC)?', 'smart-cookie-consent-manager' ) => __( 'Firefox: Settings → Privacy → "Tell websites not to sell or share my data"; Brave sends it by default. Open your website: no banner, the chosen categories stay off, and Consent Records shows a GPC record.', 'smart-cookie-consent-manager' ),
					__( 'The Dashboard warns about another consent plugin.', 'smart-cookie-consent-manager' ) => __( 'Two banners would block each other. Deactivate the other plugin once this banner is set up.', 'smart-cookie-consent-manager' ),
					__( 'Does the plugin make my website compliant?', 'smart-cookie-consent-manager' ) => __( 'It gives you the tools: consent before optional cookies, fair choices, proof of consent and a cookie list. Your setup and your privacy policy also matter, so let a privacy professional check them.', 'smart-cookie-consent-manager' ),
				)
			);
			?>
		</section>

		<section class="sccm-panel" id="sccm-help-words">
			<h2><?php echo esc_html( $sccm_sections['words'] ); ?></h2>
			<?php
			sccm_help_list(
				array(
					__( 'Consent ID', 'smart-cookie-consent-manager' )      => __( 'A random code given to a visitor when they first choose. It identifies their choice, not them: it contains no name or email.', 'smart-cookie-consent-manager' ),
					__( 'Banner version', 'smart-cookie-consent-manager' )  => __( 'The version of your cookie banner and cookie list a visitor agreed to (see above).', 'smart-cookie-consent-manager' ),
					__( 'Category', 'smart-cookie-consent-manager' )        => __( 'Necessary (always on), Preferences, Statistics and Marketing. Visitors can refuse every category except Necessary.', 'smart-cookie-consent-manager' ),
					__( 'Needs review', 'smart-cookie-consent-manager' )    => __( 'A cookie the plugin found but could not recognise. Visitors do not see it until you approve it.', 'smart-cookie-consent-manager' ),
					__( 'Blocking', 'smart-cookie-consent-manager' )        => __( 'Keeping a script, video or font from loading until the visitor allows its category.', 'smart-cookie-consent-manager' ),
					__( 'Google Consent Mode', 'smart-cookie-consent-manager' ) => __( 'Google\'s way for a website to tell its tags what the visitor allowed. Required for Google Ads in the EEA and the UK.', 'smart-cookie-consent-manager' ),
					__( 'GPC', 'smart-cookie-consent-manager' )             => __( 'Global Privacy Control: a browser setting that asks websites not to sell or share data. California law requires honouring it.', 'smart-cookie-consent-manager' ),
					__( 'Third-party file', 'smart-cookie-consent-manager' ) => __( 'A script, frame or stylesheet your pages load from another website.', 'smart-cookie-consent-manager' ),
				)
			);
			?>
		</section>
	</div>
</div>
