<?php
/**
 * Server-side blocker: rewrites tags of non-necessary services into inert placeholders.
 *
 *   <script src="…gtm.js">     → <script type="text/plain" data-sccm-category="analytics" data-sccm-src="…">
 *   <iframe src="…youtube…">   → <iframe data-sccm-src="…" data-sccm-category="marketing">
 *   <link href="…fonts…">      → <link data-sccm-href="…" data-sccm-category="functional">
 *
 * The browser (sccm-frontend.js) releases them when the category is granted.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML output rewriting.
 */
class SCCM_Blocker {

	/**
	 * Compiled rules for this request.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
	}

	/**
	 * Start output buffering for front-end HTML pages.
	 */
	public static function start_buffer() {
		if ( ! SCCM_Settings::get( 'blocker_enabled' ) || ! SCCM_Frontend::is_active() ) {
			return;
		}
		/**
		 * Disable the HTML blocker for a request.
		 *
		 * @param bool $enabled Whether to rewrite this page.
		 */
		if ( ! apply_filters( 'sccm_blocker_enabled', true ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'process' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 100 || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		$result = self::rewrite( $html, self::rules() );
		return null === $result ? $html : $result;
	}

	/**
	 * Rewrite HTML with the given rules. Public for tests.
	 *
	 * @param string $html  HTML.
	 * @param array  $rules List of array( pattern, category ).
	 * @return string|null Null on regex failure.
	 */
	public static function rewrite( $html, array $rules ) {
		if ( ! $rules ) {
			return $html;
		}

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			function ( $m ) use ( $rules ) {
				return SCCM_Blocker::rewrite_script( $m[0], $m[1], $m[2], $rules );
			},
			$html
		);
		if ( null === $html ) {
			return null;
		}

		$html = preg_replace_callback(
			'#<iframe\b([^>]*)>#i',
			function ( $m ) use ( $rules ) {
				return SCCM_Blocker::rewrite_iframe( $m[0], $m[1], $rules );
			},
			$html
		);
		if ( null === $html ) {
			return null;
		}

		return preg_replace_callback(
			'#<link\b([^>]*)>#i',
			function ( $m ) use ( $rules ) {
				return SCCM_Blocker::rewrite_link( $m[0], $m[1], $rules );
			},
			$html
		);
	}

	/**
	 * Rewrite one <script> tag.
	 *
	 * @param string $full  Full tag.
	 * @param string $attrs Attribute string.
	 * @param string $code  Inline code.
	 * @param array  $rules Rules.
	 * @return string
	 */
	public static function rewrite_script( $full, $attrs, $code, array $rules ) {
		if ( preg_match( '/\bdata-sccm-(skip|category)\b/i', $attrs ) ) {
			return $full;
		}
		$type = self::attr( $attrs, 'type' );
		if ( null !== $type && ! self::is_js_type( $type ) ) {
			return $full;
		}
		$src      = self::attr( $attrs, 'src' );
		$haystack = null !== $src ? $src : $code;
		if ( '' === trim( (string) $haystack ) ) {
			return $full;
		}
		$category = self::match( $haystack, $rules );
		if ( ! $category ) {
			return $full;
		}

		$new = self::remove_attr( $attrs, 'type' );
		$new = self::remove_attr( $new, 'src' );
		$add = ' type="text/plain" data-sccm-category="' . esc_attr( $category ) . '"';
		if ( null !== $type && '' !== trim( $type ) && 'text/javascript' !== strtolower( trim( $type ) ) ) {
			$add .= ' data-sccm-type="' . esc_attr( $type ) . '"';
		}
		if ( null !== $src ) {
			$add .= ' data-sccm-src="' . esc_attr( $src ) . '"';
		}
		return '<script' . rtrim( $new ) . $add . '>' . $code . '</script>';
	}

	/**
	 * Rewrite one <iframe> opening tag.
	 *
	 * @param string $full  Full tag.
	 * @param string $attrs Attributes.
	 * @param array  $rules Rules.
	 * @return string
	 */
	public static function rewrite_iframe( $full, $attrs, array $rules ) {
		if ( preg_match( '/\bdata-sccm-(skip|category)\b/i', $attrs ) ) {
			return $full;
		}
		$src      = self::attr( $attrs, 'src' );
		$data_src = self::attr( $attrs, 'data-src' );
		$category = null;
		if ( null !== $src && '' !== $src ) {
			$category = self::match( $src, $rules );
		}
		if ( ! $category && null !== $data_src ) {
			$category = self::match( $data_src, $rules );
		}
		if ( ! $category ) {
			return $full;
		}
		$new = self::remove_attr( $attrs, 'src' );
		$new = self::remove_attr( $new, 'data-src' );
		$add = ' data-sccm-category="' . esc_attr( $category ) . '"';
		if ( null !== $src ) {
			$add .= ' data-sccm-src="' . esc_attr( $src ) . '"';
		}
		if ( null !== $data_src ) {
			$add .= ' data-sccm-datasrc="' . esc_attr( $data_src ) . '"';
		}
		$self_closing = ( '/' === substr( rtrim( $new ), -1 ) );
		if ( $self_closing ) {
			$new = substr( rtrim( $new ), 0, -1 );
		}
		return '<iframe' . rtrim( $new ) . $add . ( $self_closing ? ' /' : '' ) . '>';
	}

	/**
	 * Rewrite one <link> tag (stylesheets, preconnect, dns-prefetch, preload).
	 *
	 * @param string $full  Full tag.
	 * @param string $attrs Attributes.
	 * @param array  $rules Rules.
	 * @return string
	 */
	public static function rewrite_link( $full, $attrs, array $rules ) {
		if ( preg_match( '/\bdata-sccm-(skip|category)\b/i', $attrs ) ) {
			return $full;
		}
		$rel = strtolower( (string) self::attr( $attrs, 'rel' ) );
		if ( ! preg_match( '/\b(stylesheet|preconnect|dns-prefetch|preload|prefetch|modulepreload)\b/', $rel ) ) {
			return $full;
		}
		$href = self::attr( $attrs, 'href' );
		if ( null === $href || '' === $href ) {
			return $full;
		}
		$category = self::match( $href, $rules );
		if ( ! $category ) {
			return $full;
		}
		$new          = self::remove_attr( $attrs, 'href' );
		$self_closing = ( '/' === substr( rtrim( $new ), -1 ) );
		if ( $self_closing ) {
			$new = substr( rtrim( $new ), 0, -1 );
		}
		return '<link' . rtrim( $new ) . ' data-sccm-category="' . esc_attr( $category ) . '" data-sccm-href="' . esc_attr( $href ) . '"' . ( $self_closing ? ' /' : '' ) . '>';
	}

	/**
	 * Active rules for this request (lower-cased patterns).
	 *
	 * @return array
	 */
	public static function rules() {
		if ( null === self::$rules ) {
			self::$rules = array();
			foreach ( SCCM_Services::rules() as $rule ) {
				if ( empty( $rule['pattern'] ) || ! SCCM_Categories::is_optional( $rule['category'] ) ) {
					continue;
				}
				self::$rules[] = array(
					'pattern'  => $rule['pattern'],
					'category' => $rule['category'],
				);
			}
		}
		return self::$rules;
	}

	/**
	 * First matching rule's category.
	 *
	 * @param string $haystack URL or code.
	 * @param array  $rules    Rules.
	 * @return string|null
	 */
	public static function match( $haystack, array $rules ) {
		$haystack = html_entity_decode( (string) $haystack, ENT_QUOTES );
		foreach ( $rules as $rule ) {
			if ( false !== stripos( $haystack, $rule['pattern'] ) ) {
				return $rule['category'];
			}
		}
		return null;
	}

	/**
	 * Whether a script type attribute means JavaScript.
	 *
	 * @param string $type Type attribute.
	 * @return bool
	 */
	private static function is_js_type( $type ) {
		$type = strtolower( trim( $type ) );
		return in_array( $type, array( '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' ), true );
	}

	/**
	 * Read an attribute value from an attribute string.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $name  Attribute name.
	 * @return string|null Null when absent.
	 */
	public static function attr( $attrs, $name ) {
		$pattern = '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
		if ( preg_match( $pattern, $attrs, $m ) ) {
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				return $m[3];
			}
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				return $m[2];
			}
			return isset( $m[1] ) ? $m[1] : '';
		}
		if ( preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '(?=\s|$|\/)/i', $attrs ) ) {
			return '';
		}
		return null;
	}

	/**
	 * Remove an attribute from an attribute string.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $name  Attribute name.
	 * @return string
	 */
	private static function remove_attr( $attrs, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=[\s\/>]|$)/i';
		return (string) preg_replace( $pattern, '', ' ' . $attrs );
	}
}
