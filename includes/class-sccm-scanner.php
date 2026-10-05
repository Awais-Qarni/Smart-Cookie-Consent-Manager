<?php
/**
 * Cookie scanner.
 *
 * Two sources feed the registry:
 *  1. Server-side scan (cron + "Scan now") — fetches key pages, reads Set-Cookie headers and
 *     third-party scripts/iframes/fonts, and matches them against the service library.
 *  2. Visitor-side detection (sccm-frontend.js → REST /report) — catches cookies set by JS.
 *     Only unknown cookies reported by several different visitors are listed (see
 *     SCCM_Cookies), and logged-in users never report.
 * Known cookies are added automatically as Active in the right category; unknown ones wait as
 * "Needs review". New items are reported by email in a daily digest.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner and scheduled tasks.
 */
class SCCM_Scanner {

	const RESULT_OPTION = 'sccm_last_scan';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'sccm_scan_event', array( __CLASS__, 'run' ) );
		add_action( 'sccm_daily_event', array( __CLASS__, 'daily' ) );
	}

	/**
	 * Run a server-side scan.
	 *
	 * @param bool $manual True for "Scan now" (fewer pages and shorter timeouts, so the admin
	 *                     request cannot run into the PHP time limit).
	 * @return array Results (also stored in the sccm_last_scan option).
	 */
	public static function run( $manual = false ) {
		$first   = false === get_option( self::RESULT_OPTION );
		$results = array(
			'time'         => time(),
			'pages'        => array(),
			'services'     => array(),
			'unclassified' => array(),
			'cookies'      => array(),
			'notes'        => array(),
		);
		$urls    = self::urls();
		if ( $manual ) {
			$urls = array_slice( $urls, 0, 6 );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}

		// Changes found by a scan ask visitors again at most once, and not at all on the very
		// first scan (nobody has consented to an older list yet).
		SCCM_Cookies::bulk(
			function () use ( &$results, $urls, $manual ) {
				self::scan_pages( $urls, $manual ? 10 : 20, $results );

				// Detected services: make sure their known cookies are in the registry.
				foreach ( array_keys( $results['services'] ) as $service_key ) {
					$added = SCCM_Cookies::add_service( $service_key, 'scanner' );
					if ( $added ) {
						$service = SCCM_Services::get( $service_key );
						foreach ( $service['cookies'] as $cookie ) {
							if ( empty( $cookie[4] ) ) {
								SCCM_Cookies::queue_alert( $cookie[0], isset( $cookie[3] ) ? $cookie[3] : 'cookie', 'active', $service['category'] );
							}
						}
					}
				}
			},
			! $first
		);

		$results['cookies']      = array_keys( $results['cookies'] );
		$results['unclassified'] = array_values( $results['unclassified'] );
		foreach ( $results['unclassified'] as $item ) {
			SCCM_Cookies::queue_alert( $item['host'], 'resource', 'unclassified', '' );
		}
		update_option( self::RESULT_OPTION, $results, false );
		return $results;
	}

	/**
	 * Fetch the pages and feed cookies / third-party resources into the results.
	 *
	 * @param array $urls    Pages.
	 * @param int   $timeout Seconds per page.
	 * @param array $results Results (by reference).
	 */
	private static function scan_pages( array $urls, $timeout, array &$results ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 3,
					'user-agent'  => 'SmartCookieConsentManager-Scanner/' . SCCM_VERSION . '; ' . home_url(),
					/**
					 * Verify SSL when scanning (turn off for staging with self-signed certificates).
					 *
					 * @param bool $verify Verify.
					 */
					'sslverify'   => (bool) apply_filters( 'sccm_scan_sslverify', true ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$results['pages'][ $url ] = $response->get_error_message();
				continue;
			}
			$results['pages'][ $url ] = (int) wp_remote_retrieve_response_code( $response );

			foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
				if ( is_object( $cookie ) && ! empty( $cookie->name ) && SCCM_REST::valid_name( $cookie->name ) ) {
					$results['cookies'][ $cookie->name ] = true;
					SCCM_Cookies::record_seen( $cookie->name, 'cookie', 'scanner' );
				}
			}

			self::analyse_html( (string) wp_remote_retrieve_body( $response ), $site_host, $results );
		}
	}

	/**
	 * Analyse one page's HTML for third-party resources.
	 *
	 * @param string $html      HTML.
	 * @param string $site_host Own host.
	 * @param array  $results   Results (by reference).
	 */
	public static function analyse_html( $html, $site_host, array &$results ) {
		if ( '' === $html ) {
			return;
		}
		$custom = (array) SCCM_Settings::get( 'rules' );

		// External resources: scripts, iframes, stylesheets (incl. already-blocked ones).
		preg_match_all( '#<(script|iframe|link)\b([^>]*)>#i', $html, $tags, PREG_SET_ORDER );
		foreach ( $tags as $tag ) {
			$attrs = $tag[2];
			foreach ( array( 'src', 'data-sccm-src', 'data-src', 'data-sccm-datasrc', 'href', 'data-sccm-href' ) as $attr ) {
				$url = SCCM_Blocker::attr( $attrs, $attr );
				if ( ! $url ) {
					continue;
				}
				if ( 'link' === strtolower( $tag[1] ) && ! preg_match( '/rel\s*=\s*["\']?[^"\'>]*(stylesheet|preconnect|dns-prefetch|preload)/i', $attrs ) ) {
					continue;
				}
				$url  = html_entity_decode( $url, ENT_QUOTES );
				$host = wp_parse_url( ( 0 === strpos( $url, '//' ) ? 'https:' : '' ) . $url, PHP_URL_HOST );
				if ( ! $host || $host === $site_host ) {
					continue;
				}
				$service = SCCM_Services::match_resource( $url );
				if ( $service ) {
					self::add_service_hit( $results, $service, $url );
					continue;
				}
				$covered = false;
				foreach ( $custom as $rule ) {
					if ( false !== stripos( $url, $rule['pattern'] ) ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered && ! isset( $results['unclassified'][ $host ] ) ) {
					$results['unclassified'][ $host ] = array(
						'host' => $host,
						'url'  => substr( $url, 0, 300 ),
						'tag'  => strtolower( $tag[1] ),
					);
				}
			}
		}

		// Inline scripts that load services (e.g. pixel snippets).
		preg_match_all( '#<script\b[^>]*>(.*?)</script\s*>#is', $html, $inline );
		foreach ( $inline[1] as $code ) {
			if ( '' === trim( $code ) ) {
				continue;
			}
			$service = SCCM_Services::match_resource( $code );
			if ( $service ) {
				self::add_service_hit( $results, $service, 'inline script' );
			}
		}

		// Fonts or trackers pulled from inside CSS cannot be blocked by tag rewriting.
		if ( preg_match( '#@import\s+url\(\s*["\']?https?://fonts\.googleapis\.com#i', $html ) || preg_match( '#fonts\.gstatic\.com#i', self::inline_css( $html ) ) ) {
			$results['notes']['css_fonts'] = __( 'Google Fonts are loaded from inside CSS (e.g. an @import or a combined stylesheet). These requests cannot be blocked by the plugin. Host the fonts on your own server to avoid them.', 'smart-cookie-consent-manager' );
		}
	}

	/**
	 * Record a detected service.
	 *
	 * @param array  $results Results (by reference).
	 * @param string $key     Service id.
	 * @param string $where   URL or "inline script".
	 */
	private static function add_service_hit( array &$results, $key, $where ) {
		$service = SCCM_Services::get( $key );
		if ( ! isset( $results['services'][ $key ] ) ) {
			$results['services'][ $key ] = array(
				'name'     => $service['name'],
				'category' => $service['category'],
				'examples' => array(),
			);
		}
		if ( count( $results['services'][ $key ]['examples'] ) < 3 && ! in_array( $where, $results['services'][ $key ]['examples'], true ) ) {
			$results['services'][ $key ]['examples'][] = substr( $where, 0, 200 );
		}
	}

	/**
	 * Inline <style> contents of a page.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function inline_css( $html ) {
		preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m );
		return implode( "\n", $m[1] );
	}

	/**
	 * Pages to scan: home, cookie policy, latest pages and posts.
	 *
	 * @return array
	 */
	public static function urls() {
		$urls   = array( home_url( '/' ) );
		$policy = SCCM_Frontend::policy_url();
		if ( $policy ) {
			$urls[] = $policy;
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'orderby'        => 'modified',
				'fields'         => 'ids',
				'has_password'   => false,
			)
		);
		foreach ( $posts as $post_id ) {
			$urls[] = get_permalink( $post_id );
		}
		/**
		 * Filter the URLs the scanner fetches.
		 *
		 * @param array $urls URLs.
		 */
		return array_slice( array_unique( array_filter( apply_filters( 'sccm_scan_urls', $urls ) ) ), 0, 15 );
	}

	/**
	 * Daily housekeeping: email digest + consent log retention.
	 */
	public static function daily() {
		self::send_digest();
		SCCM_Consent_Log::purge( (int) SCCM_Settings::get( 'retention_months' ) );
	}

	/**
	 * Email the queued scanner findings (max once per ~day).
	 *
	 * @param bool $force Ignore the once-per-day limit.
	 * @return bool Whether an email was sent.
	 */
	public static function send_digest( $force = false ) {
		$queue = (array) get_option( 'sccm_pending_alert', array() );
		if ( ! $queue || ! SCCM_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}
		$last = (int) get_option( 'sccm_last_alert', 0 );
		if ( ! $force && time() - $last < 23 * HOUR_IN_SECONDS ) {
			return false;
		}

		$sent = self::mail( self::build_email( $queue ) );
		if ( $sent ) {
			update_option( 'sccm_last_alert', time(), false );
			delete_option( 'sccm_pending_alert' );
		}
		return $sent;
	}

	/**
	 * Send a sample email so the site owner can see the design and check delivery.
	 *
	 * @return bool
	 */
	public static function send_test() {
		$now   = time();
		$queue = array(
			array(
				'name'     => 'example_tracking_id',
				'type'     => 'cookie',
				'status'   => 'pending',
				'category' => '',
				'time'     => $now,
			),
			array(
				'name'     => '_ga',
				'type'     => 'cookie',
				'status'   => 'active',
				'category' => 'analytics',
				'time'     => $now,
			),
			array(
				'name'     => '_fbp',
				'type'     => 'cookie',
				'status'   => 'active',
				'category' => 'marketing',
				'time'     => $now,
			),
		);
		$email = self::build_email( $queue, true );
		return self::mail( $email );
	}

	/**
	 * Send an email built by build_email() to every recipient (HTML + plain-text version).
	 *
	 * @param array $email subject, html, text.
	 * @return bool
	 */
	private static function mail( array $email ) {
		$alt = static function ( $phpmailer ) use ( $email ) {
			$phpmailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( SCCM_Settings::alert_recipients(), $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $sent;
	}

	/**
	 * Build the digest: subject, HTML (inline styles, table layout for email clients) and text.
	 *
	 * @param array $queue Items from SCCM_Cookies::queue_alert().
	 * @param bool  $test  Mark the email as a sample.
	 * @return array subject, html, text.
	 */
	public static function build_email( array $queue, $test = false ) {
		$groups = array(
			'pending'      => array(),
			'active'       => array(),
			'unclassified' => array(),
		);
		foreach ( $queue as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : 'active';
			if ( ! isset( $groups[ $status ] ) ) {
				continue;
			}
			$groups[ $status ][ $item['type'] . ':' . $item['name'] ] = $item;
		}
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$review   = admin_url( 'admin.php?page=sccm&tab=cookies' );
		$settings = admin_url( 'admin.php?page=sccm&tab=settings' );
		$n_review = count( $groups['pending'] );
		$n_auto   = count( $groups['active'] );
		$n_other  = count( $groups['unclassified'] );

		if ( $n_review ) {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] %2$d cookie needs your review', '[%1$s] %2$d cookies need your review', $n_review, 'smart-cookie-consent-manager' ), $site, $n_review );
		} else {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] Cookie scan: %2$d cookie added automatically', '[%1$s] Cookie scan: %2$d cookies added automatically', max( 1, $n_auto ), 'smart-cookie-consent-manager' ), $site, max( 1, $n_auto ) );
		}
		if ( $test ) {
			/* translators: %s: subject */
			$subject = sprintf( __( 'Sample: %s', 'smart-cookie-consent-manager' ), $subject );
		}

		$recipients = implode( ', ', SCCM_Settings::alert_recipients() );
		$limit      = 25;

		/* ---- HTML */
		$font  = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$h     = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
		$h    .= '<body style="margin:0;padding:0;background:#f3f4f6;' . $font . '">';
		$h    .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">';
		$h    .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">';

		// Header.
		$h .= '<tr><td style="background:#1f2937;padding:22px 28px;">';
		$h .= '<div style="' . $font . 'font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#9ca3af;">' . esc_html( $site ) . '</div>';
		$h .= '<div style="' . $font . 'font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">' . esc_html__( 'Cookie scan report', 'smart-cookie-consent-manager' ) . '</div>';
		$h .= '</td></tr>';

		// Summary numbers.
		$h .= '<tr><td style="padding:24px 28px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
		$h .= self::email_stat( $n_review, _n( 'needs your review', 'need your review', $n_review, 'smart-cookie-consent-manager' ), $n_review ? '#b45309' : '#6b7280', $n_review ? '#fffbeb' : '#f9fafb' );
		$h .= '<td width="12"></td>';
		$h .= self::email_stat( $n_auto, __( 'added automatically', 'smart-cookie-consent-manager' ), '#15803d', '#f0fdf4' );
		$h .= '</tr></table></td></tr>';

		// Needs review.
		if ( $n_review ) {
			$h .= '<tr><td style="padding:16px 28px 0;">';
			$h .= '<div style="' . $font . 'font-size:16px;font-weight:700;color:#111827;">' . esc_html__( 'Needs your review', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= '<div style="' . $font . 'font-size:14px;line-height:1.5;color:#4b5563;margin:4px 0 10px;">' . esc_html__( 'We could not tell what these cookies are for. Choose a category for each one and click Approve. Until then they are not shown to visitors.', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= self::email_rows( array_slice( $groups['pending'], 0, $limit ), $font, 'pending' );
			$h .= self::email_more( count( $groups['pending'] ) - $limit, $font );
			$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;"><tr><td style="background:#1f2937;border-radius:6px;"><a href="' . esc_url( $review ) . '" style="' . $font . 'display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">' . esc_html__( 'Review and approve', 'smart-cookie-consent-manager' ) . '</a></td></tr></table>';
			$h .= '</td></tr>';
		}

		// Added automatically.
		if ( $n_auto ) {
			$h .= '<tr><td style="padding:20px 28px 0;">';
			$h .= '<div style="' . $font . 'font-size:16px;font-weight:700;color:#111827;">' . esc_html__( 'Added automatically', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= '<div style="' . $font . 'font-size:14px;line-height:1.5;color:#4b5563;margin:4px 0 10px;">' . esc_html__( 'These are well-known cookies. They were put in the right category for you. Nothing to do unless you disagree.', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= self::email_rows( array_slice( $groups['active'], 0, $limit ), $font, 'active' );
			$h .= self::email_more( count( $groups['active'] ) - $limit, $font );
			$h .= '</td></tr>';
		}

		// Other third-party resources.
		if ( $n_other ) {
			$hosts = array();
			foreach ( array_slice( $groups['unclassified'], 0, 8 ) as $item ) {
				$hosts[] = $item['name'];
			}
			$h .= '<tr><td style="padding:20px 28px 0;">';
			$h .= '<div style="' . $font . 'font-size:16px;font-weight:700;color:#111827;">' . esc_html__( 'Other third-party resources', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= '<div style="' . $font . 'font-size:14px;line-height:1.5;color:#4b5563;margin:4px 0 6px;">' . esc_html__( 'External files were found that are not in the service library, so they are not blocked. If one of them tracks visitors, add a blocking rule in the plugin settings.', 'smart-cookie-consent-manager' ) . '</div>';
			$h .= '<div style="' . $font . 'font-size:13px;line-height:1.6;color:#374151;font-family:Menlo,Consolas,monospace;">' . esc_html( implode( ', ', $hosts ) ) . ( $n_other > 8 ? ' …' : '' ) . '</div>';
			$h .= '</td></tr>';
		}

		// Footer.
		$h .= '<tr><td style="padding:28px;"><div style="border-top:1px solid #e5e7eb;padding-top:16px;' . $font . 'font-size:12px;line-height:1.6;color:#6b7280;">';
		$h .= esc_html__( 'Sent by Smart Cookie Consent Manager', 'smart-cookie-consent-manager' ) . ' · ' . esc_html( $recipients ) . '<br>';
		$h .= '<a href="' . esc_url( $settings ) . '" style="color:#1d4ed8;">' . esc_html__( 'Change recipients or turn these emails off', 'smart-cookie-consent-manager' ) . '</a>';
		$h .= '</div></td></tr>';
		$h .= '</table></td></tr></table></body></html>';

		/* ---- Plain text */
		$t = $site . ' — ' . __( 'Cookie scan report', 'smart-cookie-consent-manager' ) . "\n" . str_repeat( '=', 40 ) . "\n\n";
		if ( $n_review ) {
			$t .= __( 'NEEDS YOUR REVIEW', 'smart-cookie-consent-manager' ) . ' (' . $n_review . ")\n";
			$t .= __( 'Choose a category for each cookie and click Approve. Until then visitors do not see them.', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( array_slice( $groups['pending'], 0, $limit ) as $item ) {
				$t .= '  - ' . $item['name'] . ' (' . $item['type'] . ")\n";
			}
			$t .= "\n" . __( 'Review and approve:', 'smart-cookie-consent-manager' ) . ' ' . $review . "\n\n";
		}
		if ( $n_auto ) {
			$t .= __( 'ADDED AUTOMATICALLY', 'smart-cookie-consent-manager' ) . ' (' . $n_auto . ")\n";
			foreach ( array_slice( $groups['active'], 0, $limit ) as $item ) {
				$t .= '  - ' . $item['name'] . ' → ' . SCCM_Categories::label( $item['category'] ) . "\n";
			}
			$t .= "\n";
		}
		if ( $n_other ) {
			$t .= __( 'OTHER THIRD-PARTY RESOURCES (not blocked)', 'smart-cookie-consent-manager' ) . ' (' . $n_other . ")\n";
			foreach ( array_slice( $groups['unclassified'], 0, 8 ) as $item ) {
				$t .= '  - ' . $item['name'] . "\n";
			}
			$t .= "\n";
		}
		$t .= '--' . "\n" . __( 'Change recipients or turn these emails off:', 'smart-cookie-consent-manager' ) . ' ' . $settings . "\n";

		return array(
			'subject' => $subject,
			'html'    => $h,
			'text'    => $t,
		);
	}

	/**
	 * One summary box (a big number and a caption) of the email.
	 *
	 * @param int    $number  Number.
	 * @param string $caption Caption (already escaped or plain).
	 * @param string $color   Number colour.
	 * @param string $bg      Box background.
	 * @return string
	 */
	private static function email_stat( $number, $caption, $color, $bg ) {
		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		return '<td width="49%" valign="top" style="background:' . esc_attr( $bg ) . ';border-radius:8px;padding:14px 16px;">'
			. '<div style="' . $font . 'font-size:30px;font-weight:700;line-height:1.1;color:' . esc_attr( $color ) . ';">' . (int) $number . '</div>'
			. '<div style="' . $font . 'font-size:13px;color:#4b5563;margin-top:2px;">' . esc_html( wp_strip_all_tags( $caption ) ) . '</div></td>';
	}

	/**
	 * Rows of cookie names (and categories) for the email.
	 *
	 * @param array  $items  Items.
	 * @param string $font   Font style.
	 * @param string $status pending|active.
	 * @return string
	 */
	private static function email_rows( array $items, $font, $status ) {
		$h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;border-collapse:separate;">';
		$i = 0;
		foreach ( $items as $item ) {
			$border = $i++ ? 'border-top:1px solid #e5e7eb;' : '';
			$right  = 'pending' === $status
				? esc_html( 'cookie' === $item['type'] ? __( 'Cookie', 'smart-cookie-consent-manager' ) : $item['type'] )
				: esc_html( SCCM_Categories::label( $item['category'] ) );
			$pill   = 'pending' === $status ? 'background:#fef3c7;color:#92400e;' : 'background:#dcfce7;color:#166534;';
			$h     .= '<tr><td style="' . $border . 'padding:10px 14px;' . $font . 'font-size:14px;color:#111827;"><span style="font-family:Menlo,Consolas,monospace;font-size:13px;">' . esc_html( $item['name'] ) . '</span></td>'
				. '<td align="right" style="' . $border . 'padding:10px 14px;"><span style="' . $font . 'display:inline-block;font-size:12px;font-weight:600;padding:2px 10px;border-radius:999px;' . $pill . '">' . $right . '</span></td></tr>';
		}
		return $h . '</table>';
	}

	/**
	 * "…and N more" line.
	 *
	 * @param int    $more Remaining items.
	 * @param string $font Font style.
	 * @return string
	 */
	private static function email_more( $more, $font ) {
		if ( $more < 1 ) {
			return '';
		}
		/* translators: %d: number of items */
		return '<div style="' . $font . 'font-size:13px;color:#6b7280;margin-top:6px;">' . esc_html( sprintf( __( '…and %d more. See the full list in the plugin.', 'smart-cookie-consent-manager' ), (int) $more ) ) . '</div>';
	}
}
