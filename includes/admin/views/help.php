<?php
/**
 * Help panel: how the plugin works and what every tab, button and option does.
 *
 * Shown above the tabs when the visitor clicks "Help: how it works". The section of the current
 * tab opens by default.
 *
 * @package SmartCookieConsentManager
 * @var string $tab Current tab slug.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sccm_help_section' ) ) {
	/**
	 * Print one help section: a title and a list of "option → what it does".
	 *
	 * @param string $slug    Tab slug (the current tab's section starts open).
	 * @param string $current Current tab.
	 * @param string $title   Section title.
	 * @param array  $items   Option label => explanation.
	 */
	function sccm_help_section( $slug, $current, $title, array $items ) {
		echo '<details class="sccm-help__section"' . ( $slug === $current ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary><dl>';
		foreach ( $items as $label => $text ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl></details>';
	}
}
?>
<div id="sccm-help" class="sccm-help" hidden>
	<div class="sccm-help__head">
		<h2><?php esc_html_e( 'How Cookie Consent works', 'smart-cookie-consent-manager' ); ?></h2>
		<button type="button" class="button" data-sccm-close-help><?php esc_html_e( 'Close help', 'smart-cookie-consent-manager' ); ?></button>
	</div>

	<ol class="sccm-help__flow">
		<li>
			<strong><?php esc_html_e( 'A visitor arrives', 'smart-cookie-consent-manager' ); ?></strong>
			<?php esc_html_e( 'They see your banner. Until they choose, every non-essential script, video, map and font on the page stays switched off. Only the Necessary ones run.', 'smart-cookie-consent-manager' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'They make a choice', 'smart-cookie-consent-manager' ); ?></strong>
			<?php esc_html_e( 'Allow all, Deny, or switch on the categories they want and click Allow selection. The things they allowed switch on immediately, without reloading the page.', 'smart-cookie-consent-manager' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'The choice is remembered', 'smart-cookie-consent-manager' ); ?></strong>
			<?php esc_html_e( 'It is stored in a small cookie called sccm_consent together with a unique consent ID, and saved in your Consent Records as proof. The visitor can see their consent ID and change their choice at any time with the cookie settings button.', 'smart-cookie-consent-manager' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Your cookie list keeps itself current', 'smart-cookie-consent-manager' ); ?></strong>
			<?php esc_html_e( 'A scan looks at your pages. Well-known cookies (Google Analytics, Meta Pixel, YouTube and more) are put in the right category automatically. Anything it does not recognise waits in the Cookies tab for you to approve. Visitors see the list in the Details tab of the banner and on your Cookie Policy page.', 'smart-cookie-consent-manager' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'It works with page caching', 'smart-cookie-consent-manager' ); ?></strong>
			<?php esc_html_e( 'Every visitor gets the same page. The banner and the visitor\'s choice are handled in their browser, so caching plugins and CDNs keep working. When you save settings or the cookie list changes, the plugin clears the page cache of common caching plugins and hosts (WP Engine, NitroPack, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Hummingbird). With any other cache or a CDN, clear it once after changing settings.', 'smart-cookie-consent-manager' ); ?>
		</li>
	</ol>

	<h3><?php esc_html_e( 'Every tab, button and option explained', 'smart-cookie-consent-manager' ); ?></h3>

	<?php
	sccm_help_section(
		'dashboard',
		$tab,
		__( 'Dashboard', 'smart-cookie-consent-manager' ),
		array(
			__( 'Preview banner', 'smart-cookie-consent-manager' )           => __( 'Opens your website with the banner showing, even if you already made a choice yourself.', 'smart-cookie-consent-manager' ),
			__( 'Switch off / Switch on', 'smart-cookie-consent-manager' )   => __( 'Turns the whole system off or on. When off, visitors see no banner and nothing is blocked.', 'smart-cookie-consent-manager' ),
			__( 'Needs your attention', 'smart-cookie-consent-manager' )     => __( 'A short to-do list. Each line says what is missing and has one button to fix it. "Everything looks good" means there is nothing to do.', 'smart-cookie-consent-manager' ),
			__( 'Cookies on your site', 'smart-cookie-consent-manager' )     => __( 'How many cookies are in your list, split by category.', 'smart-cookie-consent-manager' ),
			__( 'Visitor choices', 'smart-cookie-consent-manager' )          => __( 'How many visitors accepted, rejected or chose custom settings in the last 30 days.', 'smart-cookie-consent-manager' ),
			__( 'Last scan / Scan now', 'smart-cookie-consent-manager' )     => __( 'When your website was last checked for cookies, and a button to check it again now.', 'smart-cookie-consent-manager' ),
		)
	);
	sccm_help_section(
		'cookies',
		$tab,
		__( 'Cookies', 'smart-cookie-consent-manager' ),
		array(
			__( 'Needs your review', 'smart-cookie-consent-manager' )        => __( 'Cookies the scan found but could not recognise. Nothing is shown to visitors until you decide. It only appears when something is waiting.', 'smart-cookie-consent-manager' ),
			__( 'Approve', 'smart-cookie-consent-manager' )                  => __( 'Choose a category, then click Approve. The cookie joins the list visitors see. (If "Ask again when the cookie list changes" is on, visitors are asked again.)', 'smart-cookie-consent-manager' ),
			__( 'Ignore / Ignore all', 'smart-cookie-consent-manager' )      => __( 'Hides a cookie that is not part of your website, for example one from a browser extension. It is not reported again.', 'smart-cookie-consent-manager' ),
			__( 'Scan now', 'smart-cookie-consent-manager' )                 => __( 'Two steps. First the server reads your pages. Small websites (up to 40 pages) are scanned completely; bigger ones get more pages the bigger they are (100 pages → 55, 300 → 72, at most 80): every main page first, one item of each content type, then sub pages and sub-sub pages from every section in turn. Then the same pages open in hidden frames in your own browser (three at a time) with everything allowed, so cookies set by scripts (Google Analytics, HubSpot, chat widgets…) and by embedded services (YouTube, Calendly…) are found too. Nothing is stored for visitors. Known cookies are added automatically. Keep the page open until it finishes (one to two minutes for a big website).', 'smart-cookie-consent-manager' ),
			__( 'Your cookie list', 'smart-cookie-consent-manager' )         => __( 'Everything visitors see, grouped by category. "Automatic" means we added it, "Added by you" means you did.', 'smart-cookie-consent-manager' ),
			__( 'Edit / Delete', 'smart-cookie-consent-manager' )            => __( 'Change a cookie\'s category, provider, purpose or duration, or remove it from the list.', 'smart-cookie-consent-manager' ),
			__( 'Add a cookie by hand', 'smart-cookie-consent-manager' )     => __( 'For cookies a scan cannot see. A * in the name matches anything, so _ga_* covers _ga_ABC123.', 'smart-cookie-consent-manager' ),
			__( 'Add all cookies of a known service', 'smart-cookie-consent-manager' ) => __( 'Adds the usual cookies of a service such as Google Analytics in one click.', 'smart-cookie-consent-manager' ),
			__( 'Scan report', 'smart-cookie-consent-manager' )              => __( 'Technical details of the last scan: services found, other third-party files, and pages checked.', 'smart-cookie-consent-manager' ),
		)
	);
	sccm_help_section(
		'banner',
		$tab,
		__( 'Banner', 'smart-cookie-consent-manager' ),
		array(
			__( 'Where should the banner appear?', 'smart-cookie-consent-manager' ) => __( 'A bar at the bottom or top, a box in a corner, or a window in the centre of the page. Click the picture that looks right.', 'smart-cookie-consent-manager' ),
			__( 'Banner style', 'smart-cookie-consent-manager' )             => __( 'Compact (default): your text and three buttons, Allow all, Deny and Customize. Customize opens the detailed window. Detailed: the window with tabs opens straight away.', 'smart-cookie-consent-manager' ),
			__( 'What visitors see', 'smart-cookie-consent-manager' )        => __( 'The detailed window has three tabs. Consent: your text and a switch per category. Details: every category opens to show the providers and each cookie (what it does, how long it lasts, its type). About: what cookies are, and the visitor\'s own choice, date and consent ID.', 'smart-cookie-consent-manager' ),
			__( 'Button order', 'smart-cookie-consent-manager' )             => __( 'Four orders: Allow all · Deny · Allow selection (default), Deny · Allow all · Allow selection, or with "Allow selection" in the middle. In the compact style "Customize" takes the place of "Allow selection". Allow all, Deny and Allow selection always look exactly the same, which is what makes any order fair.', 'smart-cookie-consent-manager' ),
			__( 'Cookie settings button', 'smart-cookie-consent-manager' )   => __( 'A small button visible after the choice, so visitors can change their mind. In a corner it is a round icon. At the bottom centre or on the left or right edge it is a slim tab with the word "Cookies".', 'smart-cookie-consent-manager' ),
			__( 'Colours and rounded corners', 'smart-cookie-consent-manager' ) => __( 'Match the banner to your brand. Accept and Reject always share one style, because valid consent needs both choices to be equally easy.', 'smart-cookie-consent-manager' ),
			__( 'Links in the banner', 'smart-cookie-consent-manager' )      => __( 'The Cookie Policy page and your Privacy Policy page, linked in the banner.', 'smart-cookie-consent-manager' ),
			__( 'Category names and descriptions', 'smart-cookie-consent-manager' ) => __( 'Rename a category, rewrite its description, or hide one your website does not need.', 'smart-cookie-consent-manager' ),
			__( 'Words in the banner, the cookie details and the consent', 'smart-cookie-consent-manager' ) => __( 'Every sentence and button label visitors see. Leave a field empty to keep the default, which is translated automatically.', 'smart-cookie-consent-manager' ),
			__( 'Custom CSS', 'smart-cookie-consent-manager' )               => __( 'For developers: extra styles for the banner. Classes start with sccm-.', 'smart-cookie-consent-manager' ),
		)
	);
	sccm_help_section(
		'settings',
		$tab,
		__( 'Settings', 'smart-cookie-consent-manager' ),
		array(
			__( 'Remember the choice for', 'smart-cookie-consent-manager' )  => __( 'How long a visitor is left alone before the banner shows again. Pick a preset or type your own number of days. The longest possible is 395 days, because browsers do not keep a cookie longer. 0 forgets the choice when the browser closes.', 'smart-cookie-consent-manager' ),
			__( 'Ask again when the cookie list changes', 'smart-cookie-consent-manager' ) => __( 'If you add a new cookie, visitors agreed to an older list, so they are asked again. A scan that finds nothing new never asks again.', 'smart-cookie-consent-manager' ),
			__( 'Do not ask again after a Reject', 'smart-cookie-consent-manager' ) => __( 'A waiting period (in days) after a visitor refuses, so you do not nag them.', 'smart-cookie-consent-manager' ),
			__( 'Reload the page when consent is withdrawn', 'smart-cookie-consent-manager' ) => __( 'A script that already ran cannot be stopped, so the page reloads to stop it.', 'smart-cookie-consent-manager' ),
			__( 'Browser privacy signal', 'smart-cookie-consent-manager' )   => __( 'Some browsers send "do not track me" (Global Privacy Control). We honour it: the chosen categories stay off, no banner is shown, and the choice is recorded.', 'smart-cookie-consent-manager' ),
			__( 'Google Consent Mode', 'smart-cookie-consent-manager' )      => __( 'Tells Google Analytics and Google Ads what the visitor allowed. Strict blocks Google tags until consent. Advanced lets them load without cookies. Leave on Strict unless your marketing team asks.', 'smart-cookie-consent-manager' ),
			__( 'Google Tag Manager / Analytics ID', 'smart-cookie-consent-manager' ) => __( 'Optional. Enter an ID and the plugin loads the tag for you with correct consent handling. Leave empty if your theme or another plugin already adds it.', 'smart-cookie-consent-manager' ),
			__( 'Automatic blocking', 'smart-cookie-consent-manager' )       => __( 'Switches off scripts, videos, maps and fonts of known services until the visitor allows their category. "Known services" lists what is recognised, and "Your own blocking rules" adds more.', 'smart-cookie-consent-manager' ),
			__( 'Scan my website', 'smart-cookie-consent-manager' )          => __( 'How often your website is checked automatically.', 'smart-cookie-consent-manager' ),
			__( 'Also learn from visitors', 'smart-cookie-consent-manager' ) => __( 'Visitors\' browsers can report cookies that only JavaScript sets. Only names are sent, never values, never from logged-in users, and only after several visitors report the same cookie.', 'smart-cookie-consent-manager' ),
			__( 'Email about cookie changes / Daily check at / Send the email to', 'smart-cookie-consent-manager' ) => __( 'Once a day, at the time you choose, the plugin checks whether anything changed in the last 24 hours: cookies added to the banner, cookies waiting for review, new third-party services. Only then it sends one email; no changes, no email. Scheduled scans run one hour before, so their findings are included. The email goes to the site admin (unless switched off) and up to 10 addresses, one per line. It contains no links into WordPress, because some recipients may not have an account. "Send me a sample email" checks delivery; the result of the last email is shown under it.', 'smart-cookie-consent-manager' ),
			__( 'Consent records', 'smart-cookie-consent-manager' )          => __( 'Whether to keep a record of every choice, how the visitor\'s IP address is stored (shortened, as a one-way code, or not at all), and how many months to keep records.', 'smart-cookie-consent-manager' ),
		)
	);
	sccm_help_section(
		'records',
		$tab,
		__( 'Consent Records', 'smart-cookie-consent-manager' ),
		array(
			__( 'The table', 'smart-cookie-consent-manager' )                => __( 'One line per choice: when, the consent ID, what they chose, which categories they allowed, whether a privacy signal was used, which cookie-list version they agreed to, and on which page.', 'smart-cookie-consent-manager' ),
			__( 'Search and filters', 'smart-cookie-consent-manager' )       => __( 'Paste a visitor\'s consent ID to find their record, or filter by choice or date.', 'smart-cookie-consent-manager' ),
			__( 'Export CSV', 'smart-cookie-consent-manager' )               => __( 'Downloads the records (with your current filters) as a spreadsheet file, for audits.', 'smart-cookie-consent-manager' ),
			__( 'Delete old / Delete all', 'smart-cookie-consent-manager' )  => __( 'Removes records older than your retention period, or every record. Deleting all cannot be undone.', 'smart-cookie-consent-manager' ),
		)
	);
	sccm_help_section(
		'tools',
		$tab,
		__( 'Tools', 'smart-cookie-consent-manager' ),
		array(
			__( 'Ask all visitors again', 'smart-cookie-consent-manager' )   => __( 'Shows the banner to everyone again, for example after you change how you use cookies.', 'smart-cookie-consent-manager' ),
			__( 'Export / Import settings', 'smart-cookie-consent-manager' ) => __( 'Copies all settings and your cookie list to a file, so you can use the same setup on another website.', 'smart-cookie-consent-manager' ),
			__( 'Delete all data when the plugin is deleted', 'smart-cookie-consent-manager' ) => __( 'Off by default, so your consent records survive. Turn it on only if you want a clean removal.', 'smart-cookie-consent-manager' ),
			__( 'Reset settings to defaults', 'smart-cookie-consent-manager' ) => __( 'Puts every setting back to how it was at the start. Your cookie list and records are kept.', 'smart-cookie-consent-manager' ),
			__( 'Developer reference', 'smart-cookie-consent-manager' )      => __( 'Shortcodes, links and JavaScript you can use in your theme.', 'smart-cookie-consent-manager' ),
		)
	);
	?>

	<h3><?php esc_html_e( 'Words you may meet', 'smart-cookie-consent-manager' ); ?></h3>
	<dl class="sccm-help__glossary">
		<dt><?php esc_html_e( 'Consent ID', 'smart-cookie-consent-manager' ); ?></dt>
		<dd><?php esc_html_e( 'A random code given to a visitor when they first choose. It identifies their choice, not them: it contains no name or email. They can see it in the About tab of the cookie banner.', 'smart-cookie-consent-manager' ); ?></dd>
		<dt><?php esc_html_e( 'Category', 'smart-cookie-consent-manager' ); ?></dt>
		<dd><?php esc_html_e( 'Necessary (always on), Preferences, Statistics and Marketing. Visitors can refuse every category except Necessary.', 'smart-cookie-consent-manager' ); ?></dd>
		<dt><?php esc_html_e( 'Needs review', 'smart-cookie-consent-manager' ); ?></dt>
		<dd><?php esc_html_e( 'A cookie we found but could not recognise. It is not shown to visitors until you approve it.', 'smart-cookie-consent-manager' ); ?></dd>
		<dt><?php esc_html_e( 'Consent version', 'smart-cookie-consent-manager' ); ?></dt>
		<dd><?php esc_html_e( 'A number that goes up when visitors need to be asked again. A visitor whose choice has an older version sees the banner again.', 'smart-cookie-consent-manager' ); ?></dd>
		<dt><?php esc_html_e( 'Blocking', 'smart-cookie-consent-manager' ); ?></dt>
		<dd><?php esc_html_e( 'Keeping a script, video or font from loading until the visitor allows its category.', 'smart-cookie-consent-manager' ); ?></dd>
	</dl>
</div>
