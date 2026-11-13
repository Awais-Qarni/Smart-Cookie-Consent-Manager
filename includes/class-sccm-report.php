<?php
/**
 * Weekly consent report: an email for stakeholders with the visitors' choices of the last 7
 * days (day by day, compared with the 7 days before), the last 8 weeks and the banner status.
 *
 * Sent every Monday (at the hour of the daily email) to the same recipients as the cookie
 * change email, one email per recipient, and any time with "Send report now" (Settings).
 * The charts are tables with fixed sizes and inline colours, so they show in every mail app
 * (Gmail and Outlook support neither SVG nor CSS charts).
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Weekly report data, email and schedule.
 */
class SCCM_Report {

	/**
	 * Option: the week (o-W of its Monday) the scheduled report was last sent for.
	 */
	const SENT_OPTION = 'sccm_report_sent';

	/**
	 * Option: result of the last report email (same shape as SCCM_Scanner::MAIL_OPTION).
	 */
	const MAIL_OPTION = 'sccm_last_report_mail';

	/**
	 * Colour of each choice (the Dashboard's; "Denied" is orange so it does not read as "no data").
	 */
	const COLORS = array(
		'accept_all' => '#16a34a',
		'custom'     => '#2563eb',
		'reject_all' => '#ea580c',
		'gpc'        => '#7c3aed',
	);

	/**
	 * Called by the daily check: on Monday (or the first daily check of a week, if Monday's was
	 * missed), send the report for the last 7 days once.
	 *
	 * @param DateTimeImmutable|null $now Current time (site time zone); for tests.
	 * @return bool Whether a report was sent.
	 */
	public static function maybe_send( $now = null ) {
		if ( ! SCCM_Settings::get( 'report_enabled' ) || ! SCCM_Settings::get( 'log_enabled' ) ) {
			return false;
		}
		$now  = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$week = $now->modify( 'monday this week' )->format( 'o-W' );
		$last = get_option( self::SENT_OPTION );
		if ( false === $last ) {
			// First check after installing or updating: the first report goes out next Monday,
			// not on a Thursday because this week had none yet.
			update_option( self::SENT_OPTION, $week, false );
			return false;
		}
		if ( $last === $week ) {
			return false;
		}
		// Noted before sending: a recipient whose email fails is not sent the others' again tomorrow
		// (the failure is shown on the Settings tab, and "Send report now" can repeat it).
		update_option( self::SENT_OPTION, $week, false );
		if ( ! self::start( false, $now ) ) {
			return false;
		}
		self::work();
		return true;
	}

	/**
	 * Option: the report being sent (recipients, how far, results); see start() / work().
	 */
	const JOB_OPTION = 'sccm_report_job';

	/**
	 * Cron hook: send the next emails of the report being sent.
	 */
	const EVENT = 'sccm_report_send_event';

