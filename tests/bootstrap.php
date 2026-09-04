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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
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
$GLOBALS['scc_test_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['scc_test_filters'][ $tag ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $tag ) {
		unset( $GLOBALS['scc_test_filters'][ $tag ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = array_slice( func_get_args(), 1 );
		if ( ! empty( $GLOBALS['scc_test_filters'][ $tag ] ) ) {
			foreach ( $GLOBALS['scc_test_filters'][ $tag ] as $cb ) {
				$args[0] = call_user_func_array( $cb, $args );
			}
			return $args[0];
		}
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
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

// --- Classes under test (pure-logic subset) -------------------------------
require_once __DIR__ . '/../seo-command-center/includes/security/class-scc-security.php';
require_once __DIR__ . '/../seo-command-center/includes/net/class-scc-url.php';
require_once __DIR__ . '/../seo-command-center/includes/net/class-scc-robots.php';
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
// Minimal $wpdb stub so logging (secret-redacting) doesn't fatal in tests.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	class SCC_Test_WPDB {
		public $prefix = 'wp_';
		public $insert_id = 1;
		public function __call( $name, $args ) {
			return 0;
		}
		public function get_var( $q = '' ) {
			return 0;
		}
		public function get_row( $q = '', $o = null ) {
			return null;
		}
		public function get_results( $q = '', $o = null ) {
			return array();
		}
		public function prepare( $q ) {
			return $q;
		}
		public function insert( $t, $d, $f = null ) {
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new SCC_Test_WPDB();
}
require_once __DIR__ . '/../seo-command-center/includes/logging/class-scc-logger.php';

// AI providers (pure config/normalization logic is unit-tested; HTTP is not).
require_once __DIR__ . '/../seo-command-center/includes/ai/class-scc-ai-response.php';
require_once __DIR__ . '/../seo-command-center/includes/ai/interface-scc-ai-provider.php';
require_once __DIR__ . '/../seo-command-center/includes/ai/class-scc-gemini-provider.php';
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' );
	}
}
require_once __DIR__ . '/../seo-command-center/includes/ai/class-scc-lmstudio-provider.php';

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
require_once __DIR__ . '/../seo-command-center/includes/generation/class-scc-metadata.php';
require_once __DIR__ . '/../seo-command-center/includes/generation/class-scc-content-ideas.php';

// --- Phase 4 stubs + classes under test -----------------------------------
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = trim( (string) $url );
		return preg_match( '#^(https?:)?//#i', $url ) || 0 === strpos( $url, '/' ) ? $url : '';
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		// Minimal test stub: drop script/style/iframe blocks, keep other markup.
		return preg_replace( '#<\s*(script|style|iframe)[^>]*>.*?<\s*/\s*\1\s*>#is', '', (string) $html );
	}
}
require_once __DIR__ . '/../seo-command-center/includes/elementor/class-scc-placeholders.php';
require_once __DIR__ . '/../seo-command-center/includes/template/class-scc-template-variables.php';
// SCC_Elementor_Builder::build_replacements is pure; the file references
// SCC_Elementor only inside build_tree/apply_to_post, not at load time.
require_once __DIR__ . '/../seo-command-center/includes/elementor/class-scc-elementor-builder.php';

// --- Phase 5 classes under test -------------------------------------------
// Only the protected DOM helpers are exercised (via reflection); apply() and
// its WP dependencies are covered by integration tests.
require_once __DIR__ . '/../seo-command-center/includes/links/class-scc-link-inserter.php';

// --- Phase 6 stubs + classes under test -----------------------------------
$GLOBALS['scc_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['scc_test_options'] ) ? $GLOBALS['scc_test_options'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['scc_test_options'][ $key ] = $value;
		return true;
	}
}
require_once __DIR__ . '/../seo-command-center/includes/integrations/class-scc-gsc.php';
require_once __DIR__ . '/../seo-command-center/includes/integrations/class-scc-dataforseo.php';
require_once __DIR__ . '/../seo-command-center/includes/integrations/class-scc-competitor-analysis.php';

