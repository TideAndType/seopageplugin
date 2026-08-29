<?php
/**
 * Lightweight test bootstrap.
 *
 * Stubs the small set of WordPress functions/constants used by the units under
 * test so pure logic can be tested without a full WordPress install. This is NOT
 * a substitute for WP integration tests (documented in tests/README.md) — it
 * covers the deterministic, dependency-light logic only.
 *
 * @package SEO_Command_Center
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// --- Minimal WP function stubs --------------------------------------------
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		$str = trim( wp_strip_all_tags( $str ) );
		return $str;
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str, $remove_breaks = false ) {
		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
		$str = strip_tags( $str );
		if ( $remove_breaks ) {
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}
		return trim( $str );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : $url;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( $title, '-' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

// --- Classes under test (pure-logic subset) -------------------------------
require_once __DIR__ . '/../seo-command-center/includes/security/class-scc-security.php';
require_once __DIR__ . '/../seo-command-center/includes/ai/class-scc-ai-response.php';
require_once __DIR__ . '/../seo-command-center/includes/analysis/class-scc-crawler.php';
require_once __DIR__ . '/../seo-command-center/includes/analysis/class-scc-seo-meta.php';

// SCC_Logger references SCC_DB; stub SCC_DB + SCC_Security already loaded.
// We test only the redact() logic via reflection, so provide a no-op SCC_DB.
if ( ! class_exists( 'SCC_DB' ) ) {
	class SCC_DB {
		public static $rows = array();
		public static function table( $n ) {
			return 'wp_scc_' . $n;
		}
		public static function insert( $t, $d, $f = array() ) {
			self::$rows[] = $d;
			return 1;
		}
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-01-01 00:00:00';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
require_once __DIR__ . '/../seo-command-center/includes/logging/class-scc-logger.php';

// --- Phase 2 stubs + classes under test -----------------------------------
if ( ! class_exists( 'SCC_Analyzer' ) ) {
	// Minimal stub: architecture/cannibalization call ::latest().
	class SCC_Analyzer {
		public static $latest = null;
		public static function latest() {
			return self::$latest;
		}
	}
}
if ( ! class_exists( 'SCC_Settings' ) ) {
	class SCC_Settings {
		public static function get( $key, $default = null ) {
			return $default;
		}
	}
}
require_once __DIR__ . '/../seo-command-center/includes/strategy/class-scc-architecture.php';
require_once __DIR__ . '/../seo-command-center/includes/strategy/class-scc-cannibalization.php';
require_once __DIR__ . '/../seo-command-center/includes/strategy/class-scc-content-plan.php';

// --- Phase 3 stubs + classes under test -----------------------------------
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
require_once __DIR__ . '/../seo-command-center/includes/analysis/class-scc-seo-meta.php';
require_once __DIR__ . '/../seo-command-center/includes/generation/class-scc-schema.php';
require_once __DIR__ . '/../seo-command-center/includes/generation/class-scc-quality-score.php';