	/**
	 * Seconds one step may start new emails for. A mail service can take 30 s to answer (or not
	 * answer); hosts stop requests after about 60 s, so a step stops early and the next continues.
	 */
	const STEP_SECONDS = 15;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::EVENT, array( __CLASS__, 'work' ) );
	}

	/**
	 * "Send report now": start sending in the background, so the page answers at once and a
	 * slow mail service cannot hold it (or have it stopped after the first recipient).
	 *
	 * @return int Number of recipients, 0 without any, -1 while a report is already being sent.
	 */
	public static function queue() {
		if ( self::sending() ) {
			return -1;
		}
		$count = self::start( true );
		if ( $count ) {
			spawn_cron();
		}
		return $count;
	}

	/**
	 * Send the report now, in this request (tests, WP-CLI).
	 *
	 * @param bool                   $manual True for "Send report now".
	 * @param DateTimeImmutable|null $now    Current time (site time zone).
	 * @return bool Whether every email was sent.
	 */
	public static function send( $manual = false, $now = null ) {
		return self::start( $manual, $now ) && self::work( PHP_INT_MAX );
	}

	/**
	 * The report being sent (or null): recipients, next one, sent, failed.
	 *
	 * @return array|null
	 */
	public static function sending() {
		$job = get_option( self::JOB_OPTION );
		// A report nobody worked on for 15 minutes (WP-Cron off, host stopped it) no longer blocks.
		return is_array( $job ) && time() - (int) $job['touched'] < 900 ? $job : null;
	}

	/**
	 * Note a new report for every recipient.
	 *
	 * @param bool                   $manual Sent on request.
	 * @param DateTimeImmutable|null $now    Current time (site time zone).
	 * @return int Number of recipients.
	 */
	private static function start( $manual, $now = null ) {
		$now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$to  = array_values( SCCM_Settings::alert_recipients() );
		if ( ! $to ) {
			return 0;
		}
		update_option(
			self::JOB_OPTION,
			array(
				'manual'  => (bool) $manual,
				'time'    => $now->getTimestamp(),
				'touched' => time(),
				'to'      => $to,
				'next'    => 0,
				'sent'    => array(),
				'failed'  => array(),
				'error'   => '',
				'subject' => '',
			),
			false
		);
		wp_clear_scheduled_hook( self::EVENT );
		wp_schedule_single_event( time(), self::EVENT );
		return count( $to );
	}

	/**
	 * Send the next emails of the report being sent, one recipient after the other, for at most
	 * STEP_SECONDS; then the next step (WP-Cron) continues. When all are done, the result is
	 * stored for the Settings tab.
	 *
	 * @param int $seconds Seconds this step may start new emails for.
	 * @return bool Whether the report is done and every email was sent.
	 */
	public static function work( $seconds = self::STEP_SECONDS ) {
		$job = get_option( self::JOB_OPTION );
		if ( ! is_array( $job ) ) {
			return false;
		}
		$started = microtime( true );
		$first   = (int) $job['next'];
		$total   = count( $job['to'] );
		// The step before was stopped while sending (host time limit): that email got no answer.
		if ( $job['next'] > count( $job['sent'] ) + count( $job['failed'] ) ) {
			$job['failed'][] = $job['to'][ $job['next'] - 1 ];
			$job['error']    = __( 'The mail service did not answer in time.', 'smart-cookie-consent-manager' );
		}
		$now  = ( new DateTimeImmutable( '@' . (int) $job['time'] ) )->setTimezone( wp_timezone() );
		$data = null;
		while ( $job['next'] < $total ) {
			// Every step sends at least one email, so the report always moves on.
			if ( $job['next'] > $first && microtime( true ) - $started > $seconds ) {
				$job['touched'] = time();
				update_option( self::JOB_OPTION, $job, false );
				wp_clear_scheduled_hook( self::EVENT );
				wp_schedule_single_event( time(), self::EVENT );
				return false;
			}
			$recipient = $job['to'][ $job['next'] ];
			++$job['next'];
			$job['touched'] = time();
			update_option( self::JOB_OPTION, $job, false );
			// If this request is stopped while sending, this event continues the report.
			wp_clear_scheduled_hook( self::EVENT );
			wp_schedule_single_event( time() + 120, self::EVENT );

			$data           = $data ? $data : self::data( $now );
			$email          = self::build_email( $data, $recipient, $job['manual'], $now );
			$job['subject'] = $email['subject'];
			$reason         = '';
			if ( SCCM_Scanner::send_one( $recipient, $email, $reason ) ) {
				$job['sent'][] = $recipient;
			} else {
				$job['failed'][] = $recipient;
				$job['error']    = '' !== $reason ? $reason : __( 'WordPress could not send the email (wp_mail returned false).', 'smart-cookie-consent-manager' );
			}
			update_option( self::JOB_OPTION, $job, false );
		}
		wp_clear_scheduled_hook( self::EVENT );
		delete_option( self::JOB_OPTION );
		$ok = ! $job['failed'];
		update_option(
			self::MAIL_OPTION,
			array(
				'time'    => time(),
				'ok'      => $ok,
				'to'      => $job['to'],
				'subject' => $job['subject'],
				/* translators: %s: email addresses */
				'error'   => $ok ? '' : wp_strip_all_tags( $job['error'] . ' ' . sprintf( __( 'Not sent to: %s.', 'smart-cookie-consent-manager' ), implode( ', ', $job['failed'] ) ) ),
			),
			false
		);
		return $ok;
	}

	/**
	 * The numbers: the last 7 full days (ending yesterday), the 7 days before, and 8 weeks.
	 *
	 * @param DateTimeImmutable $now Current time (site time zone).
	 * @return array
	 */
	public static function data( DateTimeImmutable $now ) {
		$last  = $now->setTime( 0, 0 )->modify( '-1 day' );
		$days  = SCCM_Consent_Log::daily( 56, $last );
		$weeks = array();
		foreach ( array_chunk( $days, 7, true ) as $chunk ) {
			$sum = array_fill_keys( SCCM_Consent_Log::CHOICES, 0 );
			foreach ( $chunk as $counts ) {
				foreach ( $counts as $choice => $n ) {
					$sum[ $choice ] += $n;
				}
			}
			$weeks[] = array(
				'start'  => (string) array_key_first( $chunk ),
				'counts' => $sum,
				'total'  => array_sum( $sum ),
			);
		}
		$this_week = end( $weeks );
		$prev_week = $weeks[ count( $weeks ) - 2 ];
		$first     = $last->modify( '-6 days' );
		$end       = $last->modify( '+1 day' );

		// Cookie list changes in these 7 days.
		$added   = 0;
		$pending = 0;
		foreach ( SCCM_Cookies::all_rows() as $row ) {
			$seen = $row['first_seen'] ? strtotime( $row['first_seen'] . ' UTC' ) : 0;
			if ( $seen >= $first->getTimestamp() && $seen < $end->getTimestamp() ) {
				if ( 'active' === $row['status'] ) {
					++$added;
				} elseif ( 'pending' === $row['status'] ) {
					++$pending;
				}
			}
		}
		$scan    = get_option( SCCM_Scanner::RESULT_OPTION );
		$version = (int) get_option( 'sccm_consent_version', 1 );
		$history = SCCM_Cookies::version_history();

		return array(
			'first'      => $first,
			'last'       => $last,
			'calendar'   => '1' === $first->format( 'N' ),
			'days'       => array_slice( $days, -7, 7, true ),
			'weeks'      => $weeks,
			'this'       => $this_week,
			'prev'       => $prev_week,
			'categories' => SCCM_Consent_Log::category_counts_between( $first->getTimestamp(), $end->getTimestamp() ),
			'cat_prev'   => SCCM_Consent_Log::category_counts_between( $first->modify( '-7 days' )->getTimestamp(), $first->getTimestamp() ),
			'list'       => SCCM_Scanner::list_summary(),
			'added'      => $added,
			'pending'    => $pending,
			'scan_time'  => is_array( $scan ) && ! empty( $scan['time'] ) ? (int) $scan['time'] : 0,
			'enabled'    => (bool) SCCM_Settings::get( 'enabled' ),
			'cm'         => SCCM_Settings::get( 'consent_mode' ),
			'gpc'        => (bool) SCCM_Settings::get( 'gpc_enabled' ),
			'version'    => $version,
			'since'      => isset( $history[ $version ]['t'] ) ? (int) $history[ $version ]['t'] : 0,
		);
	}

	/**
	 * Share in percent (0 when there is nothing to divide).
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return float
	 */
	private static function pct( $part, $whole ) {
		return $whole > 0 ? 100 * $part / $whole : 0.0;
	}

	/**
	 * A share as text: "62%", or "<1%" for a few (never "0%" when there are some).
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return string
	 */
	private static function share_text( $part, $whole ) {
		$pct = self::pct( $part, $whole );
		return $part > 0 && $pct < 0.5 ? '<1%' : number_format_i18n( round( $pct ) ) . '%';
	}

	/**
	 * Build the email for one recipient.
	 *
	 * @param array             $data      data().
	 * @param string            $recipient Address the email goes to.
	 * @param bool              $manual    Sent with "Send report now".
	 * @param DateTimeImmutable $now       Sending time.
	 * @return array subject, html, text.
	 */
	public static function build_email( array $data, $recipient, $manual, DateTimeImmutable $now ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$address  = (string) preg_replace( '#^https?://#i', '', untrailingslashit( home_url() ) );
		$this_w   = $data['this'];
		$prev_w   = $data['prev'];
		$total    = $this_w['total'];
		$accept   = self::pct( $this_w['counts']['accept_all'], $total );
		$denied   = self::pct( $this_w['counts']['reject_all'], $total );
		$p_accept = self::pct( $prev_w['counts']['accept_all'], $prev_w['total'] );
		$p_denied = self::pct( $prev_w['counts']['reject_all'], $prev_w['total'] );
		$cal      = $data['calendar'];
		$period   = wp_date( 'l j F', $data['first']->getTimestamp() ) . ' – ' . wp_date( 'l j F Y', $data['last']->getTimestamp() );
		$prev_txt = $cal ? __( 'last week', 'smart-cookie-consent-manager' ) : __( 'the 7 days before', 'smart-cookie-consent-manager' );

		$dates = wp_date( 'j M', $data['first']->getTimestamp() ) . ' – ' . wp_date( 'j M', $data['last']->getTimestamp() );
		$subject = $total
			/* translators: 1: site name, 2: number of choices, 3: percent, 4: dates */
			? sprintf( _n( '[%1$s] Weekly consent report: %2$s choice, %3$s%% allowed all (%4$s)', '[%1$s] Weekly consent report: %2$s choices, %3$s%% allowed all (%4$s)', $total, 'smart-cookie-consent-manager' ), $site, number_format_i18n( $total ), number_format_i18n( round( $accept ) ), $dates )
			/* translators: 1: site name, 2: dates */
			: sprintf( __( '[%1$s] Weekly consent report: no choices (%2$s)', 'smart-cookie-consent-manager' ), $site, $dates );
		$summary = self::summary( $data, $accept, $p_accept, $prev_txt );

		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$h    = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			. '<title>' . esc_html( $subject ) . '</title><style>'
			. ':root{color-scheme:light dark;supported-color-schemes:light dark;}a{text-decoration:none;}'
			. '@media (prefers-color-scheme:dark){.e-page{background:#111827 !important;}.e-card{background:#1f2937 !important;border-color:#374151 !important;}.e-box{background:#111827 !important;border-color:#374151 !important;}.e-h{color:#f9fafb !important;}.e-p{color:#d1d5db !important;}.e-muted{color:#9ca3af !important;}.e-track{background:#374151 !important;}.e-gap{background:#1f2937 !important;}.e-up{color:#4ade80 !important;}.e-down{color:#f87171 !important;}.e-line{border-color:#374151 !important;}}'
			. '[data-ogsb] .e-page{background:#111827 !important;}[data-ogsb] .e-card{background:#1f2937 !important;}[data-ogsc] .e-h{color:#f9fafb !important;}[data-ogsc] .e-p{color:#d1d5db !important;}[data-ogsc] .e-muted{color:#9ca3af !important;}'
			. '@media (max-width:620px){.e-pad{padding-left:18px !important;padding-right:18px !important;}.e-kpi{display:block !important;width:auto !important;margin-bottom:10px !important;}.e-kgap{display:none !important;}}'
			. '</style>'
			// Outlook for Windows does not pass the font on from the outer table: it gets its own rule.
			. '<!--[if mso]><style>body,table,td,div,span,p{font-family:"Segoe UI",Arial,sans-serif !important;}</style><![endif]-->'
			. '</head>';
		$h .= '<body class="e-page" style="margin:0;padding:0;background:#f3f4f6;-webkit-text-size-adjust:100%;">';
		$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . esc_html( wp_strip_all_tags( $summary ) ) . '</div>';
		$h .= '<table role="presentation" class="e-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">';
		$h .= '<table role="presentation" class="e-card" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;' . $font . '">';

		// Header.
		$h .= '<tr><td height="4" bgcolor="#2563eb" style="height:4px;line-height:4px;font-size:4px;background:#2563eb;border-radius:10px 10px 0 0;">&nbsp;</td></tr>';
		$h .= '<tr><td class="e-pad" style="padding:24px 32px 4px;">';
		$h .= '<div class="e-muted" style="font-size:12px;line-height:16px;color:#6b7280;"><span style="letter-spacing:.08em;text-transform:uppercase;">' . esc_html( $site ) . '</span> · ' . esc_html( $address ) . '</div>';
		$h .= '<div class="e-h" style="font-size:24px;line-height:30px;font-weight:700;color:#111827;padding-top:4px;">' . esc_html__( 'Weekly consent report', 'smart-cookie-consent-manager' ) . '</div>';
		$h .= '<div class="e-muted" style="font-size:14px;line-height:20px;color:#6b7280;padding-top:2px;">' . esc_html( $period ) . '</div>';
		$h .= '</td></tr>';

		// In one sentence.
		$h .= '<tr><td class="e-pad" style="padding:16px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="e-box" bgcolor="#eff6ff" style="background:#eff6ff;border:1px solid #dbeafe;border-radius:8px;padding:14px 16px;">';
		$h .= '<div class="e-p" style="font-size:15px;line-height:22px;color:#1f2937;">' . wp_kses( $summary, array( 'strong' => array() ) ) . '</div>';
		$h .= '</td></tr></table></td></tr>';

		if ( $total > 0 ) {
			$h .= self::kpis( $this_w, $prev_w, $accept, $p_accept, $denied, $p_denied, $prev_txt );
			$h .= self::days_chart( $data['days'], $cal );
			$h .= self::share_bar( $this_w );
			$h .= self::categories( $data['categories'], $data['cat_prev'], $prev_txt );
		}
		$h .= self::weeks_chart( $data['weeks'] );
		$h .= self::status( $data );

		// Footer.
		$hour = wp_date( get_option( 'time_format' ), ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTime( (int) SCCM_Settings::get( 'alert_hour' ), 0 )->getTimestamp() );
		/* translators: 1: website address, 2: email address */
		$footer = sprintf( __( 'Sent by Smart Cookie Consent Manager on %1$s to %2$s.', 'smart-cookie-consent-manager' ), $address, $recipient );
		if ( $manual ) {
			/* translators: 1: date and time, 2: time of day */
			$footer .= ' ' . sprintf( __( 'Sent on request on %1$s. The regular report goes out every Monday at %2$s.', 'smart-cookie-consent-manager' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now->getTimestamp() ), $hour );
		} else {
			/* translators: %s: time of day */
			$footer .= ' ' . sprintf( __( 'This report is sent every Monday at %s.', 'smart-cookie-consent-manager' ), $hour );
		}
		$footer .= ' ' . __( 'The website\'s administrator can change this in WordPress under Compliance → Settings.', 'smart-cookie-consent-manager' );
		$note    = __( '"Choices" are answers to the cookie banner; a visitor who changes their mind later counts again. Visitors who closed the page without answering are not counted. Every choice is kept as proof under Compliance → Consent Records.', 'smart-cookie-consent-manager' );

		$h .= '<tr><td class="e-pad" style="padding:28px 32px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="e-line" style="border-top:1px solid #e5e7eb;padding-top:16px;">';
		$h .= '<div class="e-muted" style="font-size:12px;line-height:18px;color:#6b7280;">' . esc_html( $note ) . '</div>';
		$h .= '<div class="e-muted" style="font-size:12px;line-height:18px;color:#6b7280;padding-top:10px;">' . esc_html( $footer ) . '</div>';
		$h .= '</td></tr></table></td></tr>';
		$h .= '</table></td></tr></table></body></html>';
		// Every text gets the font itself: mail apps (Outlook) do not inherit it from the card.
		$h = str_replace( 'style="font-size:', 'style="' . $font . 'font-size:', $h );

		return array(
			'subject' => $subject,
			'html'    => $h,
			'text'    => self::text( $data, $site, $address, $period, wp_strip_all_tags( $summary ), $note . "\n" . $footer ),
		);
	}

	/**
	 * The sentence at the top ("444 visitors answered … 6 in 10 allowed all cookies …").
	 *
	 * @param array  $data     data().
	 * @param float  $accept   Allowed all, percent.
	 * @param float  $p_accept Allowed all the 7 days before, percent.
	 * @param string $prev_txt "last week" / "the 7 days before".
	 * @return string HTML with <strong> only.
	 */
	private static function summary( array $data, $accept, $p_accept, $prev_txt ) {
		$total = $data['this']['total'];
		$prev  = $data['prev']['total'];
		if ( ! $total ) {
			return $data['enabled']
				? esc_html__( 'Nobody answered the cookie banner in these 7 days.', 'smart-cookie-consent-manager' )
				: esc_html__( 'The cookie banner is switched off, so visitors were not asked.', 'smart-cookie-consent-manager' );
		}
		/* translators: %s: number of visitors */
		$out = sprintf( _n( '<strong>%s visitor</strong> answered the cookie banner', '<strong>%s visitors</strong> answered the cookie banner', $total, 'smart-cookie-consent-manager' ), number_format_i18n( $total ) );
		if ( $prev > 0 ) {
			$change = (int) round( self::pct( $total - $prev, $prev ) );
			if ( $change > 0 ) {
				/* translators: 1: percent, 2: "last week" */
				$out .= ', ' . sprintf( __( '<strong>%1$s%% more</strong> than %2$s', 'smart-cookie-consent-manager' ), number_format_i18n( $change ), esc_html( $prev_txt ) );
			} elseif ( $change < 0 ) {
				/* translators: 1: percent, 2: "last week" */
				$out .= ', ' . sprintf( __( '<strong>%1$s%% fewer</strong> than %2$s', 'smart-cookie-consent-manager' ), number_format_i18n( -$change ), esc_html( $prev_txt ) );
			} else {
				/* translators: %s: "last week" */
				$out .= ', ' . sprintf( __( 'about as many as %s', 'smart-cookie-consent-manager' ), esc_html( $prev_txt ) );
			}
		}
		/* translators: %s: number out of 10 */
		$out .= '. ' . sprintf( __( '<strong>%s in 10</strong> allowed all cookies', 'smart-cookie-consent-manager' ), number_format_i18n( (int) round( $accept / 10 ) ) );
		if ( $data['prev']['total'] > 0 ) {
			// Points: under 1 "about the same", under 5 "a little", otherwise "more" / "fewer".
			$diff = $accept - $p_accept;
			if ( abs( $diff ) < 1 ) {
				/* translators: %s: "last week" */
				$phrase = __( 'about the same as %s', 'smart-cookie-consent-manager' );
			} elseif ( abs( $diff ) < 5 ) {
				/* translators: %s: "last week" */
				$phrase = $diff > 0 ? __( 'a little more than %s', 'smart-cookie-consent-manager' ) : __( 'a little fewer than %s', 'smart-cookie-consent-manager' );
			} else {
				/* translators: %s: "last week" */
				$phrase = $diff > 0 ? __( 'more than %s', 'smart-cookie-consent-manager' ) : __( 'fewer than %s', 'smart-cookie-consent-manager' );
			}
			$out .= ', ' . sprintf( $phrase, esc_html( $prev_txt ) );
		}
		return $out . '.';
	}

	/**
	 * The two lines under a number, the same in every tile so the tiles line up: the change
	 * ("▲ 11%" green, "▼ 2 points" red, "No change") and the value before ("Last week: 401").
	 *
	 * @param float  $diff   Change.
	 * @param string $change Text after the arrow, e.g. "11%" or "2 points".
	 * @param string $before The value before, e.g. "Last week: 401".
	 * @return string HTML.
	 */
	private static function change( $diff, $change, $before ) {
		if ( 0 == $diff ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int or float.
			$line = '<div class="e-muted" style="font-size:13px;line-height:18px;color:#6b7280;font-weight:600;">' . esc_html__( 'No change', 'smart-cookie-consent-manager' ) . '</div>';
		} else {
			$up   = $diff > 0;
			$line = '<div class="' . ( $up ? 'e-up' : 'e-down' ) . '" style="font-size:13px;line-height:18px;color:' . ( $up ? '#15803d' : '#dc2626' ) . ';font-weight:700;">' . esc_html( ( $up ? '▲ ' : '▼ ' ) . $change ) . '</div>';
		}
		return $line . '<div class="e-muted" style="font-size:12px;line-height:16px;color:#6b7280;padding-top:2px;">' . esc_html( $before ) . '</div>';
	}

	/**
	 * Three number tiles, and who the rest are when the two shares do not add up to 100%.
	 *
	 * @param array  $w        This week.
	 * @param array  $p        The week before.
	 * @param float  $accept   Allowed all, percent.
	 * @param float  $p_accept The week before.
	 * @param float  $denied   Denied, percent.
	 * @param float  $p_denied The week before.
	 * @param string $prev_txt "last week".
	 * @return string HTML row.
	 */
	private static function kpis( array $w, array $p, $accept, $p_accept, $denied, $p_denied, $prev_txt ) {
		$label = ucfirst( $prev_txt );
		$tiles = array();
		if ( $p['total'] > 0 ) {
			$change = (int) round( self::pct( $w['total'] - $p['total'], $p['total'] ) );
			/* translators: 1: "Last week", 2: number */
			$sub = self::change( $change, number_format_i18n( abs( $change ) ) . '%', sprintf( __( '%1$s: %2$s', 'smart-cookie-consent-manager' ), $label, number_format_i18n( $p['total'] ) ) );
		} else {
			$sub = '<div class="e-muted" style="font-size:13px;line-height:18px;color:#6b7280;">' . esc_html__( 'No choices the week before', 'smart-cookie-consent-manager' ) . '</div>';
		}
		$tiles[] = array( __( 'Choices made', 'smart-cookie-consent-manager' ), number_format_i18n( $w['total'] ), $sub );
		foreach ( array( array( __( 'Allowed all cookies', 'smart-cookie-consent-manager' ), $accept, $p_accept ), array( __( 'Denied optional cookies', 'smart-cookie-consent-manager' ), $denied, $p_denied ) ) as $tile ) {
			$diff = (int) round( $tile[1] ) - (int) round( $tile[2] );
			/* translators: %s: number of points */
			$points = sprintf( _n( '%s point', '%s points', abs( $diff ), 'smart-cookie-consent-manager' ), number_format_i18n( abs( $diff ) ) );
			/* translators: 1: "Last week", 2: percent */
			$sub     = $p['total'] > 0 ? self::change( $diff, $points, sprintf( __( '%1$s: %2$s%%', 'smart-cookie-consent-manager' ), $label, number_format_i18n( round( $tile[2] ) ) ) ) : '';
			$tiles[] = array( $tile[0], number_format_i18n( round( $tile[1] ) ) . '%', $sub );
		}
		$h = '<tr><td class="e-pad" style="padding:16px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>';
		foreach ( $tiles as $i => $tile ) {
			$h .= ( $i ? '<td class="e-kgap" width="12" style="width:12px;font-size:0;line-height:0;">&nbsp;</td>' : '' )
				. '<td class="e-kpi e-box" valign="top" bgcolor="#f9fafb" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px 16px;">'
				. '<div class="e-muted" style="font-size:12px;line-height:16px;color:#6b7280;">' . esc_html( $tile[0] ) . '</div>'
				. '<div class="e-h" style="font-size:30px;line-height:38px;font-weight:700;color:#111827;padding:2px 0 4px;">' . esc_html( $tile[1] ) . '</div>'
				. $tile[2] . '</td>';
		}
		$h .= '</tr></table>';
		// The two shares do not add up to 100%: say who the rest are (as shown, rounded).
		$rest = 100 - (int) round( $accept ) - (int) round( $denied );
		$some = $w['counts']['custom'];
		$gpc  = $w['counts']['gpc'];
		if ( $rest > 0 && ( $some || $gpc ) ) {
			$parts = array();
			if ( $some ) {
				/* translators: %s: number of visitors */
				$parts[] = sprintf( _n( '%s visitor chose only some categories', '%s visitors chose only some categories', $some, 'smart-cookie-consent-manager' ), number_format_i18n( $some ) );
			}
			if ( $gpc ) {
				/* translators: %s: number of visitors */
				$parts[] = sprintf( _n( '%s browser sent a privacy signal (GPC)', '%s browsers sent a privacy signal (GPC)', $gpc, 'smart-cookie-consent-manager' ), number_format_i18n( $gpc ) );
			}
			/* translators: 1: percent, 2: who, e.g. "3 visitors chose only some categories" */
			$h .= '<div class="e-muted" style="font-size:12px;line-height:18px;color:#6b7280;padding-top:10px;">' . esc_html( sprintf( __( 'The other %1$s%%: %2$s.', 'smart-cookie-consent-manager' ), number_format_i18n( $rest ), implode( ' · ', $parts ) ) ) . '</div>';
		}
		return $h . '</td></tr>';
	}

	/**
	 * Section heading and line.
	 *
	 * @param string $title Title.
	 * @param string $text  Text (HTML allowed: <strong>).
	 * @return string HTML.
	 */
	private static function heading( $title, $text ) {
		return '<div class="e-h" style="font-size:16px;line-height:22px;font-weight:700;color:#111827;">' . esc_html( $title ) . '</div>'
			. '<div class="e-p" style="font-size:13px;line-height:19px;color:#4b5563;padding:2px 0 12px;">' . wp_kses( $text, array( 'strong' => array() ) ) . '</div>';
	}

	/**
	 * A small colour square for legends: a coloured "■" character, because Outlook ignores the
	 * size and background of an empty box (the legend then showed no colours).
	 *
	 * @param string $color Colour.
	 * @return string HTML.
	 */
	private static function swatch( $color ) {
		return '<span style="color:' . esc_attr( $color ) . ';font-size:15px;line-height:15px;">&#9632;</span>';
	}

	/**
	 * Labels of the choices.
	 *
	 * @param bool $long Longer text (the share list).
	 * @return array choice => label.
	 */
	private static function choice_labels( $long = false ) {
		return array(
			'accept_all' => __( 'Allowed all', 'smart-cookie-consent-manager' ),
			'custom'     => $long ? __( 'Chose some categories', 'smart-cookie-consent-manager' ) : __( 'Chose some', 'smart-cookie-consent-manager' ),
			'reject_all' => $long ? __( 'Denied optional cookies', 'smart-cookie-consent-manager' ) : __( 'Denied', 'smart-cookie-consent-manager' ),
			'gpc'        => $long ? __( 'Browser privacy signal (GPC)', 'smart-cookie-consent-manager' ) : __( 'Privacy signal (GPC)', 'smart-cookie-consent-manager' ),
		);
	}

	/**
	 * Day by day: one stacked column per day (bottom → top: allowed all, some, denied, GPC).
	 *
	 * @param array $days Y-m-d => counts (7 days).
	 * @param bool  $cal  The 7 days are a calendar week.
	 * @return string HTML row.
	 */
	private static function days_chart( array $days, $cal ) {
		$totals = array_map( 'array_sum', $days );
		$max    = max( 1, max( $totals ) );
		$best   = array_search( max( $totals ), $totals, true );
		$h      = '<tr><td class="e-pad" style="padding:24px 32px 0;">';
		/* translators: 1: weekday, 2: number */
		$h .= self::heading( $cal ? __( 'This week, day by day', 'smart-cookie-consent-manager' ) : __( 'Day by day', 'smart-cookie-consent-manager' ), __( 'How many visitors answered the banner each day, and what they chose.', 'smart-cookie-consent-manager' ) . ' ' . sprintf( __( 'Busiest day: <strong>%1$s (%2$s)</strong>.', 'smart-cookie-consent-manager' ), esc_html( wp_date( 'l', strtotime( $best . ' 12:00:00' ) ) ), number_format_i18n( $totals[ $best ] ) ) );
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>';
		foreach ( $days as $day => $counts ) {
			$top    = $day === $best;
			$ink    = $top ? 'e-h' : 'e-p';
			$weight = $top ? '700' : '600';
			$h     .= '<td align="center" valign="bottom" style="padding:0 4px;">';
			$h     .= '<div class="' . $ink . '" style="font-size:12px;line-height:16px;padding-bottom:4px;font-weight:' . $weight . ';color:' . ( $top ? '#111827' : '#374151' ) . ';">' . esc_html( number_format_i18n( $totals[ $day ] ) ) . '</div>';
			$cells  = array();
			foreach ( array( 'gpc', 'reject_all', 'custom', 'accept_all' ) as $choice ) {
				if ( $counts[ $choice ] > 0 ) {
					$cells[] = array( self::COLORS[ $choice ], max( 2, (int) round( 120 * $counts[ $choice ] / $max ) ) );
				}
			}
			if ( $cells ) {
				$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:44px;">';
				foreach ( $cells as $i => $cell ) {
					$radius = $i ? '' : 'border-radius:3px 3px 0 0;';
					$h     .= ( $i ? '<tr><td class="e-gap" height="2" style="height:2px;line-height:2px;font-size:0;background:#ffffff;">&nbsp;</td></tr>' : '' )
						. '<tr><td height="' . (int) $cell[1] . '" bgcolor="' . esc_attr( $cell[0] ) . '" style="height:' . (int) $cell[1] . 'px;line-height:' . (int) $cell[1] . 'px;font-size:0;background:' . esc_attr( $cell[0] ) . ';' . $radius . '">&nbsp;</td></tr>';
				}
				$h .= '</table>';
			}
			$h .= '<div class="' . ( $top ? 'e-h' : 'e-muted' ) . '" style="font-size:12px;line-height:16px;color:' . ( $top ? '#111827' : '#6b7280' ) . ';' . ( $top ? 'font-weight:700;' : '' ) . 'padding-top:6px;">' . esc_html( wp_date( 'D', strtotime( $day . ' 12:00:00' ) ) ) . '</div>';
			$h .= '</td>';
		}
		$h      .= '</tr></table>';
		$legend  = array();
		foreach ( self::choice_labels() as $choice => $label ) {
			$legend[] = self::swatch( self::COLORS[ $choice ] ) . '&nbsp;' . esc_html( $label );
		}
		$h .= '<div class="e-p" style="font-size:12px;line-height:18px;color:#4b5563;padding-top:12px;">' . implode( ' &nbsp;&nbsp; ', $legend ) . '</div>';
		return $h . '</td></tr>';
	}

	/**
	 * How visitors chose: one 100% bar and the counts.
	 *
	 * @param array $w This week.
	 * @return string HTML row.
	 */
	private static function share_bar( array $w ) {
		$h     = '<tr><td class="e-pad" style="padding:24px 32px 0;">' . self::heading( __( 'How visitors chose', 'smart-cookie-consent-manager' ), __( 'Every choice of these 7 days, as one bar.', 'smart-cookie-consent-manager' ) );
		$parts = array();
		foreach ( array( 'accept_all', 'custom', 'reject_all', 'gpc' ) as $choice ) {
			if ( $w['counts'][ $choice ] > 0 ) {
				$parts[] = array( $choice, max( 1, round( self::pct( $w['counts'][ $choice ], $w['total'] ), 1 ) ) );
			}
		}
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>';
		foreach ( $parts as $i => $part ) {
			$last   = count( $parts ) - 1 === $i;
			$radius = ( 0 === $i ? 'border-radius:4px 0 0 4px;' : '' ) . ( $last ? 'border-radius:' . ( 0 === $i ? '4px' : '0 4px 4px 0' ) . ';' : '' );
			$color  = self::COLORS[ $part[0] ];
			$h     .= ( $i ? '<td class="e-gap" width="2" style="width:2px;font-size:0;background:#ffffff;">&nbsp;</td>' : '' )
				. '<td width="' . esc_attr( $part[1] ) . '%" height="16" bgcolor="' . esc_attr( $color ) . '" style="height:16px;font-size:0;line-height:16px;background:' . esc_attr( $color ) . ';' . $radius . '">&nbsp;</td>';
		}
		$h .= '</tr></table><div style="height:10px;line-height:10px;font-size:0;">&nbsp;</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
		foreach ( self::choice_labels( true ) as $choice => $label ) {
			$n  = $w['counts'][ $choice ];
			$h .= '<tr><td class="e-p" style="font-size:13px;line-height:22px;color:#374151;">' . self::swatch( self::COLORS[ $choice ] ) . '&nbsp; ' . esc_html( $label ) . '</td>'
				. '<td class="e-h" align="right" style="font-size:13px;line-height:22px;color:#111827;font-weight:700;">' . esc_html( number_format_i18n( $n ) ) . ' &nbsp;<span class="e-muted" style="color:#6b7280;font-weight:400;">' . esc_html( self::share_text( $n, $w['total'] ) ) . '</span></td></tr>';
		}
		return $h . '</table></td></tr>';
	}

	/**
	 * What visitors allowed: one bar per optional category.
	 *
	 * @param array  $now      category_counts_between() of these 7 days.
	 * @param array  $prev     … of the 7 days before.
	 * @param string $prev_txt "last week".
	 * @return string HTML row.
	 */
	private static function categories( array $now, array $prev, $prev_txt ) {
		$hints = array(
			'functional' => __( 'remember settings, chat', 'smart-cookie-consent-manager' ),
			'analytics'  => __( 'visitor counts, e.g. Google Analytics', 'smart-cookie-consent-manager' ),
			'marketing'  => __( 'ads, social media, video, maps', 'smart-cookie-consent-manager' ),
		);
		$h = '<tr><td class="e-pad" style="padding:24px 32px 0;">' . self::heading( __( 'What visitors allowed', 'smart-cookie-consent-manager' ), __( 'Share of choices that allowed each kind of cookie. Necessary cookies are always on.', 'smart-cookie-consent-manager' ) );
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
		$i  = 0;
		foreach ( SCCM_Categories::all( true ) as $key => $category ) {
			if ( 'necessary' === $key ) {
				continue;
			}
			$share = (int) round( self::pct( $now[ $key ] ?? 0, $now['total'] ) );
			$was   = (int) round( self::pct( $prev[ $key ] ?? 0, $prev['total'] ) );
			$diff  = $share - $was;
			$mark  = '';
			if ( $prev['total'] > 0 && 0 !== $diff ) {
				$mark = ' <span class="' . ( $diff > 0 ? 'e-up' : 'e-down' ) . '" style="color:' . ( $diff > 0 ? '#15803d' : '#dc2626' ) . ';font-weight:600;">' . ( $diff > 0 ? '▲' : '▼' ) . esc_html( number_format_i18n( abs( $diff ) ) ) . '</span>';
			}
			$pad = $i++ ? '12px' : '6px';
			$h  .= '<tr><td class="e-p" style="font-size:13px;line-height:18px;color:#374151;padding:' . $pad . ' 0 4px;">' . esc_html( $category['label'] ) . ( isset( $hints[ $key ] ) ? ' <span class="e-muted" style="color:#6b7280;">(' . esc_html( $hints[ $key ] ) . ')</span>' : '' ) . '</td>'
				. '<td class="e-h" align="right" style="font-size:13px;line-height:18px;color:#111827;font-weight:700;padding:' . $pad . ' 0 4px;white-space:nowrap;">' . esc_html( number_format_i18n( $share ) ) . '%' . $mark . '</td></tr>';
			$h  .= '<tr><td colspan="2"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>'
				. ( $share > 0 ? '<td width="' . (int) $share . '%" height="10" bgcolor="#2563eb" style="height:10px;font-size:0;line-height:10px;background:#2563eb;border-radius:' . ( $share >= 100 ? '4px' : '4px 0 0 4px' ) . ';">&nbsp;</td>' : '' )
				. ( $share < 100 ? '<td class="e-track" height="10" bgcolor="#e5e7eb" style="height:10px;font-size:0;line-height:10px;background:#e5e7eb;border-radius:' . ( $share > 0 ? '0 4px 4px 0' : '4px' ) . ';">&nbsp;</td>' : '' )
				. '</tr></table></td></tr>';
		}
		$h .= '</table>';
		if ( $prev['total'] > 0 ) {
			/* translators: %s: "last week" */
			$h .= '<div class="e-muted" style="font-size:12px;line-height:16px;color:#6b7280;padding-top:8px;">' . esc_html( sprintf( __( '▲ / ▼ = points compared with %s.', 'smart-cookie-consent-manager' ), $prev_txt ) ) . '</div>';
		}
		return $h . '</td></tr>';
	}

	/**
	 * The last 8 weeks: one column per week, the newest highlighted, allowed-all share below.
	 *
	 * @param array $weeks data()['weeks'].
	 * @return string HTML row.
	 */
	private static function weeks_chart( array $weeks ) {
		$max   = max( 1, max( array_column( $weeks, 'total' ) ) );
		$all   = array_sum( array_column( $weeks, 'total' ) );
		$yes   = array_sum( array_map( static function ( $w ) {
			return $w['counts']['accept_all'];
		}, $weeks ) );
		$h     = '<tr><td class="e-pad" style="padding:24px 32px 0;">' . self::heading( __( 'The last 8 weeks', 'smart-cookie-consent-manager' ), __( 'Choices per week, and the share that allowed all cookies.', 'smart-cookie-consent-manager' ) );
		$h    .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;">';
		$last  = count( $weeks ) - 1;
		$rows  = array( '', '', '' );
		foreach ( $weeks as $i => $w ) {
			$now     = $last === $i;
			$color   = $now ? '#2563eb' : '#93c5fd';
			$height  = $w['total'] > 0 ? max( 2, (int) round( 90 * $w['total'] / $max ) ) : 0;
			$rows[0] .= '<td align="center" valign="bottom" style="padding:0 3px;"><div class="' . ( $now ? 'e-h' : 'e-muted' ) . '" style="font-size:11px;line-height:14px;padding-bottom:3px;color:' . ( $now ? '#111827;font-weight:700' : '#6b7280' ) . ';">' . esc_html( number_format_i18n( $w['total'] ) ) . '</div>'
				. ( $height ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:40px;"><tr><td height="' . $height . '" bgcolor="' . $color . '" style="height:' . $height . 'px;font-size:0;line-height:' . $height . 'px;background:' . $color . ';border-radius:3px 3px 0 0;">&nbsp;</td></tr></table>' : '' )
				. '</td>';
			$label    = $now ? __( 'Last 7 days', 'smart-cookie-consent-manager' ) : wp_date( 'j M', strtotime( $w['start'] . ' 12:00:00' ) );
			$rows[1] .= '<td align="center" class="' . ( $now ? 'e-h' : 'e-muted' ) . '" style="font-size:11px;line-height:14px;color:' . ( $now ? '#111827;font-weight:700' : '#6b7280' ) . ';padding-top:6px;">' . esc_html( $label ) . '</td>';
			$share    = $w['total'] > 0 ? number_format_i18n( round( self::pct( $w['counts']['accept_all'], $w['total'] ) ) ) . '%' : '–';
			$rows[2] .= '<td align="center" class="' . ( $now ? 'e-h' : 'e-p' ) . '" style="font-size:12px;line-height:16px;color:' . ( $now ? '#111827;font-weight:700' : '#374151' ) . ';padding-top:6px;">' . esc_html( $share ) . '</td>';
		}
		$h .= '<tr>' . implode( '</tr><tr>', $rows ) . '</tr></table>';
		/* translators: 1: number of choices, 2: percent */
		$h .= '<div class="e-muted" style="font-size:12px;line-height:16px;color:#6b7280;padding-top:8px;">' . wp_kses( sprintf( __( 'Bottom row: share that allowed all cookies. 8 weeks in total: <strong>%1$s choices</strong>, %2$s%% allowed all.', 'smart-cookie-consent-manager' ), number_format_i18n( $all ), number_format_i18n( round( self::pct( $yes, $all ) ) ) ), array( 'strong' => array() ) ) . '</div>';
		return $h . '</td></tr>';
	}

	/**
	 * Your cookie banner: status lines.
	 *
	 * @param array $data data().
	 * @return string HTML row.
	 */
	private static function status( array $data ) {
		$parts = array();
		foreach ( $data['list']['by_category'] as $label => $n ) {
			if ( $n > 0 ) {
				$parts[] = $label . ' ' . number_format_i18n( $n );
			}
		}
		if ( $data['added'] || $data['pending'] ) {
			/* translators: 1: number added, 2: number waiting for review */
			$changes = sprintf( __( '%1$s added · %2$s waiting for review', 'smart-cookie-consent-manager' ), number_format_i18n( $data['added'] ), number_format_i18n( $data['pending'] ) );
		} else {
			$changes = __( 'None', 'smart-cookie-consent-manager' );
		}
		$cm    = 'off' === $data['cm'] ? __( 'Off', 'smart-cookie-consent-manager' ) : __( 'On', 'smart-cookie-consent-manager' );
		$gpc   = $data['gpc'] ? __( 'Respected', 'smart-cookie-consent-manager' ) : __( 'Not used', 'smart-cookie-consent-manager' );
		$lines = array(
			array( __( 'Status', 'smart-cookie-consent-manager' ), $data['enabled'] ? '<span class="e-up" style="color:#15803d;">●</span> ' . esc_html__( 'Live on every page', 'smart-cookie-consent-manager' ) : '<span style="color:#b45309;">●</span> ' . esc_html__( 'Switched off', 'smart-cookie-consent-manager' ) ),
			array( __( 'Cookies listed', 'smart-cookie-consent-manager' ), esc_html( number_format_i18n( $data['list']['total'] ) ) . ( $parts ? ' <span class="e-muted" style="color:#6b7280;font-weight:400;">(' . esc_html( implode( ' · ', $parts ) ) . ')</span>' : '' ) ),
			array( __( 'Cookie changes in these 7 days', 'smart-cookie-consent-manager' ), esc_html( $changes ) ),
			array( __( 'Last cookie scan', 'smart-cookie-consent-manager' ), esc_html( $data['scan_time'] ? wp_date( get_option( 'date_format' ), $data['scan_time'] ) : __( 'Not yet', 'smart-cookie-consent-manager' ) ) ),
			array( __( 'Google Consent Mode · Privacy signal', 'smart-cookie-consent-manager' ), esc_html( $cm . ' · ' . $gpc ) ),
			/* translators: %s: date */
			array( __( 'Banner version', 'smart-cookie-consent-manager' ), esc_html( number_format_i18n( $data['version'] ) ) . ( $data['since'] ? ' <span class="e-muted" style="color:#6b7280;font-weight:400;">(' . esc_html( sprintf( __( 'since %s', 'smart-cookie-consent-manager' ), wp_date( get_option( 'date_format' ), $data['since'] ) ) ) . ')</span>' : '' ) ),
		);
		$h = '<tr><td class="e-pad" style="padding:24px 32px 0;"><div class="e-h" style="font-size:16px;line-height:22px;font-weight:700;color:#111827;">' . esc_html__( 'Your cookie banner', 'smart-cookie-consent-manager' ) . '</div>';
		$h .= '<div style="height:10px;line-height:10px;font-size:0;">&nbsp;</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="e-box" style="border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">';
		foreach ( $lines as $i => $line ) {
			$border = $i ? 'border-top:1px solid #e5e7eb;' : '';
			$h     .= '<tr><td class="e-p e-line" style="font-size:13px;line-height:18px;color:#4b5563;padding:10px 14px;' . $border . '">' . esc_html( $line[0] ) . '</td>'
				. '<td class="e-h e-line" align="right" style="font-size:13px;line-height:18px;color:#111827;font-weight:600;padding:10px 14px;' . $border . '">' . $line[1] . '</td></tr>';
		}
		return $h . '</table></td></tr>';
	}

	/**
	 * Plain-text version.
	 *
	 * @param array  $data    data().
	 * @param string $site    Site name.
	 * @param string $address Site address.
	 * @param string $period  Dates.
	 * @param string $summary Sentence.
	 * @param string $footer  Footer.
	 * @return string
	 */
	private static function text( array $data, $site, $address, $period, $summary, $footer ) {
		$w = $data['this'];
		$t = $site . ' (' . $address . ') — ' . __( 'Weekly consent report', 'smart-cookie-consent-manager' ) . "\n" . $period . "\n" . str_repeat( '=', 40 ) . "\n\n" . $summary . "\n\n";
		if ( $w['total'] > 0 ) {
			$t .= __( 'HOW VISITORS CHOSE', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( self::choice_labels( true ) as $choice => $label ) {
				$t .= '  - ' . $label . ': ' . number_format_i18n( $w['counts'][ $choice ] ) . ' (' . self::share_text( $w['counts'][ $choice ], $w['total'] ) . ")\n";
			}
			$t .= "\n" . __( 'DAY BY DAY', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( $data['days'] as $day => $counts ) {
				$t .= '  - ' . wp_date( 'D j M', strtotime( $day . ' 12:00:00' ) ) . ': ' . number_format_i18n( array_sum( $counts ) ) . "\n";
			}
			$t .= "\n" . __( 'WHAT VISITORS ALLOWED', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( SCCM_Categories::all( true ) as $key => $category ) {
				if ( 'necessary' !== $key ) {
					$t .= '  - ' . $category['label'] . ': ' . number_format_i18n( round( self::pct( $data['categories'][ $key ] ?? 0, $data['categories']['total'] ) ) ) . "%\n";
				}
			}
		}
		$t .= "\n" . __( 'THE LAST 8 WEEKS', 'smart-cookie-consent-manager' ) . "\n";
		foreach ( $data['weeks'] as $week ) {
			$t .= '  - ' . wp_date( 'j M', strtotime( $week['start'] . ' 12:00:00' ) ) . ': ' . number_format_i18n( $week['total'] ) . "\n";
		}
		return $t . "\n--\n" . $footer . "\n";
	}
}
