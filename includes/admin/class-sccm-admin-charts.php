<?php
/**
 * Small charts for the admin Dashboard, drawn as inline SVG on the server: no chart library, no
 * script, nothing loaded from elsewhere. Every chart has a text alternative for screen readers.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * SVG charts.
 */
class SCCM_Admin_Charts {

	/**
	 * A stacked bar per day.
	 *
	 * @param array  $days   Y-m-d => array( series key => count ), oldest first.
	 * @param array  $series Series key => array( label, colour ), bottom of the stack first.
	 * @param string $title  Text alternative.
	 * @return string SVG.
	 */
	public static function stacked_days( array $days, array $series, $title ) {
		$width  = 640;
		$height = 160;
		$max    = 1;
		foreach ( $days as $counts ) {
			$max = max( $max, array_sum( array_intersect_key( $counts, $series ) ) );
		}
		$max  = self::nice_max( $max );
		$n    = max( 1, count( $days ) );
		$slot = $width / $n;
		$bar  = max( 2, $slot * 0.7 );
		$area = $height;

		// Dates under the chart (first, middle and last day), as HTML so they keep their size.
		$days_shown = array();

		$svg  = '<svg class="sccm-chart sccm-chart--days" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $title ) . '">';
		$svg .= '<title>' . esc_html( $title ) . '</title>';
		// Grid: the top value and the middle.
		foreach ( array( 1, 0.5 ) as $share ) {
			$y    = round( $area - $area * $share, 1 );
			$svg .= '<line class="sccm-chart__grid" x1="0" x2="' . $width . '" y1="' . $y . '" y2="' . $y . '"/>';
		}
		$i = 0;
		foreach ( $days as $day => $counts ) {
			$x     = round( $i * $slot + ( $slot - $bar ) / 2, 1 );
			$y     = $area;
			$parts = array();
			foreach ( $series as $key => $info ) {
				$value = (int) ( $counts[ $key ] ?? 0 );
				if ( ! $value ) {
					continue;
				}
				$h      = $area * $value / $max;
				$y     -= $h;
				$svg   .= '<rect x="' . $x . '" y="' . round( $y, 1 ) . '" width="' . round( $bar, 1 ) . '" height="' . round( $h, 1 ) . '" fill="' . esc_attr( $info[1] ) . '"/>';
				$parts[] = $info[0] . ' ' . $value;
			}
			// One invisible column per day with its numbers as a tooltip.
			$label = wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00:00' ) ) . ( $parts ? ': ' . implode( ', ', $parts ) : ': 0' );
			$svg  .= '<rect class="sccm-chart__hit" x="' . round( $i * $slot, 1 ) . '" y="0" width="' . round( $slot, 1 ) . '" height="' . $area . '"><title>' . esc_html( $label ) . '</title></rect>';
			if ( 0 === $i || $n - 1 === $i || (int) floor( $n / 2 ) === $i ) {
				$days_shown[] = wp_date( 'j M', strtotime( $day . ' 12:00:00' ) );
			}
			++$i;
		}
		$svg .= '</svg><div class="sccm-chart__dates" aria-hidden="true"><span>' . implode( '</span><span>', array_map( 'esc_html', $days_shown ) ) . '</span></div>';
		return '<div class="sccm-chart-wrap"><span class="sccm-chart__max">' . (int) $max . '</span>' . $svg . '</div>';
	}

	/**
	 * A donut with a number in the middle.
	 *
	 * @param array  $segments List of array( label, value, colour ).
	 * @param string $center   Big text in the middle.
	 * @param string $caption  Small text under it.
	 * @param string $title    Text alternative.
	 * @return string SVG.
	 */
	public static function donut( array $segments, $center, $caption, $title ) {
		$total = 0;
		foreach ( $segments as $segment ) {
			$total += max( 0, (int) $segment[1] );
		}
		$r      = 42;
		$length = 2 * M_PI * $r;
		$svg    = '<svg class="sccm-chart sccm-chart--donut" viewBox="0 0 120 120" role="img" aria-label="' . esc_attr( $title ) . '"><title>' . esc_html( $title ) . '</title>';
		$svg   .= '<circle class="sccm-chart__track" cx="60" cy="60" r="' . $r . '" fill="none" stroke-width="16"/>';
		if ( $total ) {
			$offset = 0;
			foreach ( $segments as $segment ) {
				$value = max( 0, (int) $segment[1] );
				if ( ! $value ) {
					continue;
				}
				$dash   = $length * $value / $total;
				$svg   .= '<circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="' . esc_attr( $segment[2] ) . '" stroke-width="16" stroke-dasharray="' . round( $dash, 2 ) . ' ' . round( $length - $dash, 2 ) . '" stroke-dashoffset="' . round( -$offset, 2 ) . '" transform="rotate(-90 60 60)"><title>' . esc_html( $segment[0] . ': ' . $value ) . '</title></circle>';
				$offset += $dash;
			}
		}
		$svg .= '<text class="sccm-chart__center" x="60" y="60" text-anchor="middle" dominant-baseline="central">' . esc_html( $center ) . '</text>';
		$svg .= '<text class="sccm-chart__caption" x="60" y="80" text-anchor="middle">' . esc_html( $caption ) . '</text>';
		return $svg . '</svg>';
	}

	/**
	 * Horizontal bars with a percentage each (no SVG: plain HTML that wraps on small screens).
	 *
	 * @param array $rows List of array( label, value, total, colour ).
	 * @return string HTML.
	 */
	public static function percent_bars( array $rows ) {
		$html = '<ul class="sccm-pbars">';
		foreach ( $rows as $row ) {
			$share = $row[2] ? (int) round( 100 * $row[1] / $row[2] ) : 0;
			$html .= '<li><span class="sccm-pbars__label">' . esc_html( $row[0] ) . '</span>'
				. '<span class="sccm-pbars__track"><span class="sccm-pbars__fill" style="width:' . $share . '%;background:' . esc_attr( $row[3] ) . '"></span></span>'
				. '<span class="sccm-pbars__value"><strong>' . $share . '%</strong> <small>(' . (int) $row[1] . ')</small></span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * A round top value for the chart scale (1, 2, 5, 10, 20, 50 …).
	 *
	 * @param int $value Largest value.
	 * @return int
	 */
	private static function nice_max( $value ) {
		$value = max( 1, (int) $value );
		$base  = (int) pow( 10, (int) floor( log10( $value ) ) );
		foreach ( array( 1, 2, 5, 10 ) as $step ) {
			if ( $value <= $step * $base ) {
				return $step * $base;
			}
		}
		return 10 * $base;
	}
}
