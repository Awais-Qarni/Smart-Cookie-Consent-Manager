<?php
/**
 * Admin screens: menu, tabs, form handlers.
 *
 * Every action goes through admin-post.php with a nonce and a manage_options check.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class SCCM_Admin {

	const SLUG = 'sccm';
	const CAP  = 'manage_options';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . SCCM_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		$actions = array( 'save', 'cookie_save', 'cookie_delete', 'cookie_status', 'ignore_all', 'add_service', 'scan_now', 'send_digest', 'send_test_email', 'export_log', 'purge_log', 'delete_log', 'export_settings', 'import_settings', 'bump_version', 'create_policy_page', 'reset_settings', 'test_record' );
		foreach ( $actions as $action ) {
			add_action( 'admin_post_sccm_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_sccm_browser_scan_start', array( __CLASS__, 'ajax_scan_start' ) );
		add_action( 'wp_ajax_sccm_browser_scan_report', array( __CLASS__, 'ajax_scan_report' ) );
		add_action( 'wp_ajax_sccm_browser_scan_server', array( __CLASS__, 'ajax_scan_server' ) );
		add_action( 'wp_ajax_sccm_browser_scan_resume', array( __CLASS__, 'ajax_scan_resume' ) );
	}

	/**
	 * Tabs: slug => label. Six tabs, in the order a site owner needs them.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'dashboard' => __( 'Dashboard', 'smart-cookie-consent-manager' ),
			'cookies'   => __( 'Cookies', 'smart-cookie-consent-manager' ),
			'banner'    => __( 'Banner', 'smart-cookie-consent-manager' ),
			'settings'  => __( 'Settings', 'smart-cookie-consent-manager' ),
			'records'   => __( 'Consent Records', 'smart-cookie-consent-manager' ),
			'tools'     => __( 'Tools', 'smart-cookie-consent-manager' ),
		);
	}

	/**
	 * Tab names of version 0.1.0, so old links and bookmarks keep working.
	 *
	 * @return array old slug => new slug.
	 */
	private static function legacy_tabs() {
		return array(
			'general'    => 'dashboard',
			'appearance' => 'banner',
			'texts'      => 'banner',
			'categories' => 'banner',
			'blocking'   => 'settings',
			'scanner'    => 'cookies',
			'log'        => 'records',
		);
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$pending = SCCM_Cookies::pending_count();
		$badge   = $pending ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>' : '';
		add_menu_page(
			__( 'Cookie Consent', 'smart-cookie-consent-manager' ),
			__( 'Cookie Consent', 'smart-cookie-consent-manager' ) . $badge,
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'page' ),
			'dashicons-shield',
			81
		);
	}

	/**
	 * Admin assets (only on our page).
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		list( $css_url, $css_ver ) = SCCM_Plugin::asset( 'assets/css/sccm-admin.css' );
		list( $js_url, $js_ver )   = SCCM_Plugin::asset( 'assets/js/sccm-admin.js' );
		wp_enqueue_style( 'sccm-admin', $css_url, array(), $css_ver );
		wp_enqueue_script( 'sccm-admin', $js_url, array( 'jquery', 'wp-color-picker' ), $js_ver, true );
		wp_localize_script(
			'sccm-admin',
			'SCCM_ADMIN',
			array(
				'confirm'    => __( 'Are you sure?', 'smart-cookie-consent-manager' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'scanNonce'  => wp_create_nonce( 'sccm_browser_scan' ),
				'scanServer' => __( 'Step 1 of 2: checking your pages on the server…', 'smart-cookie-consent-manager' ),
				/* translators: 1: pages checked so far, 2: pages planned */
				'scanServerProgress' => __( 'Step 1 of 2: checking your pages on the server (%1$d of %2$d)…', 'smart-cookie-consent-manager' ),
				/* translators: 1: page number, 2: number of pages */
				'scanPage'   => __( 'Step 2 of 2: opening page %1$d of %2$d in your browser to see the cookies its scripts set…', 'smart-cookie-consent-manager' ),
				'scanSaving' => __( 'Saving what was found…', 'smart-cookie-consent-manager' ),
				/* translators: %s: error message */
				'scanFailed' => __( 'The scan could not finish: %s', 'smart-cookie-consent-manager' ),
				'scanResume' => (bool) SCCM_Scanner::browser_state(),
				'scanResuming' => __( 'Cookie scan: continuing where it stopped…', 'smart-cookie-consent-manager' ),
				'scanBusy'   => __( 'A cookie scan is running in another browser tab.', 'smart-cookie-consent-manager' ),
				'scanDone'   => __( 'Cookie scan finished. The results are in the Cookies tab.', 'smart-cookie-consent-manager' ),
				'cookiesUrl' => self::url( 'cookies' ),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'smart-cookie-consent-manager' ) . '</a>' );
		return $links;
	}

	/**
	 * Notices: result messages, and a reminder when cookies wait for review.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::SLUG !== $screen->id ) {
			return;
		}
		$notice = get_transient( 'sccm_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && '' !== $notice['msg'] ) {
			delete_transient( 'sccm_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		// phpcs:enable
		// The Dashboard and the Cookies tab already show what is waiting; elsewhere, remind.
		$pending = SCCM_Cookies::pending_count();
		if ( $pending && ! in_array( $tab, array( 'dashboard', 'cookies', '' ), true ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %d: number of cookies */
				esc_html( _n( '%d cookie is waiting for your approval.', '%d cookies are waiting for your approval.', $pending, 'smart-cookie-consent-manager' ) ),
				(int) $pending
			);
			echo ' <a href="' . esc_url( self::url( 'cookies' ) ) . '">' . esc_html__( 'Review now', 'smart-cookie-consent-manager' ) . '</a></p></div>';
		}
	}

	/**
	 * Render the settings page: header, help panel, tabs, then the tab's view.
	 */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs   = self::tabs();
		$legacy = self::legacy_tabs();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $legacy[ $tab ] ) ? $legacy[ $tab ] : $tab;
		$tab    = isset( $tabs[ $tab ] ) ? $tab : 'dashboard';
		$settings = SCCM_Settings::get();
		$pending  = SCCM_Cookies::pending_count();

		echo '<div class="wrap sccm-admin">';
		echo '<div class="sccm-header">';
		echo '<h1>' . esc_html__( 'Cookie Consent', 'smart-cookie-consent-manager' ) . '</h1>';
		echo '<span class="sccm-pill sccm-pill--' . ( $settings['enabled'] ? 'on' : 'off' ) . '">' . ( $settings['enabled'] ? esc_html__( 'Banner on', 'smart-cookie-consent-manager' ) : esc_html__( 'Banner off', 'smart-cookie-consent-manager' ) ) . '</span>';
		echo '<button type="button" class="button sccm-help-toggle sccm-header__help" id="sccm-help-toggle" aria-expanded="false" aria-controls="sccm-help"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> ' . esc_html__( 'Help: how it works', 'smart-cookie-consent-manager' ) . '</button>';
		echo '</div><hr class="wp-header-end">';

		include SCCM_PATH . 'includes/admin/views/help.php';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = 'nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' );
			$badge = ( 'cookies' === $slug && $pending ) ? ' <span class="sccm-badge-count">' . (int) $pending . '</span>' : '';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( $label ) . $badge . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is a number.
		}
		echo '</nav><div class="sccm-tab">';
		include SCCM_PATH . 'includes/admin/views/tab-' . $tab . '.php';
		echo '</div></div>';
	}

	/* ------------------------------------------------------------------ Form helpers (used by views) */

	/**
	 * Other cookie consent plugins that are active (two banners would conflict).
	 *
	 * @return string[] Plugin names.
	 */
	public static function other_consent_plugins() {
		$known = array(
			'cookiebot'                    => 'Cookiebot',
			'complianz-gdpr'               => 'Complianz',
			'complianz-gdpr-premium'       => 'Complianz',
			'cookie-law-info'              => 'CookieYes',
			'gdpr-cookie-compliance'       => 'GDPR Cookie Compliance',
			'real-cookie-banner'           => 'Real Cookie Banner',
			'real-cookie-banner-pro'       => 'Real Cookie Banner',
			'borlabs-cookie'               => 'Borlabs Cookie',
			'iubenda-cookie-law-solution'  => 'iubenda',
			'cookie-notice'                => 'Cookie Notice & Compliance',
			'uk-cookie-consent'            => 'Termly',
			'gdpr-cookie-consent'          => 'WP Cookie Consent',
			'cookie-consent-box'           => 'Cookie Consent Box',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( $active as $basename ) {
			$dir = strtok( (string) $basename, '/' );
			if ( isset( $known[ $dir ] ) ) {
				$found[ $known[ $dir ] ] = true;
			}
		}
		/**
		 * Other consent plugins detected (names). Return an empty array to hide the warning.
		 *
		 * @param string[] $names Plugin names.
		 */
		return (array) apply_filters( 'sccm_other_consent_plugins', array_keys( $found ) );
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $tab  Tab slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'general', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Open a settings form for a tab.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function form_open( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sccm_save">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'sccm_save' );
	}

	/**
	 * Hidden fields + nonce for a custom admin-post action.
	 *
	 * @param string $action Action (without sccm_ prefix).
	 */
	public static function action_fields( $action ) {
		echo '<input type="hidden" name="action" value="sccm_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'sccm_' . $action );
	}

	/**
	 * Close a settings form with a save button.
	 */
	public static function form_close() {
		submit_button( __( 'Save changes', 'smart-cookie-consent-manager' ) );
		echo '</form>';
	}

	/**
	 * Field name for a setting path, e.g. array( 'texts', 'btn_accept' ) → sccm[texts][btn_accept].
	 *
	 * @param string|array $path Key or path.
	 * @return string
	 */
	public static function name( $path ) {
		$path = (array) $path;
		return 'sccm[' . implode( '][', $path ) . ']';
	}

	/**
	 * Table row with a checkbox.
	 *
	 * @param string|array $path  Setting path.
	 * @param string       $label Label.
	 * @param mixed        $value Current value.
	 * @param string       $desc  Description.
	 */
	public static function checkbox( $path, $label, $value, $desc = '' ) {
		$name = self::name( $path );
		$id   = 'sccm-' . sanitize_html_class( implode( '-', (array) $path ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . '> ' . esc_html__( 'Enabled', 'smart-cookie-consent-manager' ) . '</label>';
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Table row with a select.
	 *
	 * @param string|array $path    Setting path.
	 * @param string       $label   Label.
	 * @param mixed        $value   Current value.
	 * @param array        $options value => label.
	 * @param string       $desc    Description.
	 */
	public static function select( $path, $label, $value, array $options, $desc = '' ) {
		$id = 'sccm-' . sanitize_html_class( implode( '-', (array) $path ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $path ) ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Table row with an input.
	 *
	 * @param string|array $path  Setting path.
	 * @param string       $label Label.
	 * @param mixed        $value Current value.
	 * @param string       $type  Input type (text|number|email|color).
	 * @param string       $desc  Description.
	 * @param array        $extra Extra attributes (placeholder, min, max, class).
	 */
	public static function input( $path, $label, $value, $type = 'text', $desc = '', array $extra = array() ) {
		$id    = 'sccm-' . sanitize_html_class( implode( '-', (array) $path ) );
		$attrs = '';
		foreach ( $extra as $attr => $attr_value ) {
			$attrs .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $attr_value ) . '"';
		}
		$class = 'color' === $type ? 'sccm-color' : ( 'number' === $type ? 'small-text' : 'regular-text' );
		$html_type = 'color' === $type ? 'text' : $type;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $html_type ) . '" id="' . esc_attr( $id ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * Table row with a textarea.
	 *
	 * @param string|array $path        Setting path.
	 * @param string       $label       Label.
	 * @param mixed        $value       Current value.
	 * @param string       $desc        Description.
	 * @param string       $placeholder Placeholder.
	 * @param int          $rows        Rows.
	 */
	public static function textarea( $path, $label, $value, $desc = '', $placeholder = '', $rows = 3 ) {
		$id = 'sccm-' . sanitize_html_class( implode( '-', (array) $path ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $id ) . '" class="large-text" rows="' . (int) $rows . '" name="' . esc_attr( self::name( $path ) ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		self::desc( $desc );
		echo '</td></tr>';
	}

	/**
	 * A visual picker: small "screens" that show where the banner or widget will appear.
	 * Plain radio buttons underneath, so it works with the keyboard and without JavaScript.
	 *
	 * @param string|array $path    Setting path.
	 * @param string       $current Current value.
	 * @param array        $options value => label.
	 * @param string       $kind    banner|widget (decides how the preview is drawn).
	 */
	public static function picker( $path, $current, array $options, $kind ) {
		echo '<div class="sccm-picker sccm-picker--' . esc_attr( $kind ) . '" role="radiogroup">';
		foreach ( $options as $value => $label ) {
			echo '<label class="sccm-pos' . ( (string) $value === (string) $current ? ' is-selected' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( self::name( $path ) ) . '" value="' . esc_attr( $value ) . '"' . checked( (string) $value, (string) $current, false ) . '>';
			echo '<span class="sccm-pos__screen" aria-hidden="true"><i class="sccm-pos__ui sccm-pos__ui--' . esc_attr( $value ) . '"></i></span>';
			echo '<span class="sccm-pos__label">' . esc_html( $label ) . '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	/**
	 * Field description.
	 *
	 * @param string $desc Text (may contain <code>, <a>, <strong>).
	 */
	public static function desc( $desc ) {
		if ( $desc ) {
			echo '<p class="description">' . wp_kses( $desc, array( 'code' => array(), 'strong' => array(), 'a' => array( 'href' => true, 'target' => true ) ) ) . '</p>';
		}
	}

	/**
	 * Category options for selects.
	 *
	 * @param bool $optional_only Only refusable categories.
	 * @return array
	 */
	public static function category_options( $optional_only = false ) {
		$out = array();
		foreach ( SCCM_Categories::all() as $key => $cat ) {
			if ( $optional_only && $cat['locked'] ) {
				continue;
			}
			$out[ $key ] = $cat['label'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Handlers */

	/**
	 * Stop unless the current user may manage the plugin (each handler then checks its nonce).
	 */
	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'smart-cookie-consent-manager' ), 403 );
		}
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $tab   Tab.
	 * @param string $msg   Message.
	 * @param bool   $error Error flag.
	 * @param array  $args  Extra args.
	 */
	private static function back( $tab, $msg, $error = false, array $args = array() ) {
		self::flash( $msg, $error );
		$args['sccm_notice'] = 1;
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Keep a message for the next admin page view of the current user. Messages are not put in
	 * the URL, so a link cannot show a made-up message in the plugin screens.
	 *
	 * @param string $msg   Message.
	 * @param bool   $error Error flag.
	 */
	private static function flash( $msg, $error = false ) {
		set_transient(
			'sccm_notice_' . get_current_user_id(),
			array(
				'msg'   => (string) $msg,
				'error' => (bool) $error,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Save settings of a tab.
	 */
	public static function handle_save() {
		self::require_cap();
		check_admin_referer( 'sccm_save' );
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'dashboard';
		$input = isset( $_POST['sccm'] ) && is_array( $_POST['sccm'] ) ? wp_unslash( $_POST['sccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in SCCM_Settings::sanitize().

		// The Settings tab lists the known services as ticked boxes: unticked ones are the disabled ones.
		if ( isset( $_POST['sccm_services_form'] ) ) {
			$active                     = isset( $_POST['sccm_services'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sccm_services'] ) ) : array();
			$input['disabled_services'] = array_values( array_diff( array_keys( SCCM_Services::active_candidates() ), $active ) );
			$input['rules']             = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();
		}

		$before = array( SCCM_Settings::get( 'scan_schedule' ), (int) SCCM_Settings::get( 'alert_hour' ) );
		SCCM_Settings::update( $input );
		if ( array( SCCM_Settings::get( 'scan_schedule' ), (int) SCCM_Settings::get( 'alert_hour' ) ) !== $before ) {
			SCCM_Install::schedule_events();
		}
		/**
		 * Fires after settings are saved (e.g. to purge page caches).
		 *
		 * @param string $tab Tab.
		 */
		do_action( 'sccm_settings_saved', $tab );
		self::back( $tab, __( 'Settings saved. Page caches of supported caching plugins and hosts were cleared; if you use another cache or a CDN, clear it so visitors get the new settings.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Add or edit a registry row.
	 */
	public static function handle_cookie_save() {
		self::require_cap();
		check_admin_referer( 'sccm_cookie_save' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = isset( $_POST['cookie'] ) && is_array( $_POST['cookie'] ) ? wp_unslash( $_POST['cookie'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in SCCM_Cookies::save().
		if ( ! $id ) {
			$data['source'] = 'manual';
		}
		$result = SCCM_Cookies::save( $data, $id );
		if ( ! $result ) {
			self::back( 'cookies', __( 'Could not save the cookie. Please check the name.', 'smart-cookie-consent-manager' ), true );
		}
		self::back( 'cookies', __( 'Cookie saved.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Delete a registry row.
	 */
	public static function handle_cookie_delete() {
		self::require_cap();
		check_admin_referer( 'sccm_cookie_delete' );
		SCCM_Cookies::delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::back( 'cookies', __( 'Cookie deleted.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Approve (set category + active) or ignore a pending row.
	 */
	public static function handle_cookie_status() {
		self::require_cap();
		check_admin_referer( 'sccm_cookie_status' );
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active';
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		$data     = array( 'status' => 'ignored' === $status ? 'ignored' : 'active' );
		if ( 'active' === $data['status'] ) {
			// Never guess: the site owner decides what an unknown cookie is for.
			if ( ! SCCM_Categories::is_valid( $category ) ) {
				self::back( 'cookies', __( 'Please choose a category for the cookie first.', 'smart-cookie-consent-manager' ), true );
			}
			$data['category'] = $category;
		}
		SCCM_Cookies::save( $data, $id );
		self::back( 'cookies', 'active' === $data['status'] ? __( 'Cookie approved. It now appears in the cookie list.', 'smart-cookie-consent-manager' ) : __( 'Cookie ignored. It will not be reported again.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Ignore everything that waits for review.
	 */
	public static function handle_ignore_all() {
		self::require_cap();
		check_admin_referer( 'sccm_ignore_all' );
		$changed = SCCM_Cookies::bulk_status( 'pending', 'ignored' );
		/* translators: %d: number of cookies */
		self::back( 'cookies', sprintf( _n( '%d cookie ignored.', '%d cookies ignored.', $changed, 'smart-cookie-consent-manager' ), $changed ) );
	}

	/**
	 * Add a library service's cookies to the registry.
	 */
	public static function handle_add_service() {
		self::require_cap();
		check_admin_referer( 'sccm_add_service' );
		$service = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		$added   = SCCM_Cookies::add_service( $service, 'library' );
		/* translators: %d: number of cookies */
		self::back( 'cookies', sprintf( __( '%d cookie(s) added from the library.', 'smart-cookie-consent-manager' ), $added ) );
	}

	/**
	 * Run the scanner now.
	 */
	public static function handle_scan_now() {
		self::require_cap();
		check_admin_referer( 'sccm_scan_now' );
		SCCM_Scanner::begin( true );
		// One request can only scan for so long (hosts stop requests after 30–60 s): the rest
		// continues in the background.
		if ( ! SCCM_Scanner::step( 2 * SCCM_Scanner::STEP_SECONDS ) ) {
			$progress = SCCM_Scanner::progress();
			wp_schedule_single_event( time() + 30, SCCM_Scanner::CONTINUE_EVENT );
			/* translators: 1: pages checked, 2: pages planned */
			self::back( 'cookies', sprintf( __( 'Scan started: %1$d of %2$d page(s) checked. The rest is scanned in the background in the next minutes.', 'smart-cookie-consent-manager' ), $progress['done'], $progress['total'] ) );
		}
		$results = SCCM_Scanner::finish();
		/* translators: 1: pages, 2: services */
		self::back( 'cookies', sprintf( __( 'Scan finished: %1$d page(s) checked, %2$d service(s) detected.', 'smart-cookie-consent-manager' ), count( $results['pages'] ), count( $results['services'] ) ) );
	}

	/**
	 * Browser scan, step 1: run the server scan and hand out a scan-mode token and the pages.
	 */
	public static function ajax_scan_start() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'smart-cookie-consent-manager' ) ), 403 );
		}
		check_ajax_referer( 'sccm_browser_scan' );
		$first   = false === get_option( SCCM_Scanner::RESULT_OPTION );
		$counts  = SCCM_Cookies::counts();
		SCCM_Scanner::begin( true );
		$token = SCCM_Scanner::scan_token( $first, $counts );
		$urls  = SCCM_Scanner::browser_scan_urls( $token );
		SCCM_Scanner::browser_begin( $token, $urls );
		wp_send_json_success( array_merge( array( 'token' => $token, 'urls' => $urls, 'total' => count( $urls ) ), self::scan_server_step() ) );
	}

	/**
	 * AJAX: continue a "Scan now" whose page was left (called by any plugin admin page).
	 */
	public static function ajax_scan_resume() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'smart-cookie-consent-manager' ) ), 403 );
		}
		check_ajax_referer( 'sccm_browser_scan' );
		$state = SCCM_Scanner::browser_state();
		if ( ! $state ) {
			wp_send_json_success( array( 'active' => false ) );
		}
		// Another tab still reports progress: let it work.
		if ( time() - (int) $state['beat'] < 45 ) {
			wp_send_json_success( array( 'active' => true, 'busy' => true ) );
		}
		$progress = SCCM_Scanner::progress();
		wp_send_json_success(
			array(
				'active'   => true,
				'token'    => $state['token'],
				'urls'     => array_values( array_diff( $state['urls'], $state['done'] ) ),
				'total'    => count( $state['urls'] ),
				'opened'   => count( $state['done'] ),
				'done'     => $progress['done'],
				'finished' => ! get_option( SCCM_Scanner::STATE_OPTION ),
			)
		);
	}

	/**
	 * AJAX: the next server step of "Scan now" (the browser calls it until the server part is done).
	 */
	public static function ajax_scan_server() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'smart-cookie-consent-manager' ) ), 403 );
		}
		check_ajax_referer( 'sccm_browser_scan' );
		SCCM_Scanner::browser_touch();
		wp_send_json_success( self::scan_server_step() );
	}

	/**
	 * Scan the next pages on the server for about STEP_SECONDS; finish when all are done.
	 *
	 * @return array done, total, finished.
	 */
	private static function scan_server_step() {
		$finished = SCCM_Scanner::step( SCCM_Scanner::STEP_SECONDS );
		$progress = SCCM_Scanner::progress();
		if ( $finished ) {
			SCCM_Scanner::finish();
		} else {
			SCCM_Scanner::keep_alive();
		}
		return array(
			'done'     => $progress['done'],
			'total'    => $progress['total'],
			'finished' => $finished,
		);
	}

	/**
	 * Browser scan, step 2: record what the hidden frame found.
	 */
	public static function ajax_scan_report() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'smart-cookie-consent-manager' ) ), 403 );
		}
		check_ajax_referer( 'sccm_browser_scan' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$valid = SCCM_Scanner::valid_scan_token( $token );
		if ( ! $valid ) {
			wp_send_json_error( array( 'message' => __( 'The scan took too long. Please start it again.', 'smart-cookie-consent-manager' ) ), 400 );
		}
		$data = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated in record_browser_scan().
		$data = is_array( $data ) ? $data : array();
		$sum  = SCCM_Scanner::record_browser_scan( $data, $valid, $token );
		$left = SCCM_Scanner::browser_progress( (array) ( $data['done'] ?? array() ), ! empty( $_POST['leaving'] ) );

		// Reports come every few pages; only the final one finishes the scan.
		if ( empty( $_POST['final'] ) ) {
			wp_send_json_success( array( 'left' => count( $left ) ) );
		}
		SCCM_Scanner::browser_end();
		$browser = (array) ( get_option( SCCM_Scanner::RESULT_OPTION )['browser'] ?? array() );
		$pages   = (int) ( $browser['pages'] ?? 0 );
		$blocked = (int) ( $browser['blocked'] ?? 0 );

		$message = sprintf(
			/* translators: 1: pages, 2: cookies added automatically, 3: cookies waiting for review */
			__( 'Scan finished: %1$d page(s) opened in your browser. %2$d new cookie(s) were recognised and added automatically, %3$d need your review.', 'smart-cookie-consent-manager' ),
			$pages,
			$sum['active'],
			$sum['pending']
		);
		if ( $blocked && $blocked >= $pages ) {
			$message .= ' ' . __( 'Your website did not allow itself to be opened in a frame (a security header), so only the server scan ran. Cookies set by scripts may be missing.', 'smart-cookie-consent-manager' );
		}
		self::flash( $message );
		wp_send_json_success( array( 'redirect' => self::url( 'cookies', array( 'sccm_notice' => 1 ) ) ) );
	}

	/**
	 * Send the email digest now.
	 */
	public static function handle_send_digest() {
		self::require_cap();
		check_admin_referer( 'sccm_send_digest' );
		$sent = SCCM_Scanner::send_digest();
		self::back( 'settings', $sent ? __( 'Email sent.', 'smart-cookie-consent-manager' ) : __( 'Nothing to send, or the email could not be sent.', 'smart-cookie-consent-manager' ), ! $sent );
	}

	/**
	 * Send a sample alert email to the configured recipients.
	 */
	public static function handle_send_test_email() {
		self::require_cap();
		check_admin_referer( 'sccm_send_test_email' );
		$sent = SCCM_Scanner::send_test();
		if ( $sent ) {
			/* translators: %s: email addresses */
			self::back( 'settings', sprintf( __( 'Sample email sent to %s. If it does not arrive, check your spam folder or your site\'s email setup.', 'smart-cookie-consent-manager' ), implode( ', ', SCCM_Settings::alert_recipients() ) ) );
		}
		self::back( 'settings', __( 'The sample email could not be sent. Your website may not be set up to send email.', 'smart-cookie-consent-manager' ), true );
	}

	/**
	 * "Test record saving": store a record directly, then the way a visitor's browser does it
	 * (admin-ajax.php from the server), report both results and remove the test records.
	 */
	public static function handle_test_record() {
		self::require_cap();
		check_admin_referer( 'sccm_test_record' );
		if ( ! SCCM_Settings::get( 'log_enabled' ) ) {
			self::back( 'records', __( 'Records are switched off: Settings → Consent records → "Keep a record of every choice".', 'smart-cookie-consent-manager' ), true );
		}
		$messages = array();
		$failed   = false;

		// 1. The database.
		$id     = wp_generate_uuid4();
		$direct = SCCM_Consent_Log::insert(
			array(
				'consent_id' => $id,
				'choice'     => 'accept_all',
				'categories' => array(),
				'url'        => home_url( '/' ),
			)
		);
		SCCM_Consent_Log::delete_by_consent_id( $id );
		if ( is_wp_error( $direct ) ) {
			$failed     = true;
			$messages[] = $direct->get_error_message();
		} else {
			$messages[] = __( 'Database: OK, a record was saved.', 'smart-cookie-consent-manager' );
		}

		// 2. The route visitors' browsers use when the REST API is blocked (with a one-time pass,
		// so the test is not stopped by the per-IP limit meant for visitors).
		$id   = wp_generate_uuid4();
		$pass = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( 'sccm_record_test_' . $pass, 1, MINUTE_IN_SECONDS );
		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 15,
				'sslverify' => (bool) apply_filters( 'sccm_scan_sslverify', true ),
				'body'      => array(
					'action'       => 'sccm_consent',
					'sccm_test'    => $pass,
					'payload'      => wp_json_encode(
						array(
							'consent_id' => $id,
							'choice'     => 'accept_all',
							'categories' => array(),
							'url'        => home_url( '/' ),
						)
					),
					'f_consent_id' => $id,
					'f_choice'     => 'accept_all',
					'f_url'        => home_url( '/' ),
				),
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body = is_wp_error( $response ) ? $response->get_error_message() : wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) );
		SCCM_Consent_Log::delete_by_consent_id( $id );
		if ( 200 === $code && false !== strpos( $body, '"success":true' ) ) {
			$messages[] = __( 'Visitor route (admin-ajax.php): OK.', 'smart-cookie-consent-manager' );
		} else {
			$failed = true;
			/* translators: 1: HTTP status or 0, 2: reply */
			$messages[] = sprintf( __( 'Visitor route (admin-ajax.php) from the server: HTTP %1$d, %2$s. A password-protected staging site or a firewall that blocks requests from the server itself can cause this test to fail even when visitors get through.', 'smart-cookie-consent-manager' ), $code, mb_substr( $body, 0, 200 ) );
		}
		self::back( 'records', implode( ' ', $messages ), $failed );
	}

	/**
	 * Download consent records as CSV.
	 */
	public static function handle_export_log() {
		self::require_cap();
		check_admin_referer( 'sccm_export_log' );
		$args = self::log_filters( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and sanitized in log_filters().
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=consent-log-' . gmdate( 'Y-m-d' ) . '.csv' );
		SCCM_Consent_Log::stream_csv( $args );
		exit;
	}

	/**
	 * Purge records older than the retention period.
	 */
	public static function handle_purge_log() {
		self::require_cap();
		check_admin_referer( 'sccm_purge_log' );
		$deleted = SCCM_Consent_Log::purge( (int) SCCM_Settings::get( 'retention_months' ) );
		/* translators: %d: rows */
		self::back( 'records', sprintf( __( '%d old record(s) deleted.', 'smart-cookie-consent-manager' ), $deleted ) );
	}

	/**
	 * Delete all consent records.
	 */
	public static function handle_delete_log() {
		self::require_cap();
		check_admin_referer( 'sccm_delete_log' );
		SCCM_Consent_Log::delete_all();
		self::back( 'records', __( 'All consent records deleted.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Download settings JSON.
	 */
	public static function handle_export_settings() {
		self::require_cap();
		check_admin_referer( 'sccm_export_settings' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cookie-consent-settings-' . gmdate( 'Y-m-d' ) . '.json' );
		echo SCCM_Settings::export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Import settings JSON (upload).
	 */
	public static function handle_import_settings() {
		self::require_cap();
		check_admin_referer( 'sccm_import_settings' );
		if ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::back( 'tools', __( 'Please choose a file.', 'smart-cookie-consent-manager' ), true );
		}
		$json   = file_get_contents( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$result = SCCM_Settings::import_json( (string) $json, ! empty( $_POST['with_cookies'] ) );
		if ( is_wp_error( $result ) ) {
			self::back( 'tools', $result->get_error_message(), true );
		}
		self::back( 'tools', __( 'Settings imported.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Ask every visitor again.
	 */
	public static function handle_bump_version() {
		self::require_cap();
		check_admin_referer( 'sccm_bump_version' );
		$version = SCCM_Cookies::bump_version();
		/* translators: %d: version */
		self::back( 'tools', sprintf( __( 'Consent version is now %d. Every visitor will be asked again (clear your page cache).', 'smart-cookie-consent-manager' ), $version ) );
	}

	/**
	 * Create a Cookie Policy page with the shortcode.
	 */
	public static function handle_create_policy_page() {
		self::require_cap();
		check_admin_referer( 'sccm_create_policy_page' );
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Cookie Policy', 'smart-cookie-consent-manager' ),
				'post_content' => "<!-- wp:paragraph -->\n<p>" . esc_html__( 'This page explains which cookies this website uses and why. You can change your choice at any time.', 'smart-cookie-consent-manager' ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[sccm_cookie_policy]\n<!-- /wp:shortcode -->",
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			self::back( 'dashboard', $page_id->get_error_message(), true );
		}
		SCCM_Settings::update( array( 'policy_page_id' => $page_id ) );
		self::back( 'dashboard', __( 'Cookie Policy page created and linked in the banner.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Reset all settings to defaults (registry and log are kept).
	 */
	public static function handle_reset_settings() {
		self::require_cap();
		check_admin_referer( 'sccm_reset_settings' );
		update_option( SCCM_Settings::OPTION, SCCM_Settings::defaults(), false );
		SCCM_Settings::flush();
		SCCM_Install::schedule_events();
		self::back( 'tools', __( 'Settings reset to defaults.', 'smart-cookie-consent-manager' ) );
	}

	/**
	 * Read log filters from a request array.
	 *
	 * @param array $source $_GET or $_POST.
	 * @return array
	 */
	public static function log_filters( array $source ) {
		$args = array();
		foreach ( array( 'search', 'choice', 'from', 'to' ) as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $source[ $key ] ) );
			}
		}
		return $args;
	}
}