// --- Phase 7 stubs + classes under test -----------------------------------
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook ) {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $id = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		return is_array( $args ) && ! empty( $args['ID'] ) ? (int) $args['ID'] : 1;
	}
}
require_once __DIR__ . '/../seo-command-center/includes/jobs/class-scc-jobs.php';
require_once __DIR__ . '/../seo-command-center/includes/publishing/class-scc-publishing.php';

// --- Advanced linking / meta / schema stubs + classes under test ----------
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
	}
}
require_once __DIR__ . '/../seo-command-center/includes/index/class-scc-content-index.php';
require_once __DIR__ . '/../seo-command-center/includes/index/class-scc-change-history.php';
require_once __DIR__ . '/../seo-command-center/includes/links/class-scc-anchor-engine.php';
require_once __DIR__ . '/../seo-command-center/includes/links/class-scc-link-engine.php';

// --- CMS-agnostic template/renderer stubs + classes under test ------------
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $html, $allowed = array() ) {
		return (string) $html;
	}
}
if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
	function wp_kses_allowed_html( $ctx = 'post' ) {
		return array();
	}
}
if ( ! class_exists( 'SCC_Elementor' ) ) {
	// Elementor is treated as absent in the unit environment.
	class SCC_Elementor {
		public static $active = false;
		public static function is_active() {
			return self::$active;
		}
		public static function get_data( $id ) {
			return null;
		}
		public static function is_elementor_post( $id ) {
			return false;
		}
	}
}
require_once __DIR__ . '/../seo-command-center/includes/template/class-scc-content-object.php';
require_once __DIR__ . '/../seo-command-center/includes/template/class-scc-template.php';
require_once __DIR__ . '/../seo-command-center/includes/template/class-scc-template-map.php';
require_once __DIR__ . '/../seo-command-center/includes/template/class-scc-template-selector.php';
require_once __DIR__ . '/../seo-command-center/includes/render/interface-scc-renderer.php';
require_once __DIR__ . '/../seo-command-center/includes/render/class-scc-wordpress-renderer.php';
require_once __DIR__ . '/../seo-command-center/includes/render/class-scc-gutenberg-renderer.php';
require_once __DIR__ . '/../seo-command-center/includes/render/class-scc-elementor-renderer.php';
require_once __DIR__ . '/../seo-command-center/includes/render/class-scc-renderer-manager.php';

// --- Intelligence layer (Opportunity Engine) ------------------------------
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { return true; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
}
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-content-decay.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-intent-drift.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-opportunity-engine.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-action-queue.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-page-optimizer.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-health-timeline.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-experiments.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-entity-graph.php';
require_once __DIR__ . '/../seo-command-center/includes/intelligence/class-scc-ai-visibility.php';

// --- Generator (native vs template mode: pure static helpers under test) ----
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) { return 'Test Site'; }
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $t ) { return $t; }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $n ) { return preg_replace( '/[^a-z0-9_.-]/i', '', (string) $n ); }
}
// Category resolver stub: a small fake taxonomy for resolve_existing_category().
$GLOBALS['scc_test_categories'] = array( 'seo' => 11, 'local seo' => 12 );
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		if ( 'category' !== $taxonomy ) {
			return false;
		}
		$map = $GLOBALS['scc_test_categories'];
		$key = ( 'slug' === $field ) ? sanitize_title( $value ) : strtolower( (string) $value );
		// Slugs in the fake map: 'seo' and 'local-seo'.
		$slugs = array( 'seo' => 11, 'local-seo' => 12 );
		if ( 'slug' === $field && isset( $slugs[ $key ] ) ) {
			return (object) array( 'term_id' => $slugs[ $key ], 'name' => $value );
		}
		if ( 'name' === $field && isset( $map[ $key ] ) ) {
			return (object) array( 'term_id' => $map[ $key ], 'name' => $value );
		}
		return false;
	}
}
require_once __DIR__ . '/../seo-command-center/includes/generation/class-scc-generator.php';
