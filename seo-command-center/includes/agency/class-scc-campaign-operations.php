<?php
/**
 * TideOrbit SEO Campaign Operations: per-site agency plan, deliverables,
 * evidence ledger and measured Search Console position snapshots.
 *
 * GBP, GA4 and AI visibility remain explicit external/manual evidence sources
 * until authorized integrations exist. Never represent them as connected.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Campaign_Operations {
	const CONFIG = 'scc_campaign_config';
	const TASKS = 'scc_campaign_tasks';
	const METRICS = 'scc_campaign_metrics';
	const GSC = 'scc_campaign_gsc_history';
	const GSC_CHECK = 'scc_campaign_gsc_checked';
	const SERP = 'scc_campaign_serp_history';
	const CITATIONS = 'scc_campaign_citation_inventory';
	const OUTREACH = 'scc_campaign_outreach_inventory';
	const NS = 'seo-command/v1';

	public static function plans() {
		return array(
			'local' => array( 'label' => 'Local SEO', 'markets' => 1, 'themes' => 10, 'assets' => 1 ),
			'growth' => array( 'label' => 'SEO Growth', 'markets' => 3, 'themes' => 25, 'assets' => 3 ),
			'authority' => array( 'label' => 'SEO Authority', 'markets' => 5, 'themes' => 50, 'assets' => 4 ),
		);
	}

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 25 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ), 25 );
		add_action( 'scc_run_jobs', array( __CLASS__, 'weekly_maintenance' ), 25 );
		add_action( 'admin_init', array( 'SCC_GA4', 'maybe_handle_callback' ), 15 );
		add_action( 'admin_init', array( 'SCC_GBP_Performance', 'maybe_handle_callback' ), 16 );
	}

	public static function admin_menu() {
		add_submenu_page(
			'seo-command-center',
			__( 'SEO Campaign', 'seo-command-center' ),
			__( 'SEO Campaign', 'seo-command-center' ),
			SCC_Security::capability(),
			'seo-command-center-campaign',
			array( __CLASS__, 'admin_page' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'seo-command-center-campaign' ) ) { return; }
		wp_enqueue_script(
			'scc-campaign',
			SCC_PLUGIN_URL . 'assets/js/campaign.js',
			array( 'wp-api-fetch' ),
			SCC_VERSION,
			true
		);
		wp_localize_script( 'scc-campaign', 'sccCampaign', array(
			'api' => '/' . self::NS,
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public static function admin_page() {
		if ( ! current_user_can( SCC_Security::capability() ) ) { wp_die( 'Not authorized.' ); }
		$file = SCC_PLUGIN_DIR . 'includes/admin/views/campaign.php';
		if ( file_exists( $file ) ) { include $file; }
	}

	public static function routes() {
		$perm = array( 'SCC_Security', 'rest_permission' );
		foreach ( array(
			'/campaign' => array( 'GET', 'state' ),
			'/campaign/config' => array( 'POST', 'rest_config' ),
			'/campaign/task' => array( 'POST', 'rest_task' ),
			'/campaign/metric' => array( 'POST', 'rest_metric' ),
			'/campaign/gsc-refresh' => array( 'POST', 'rest_gsc_refresh' ),
			'/campaign/ga4/property' => array( 'POST', 'rest_ga4_property' ),
			'/campaign/ga4/auth-url' => array( 'POST', 'rest_ga4_auth_url' ),
			'/campaign/ga4/refresh' => array( 'POST', 'rest_ga4_refresh' ),
			'/campaign/bing/connect' => array( 'POST', 'rest_bing_connect' ),
			'/campaign/bing/refresh' => array( 'POST', 'rest_bing_refresh' ),
			'/campaign/bing/disconnect' => array( 'POST', 'rest_bing_disconnect' ),
			'/campaign/clarity/connect' => array( 'POST', 'rest_clarity_connect' ),
			'/campaign/clarity/refresh' => array( 'POST', 'rest_clarity_refresh' ),
			'/campaign/clarity/disconnect' => array( 'POST', 'rest_clarity_disconnect' ),
			'/campaign/ga4/disconnect' => array( 'POST', 'rest_ga4_disconnect' ),
			'/campaign/gbp/location' => array( 'POST', 'rest_gbp_location' ),
			'/campaign/gbp/auth-url' => array( 'POST', 'rest_gbp_auth_url' ),
			'/campaign/gbp/refresh' => array( 'POST', 'rest_gbp_refresh' ),
			'/campaign/gbp/disconnect' => array( 'POST', 'rest_gbp_disconnect' ),
			'/campaign/ghl/connect' => array( 'POST', 'rest_ghl_connect' ),
			'/campaign/ghl/refresh' => array( 'POST', 'rest_ghl_refresh' ),
			'/campaign/ghl/disconnect' => array( 'POST', 'rest_ghl_disconnect' ),
			'/campaign/serp-ranks' => array( 'POST', 'rest_serp_ranks' ),
			'/campaign/citation' => array( 'POST', 'rest_citation' ),
			'/campaign/outreach/sync' => array( 'POST', 'rest_outreach_sync' ),
			'/campaign/outreach' => array( 'POST', 'rest_outreach' ),
			'/campaign/report' => array( 'GET', 'rest_report' ),
			'/campaign/public-citations' => array( 'POST', 'rest_citation_settings' ),
		) as $route => $mapping ) {
			register_rest_route( self::NS, $route, array(
				'methods' => $mapping[0],
				'callback' => array( __CLASS__, $mapping[1] ),
				'permission_callback' => $perm,
			) );
		}
	}

	protected static function input( $request ) {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : (array) $request->get_params();
	}

	protected static function saved( $name ) {
		$rows = get_option( $name, array() );
		return is_array( $rows ) ? $rows : array();
	}

	protected static function save( $name, array $data ) {
		// Campaign data is only needed on this screen, cron and reporting;
		// keeping it out of WordPress autoload avoids cost on normal pageviews.
		if ( false === get_option( $name, false ) ) {
			return add_option( $name, $data, '', 'no' );
		}
		return update_option( $name, $data, false );
	}

	public static function config() {
		return self::saved( self::CONFIG );
	}

	protected static function values( $raw, $max ) {
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/[\r\n,]+/', (string) $raw );
		}
		$clean = array();
		foreach ( $raw as $v ) {
			$v = trim( sanitize_text_field( (string) $v ) );
			if ( '' !== $v && ! in_array( $v, $clean, true ) ) { $clean[] = substr( $v, 0, 180 ); }
		}
		return array_slice( $clean, 0, $max );
	}

	public static function normalize_config( array $raw ) {
		$plans = self::plans();
		$tier = sanitize_key( (string) ( $raw['plan'] ?? 'local' ) );
		if ( ! isset( $plans[ $tier ] ) ) { $tier = 'local'; }
		$plan = $plans[ $tier ];
		return array(
			'name' => substr( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ), 0, 180 ),
			'plan' => $tier,
			'markets' => self::values( $raw['markets'] ?? array(), $plan['markets'] ),
			'themes' => self::values( $raw['themes'] ?? array(), $plan['themes'] ),
			'competitors' => self::values( $raw['competitors'] ?? array(), 10 ),
			'assets' => max( $plan['assets'], min( 30, (int) ( $raw['assets'] ?? $plan['assets'] ) ) ),
			'goal' => substr( sanitize_textarea_field( (string) ( $raw['goal'] ?? '' ) ), 0, 500 ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function configure( array $raw ) {
		$config = self::normalize_config( $raw );
		if ( '' === $config['name'] ) {
			return new WP_Error( 'scc_campaign_name', __( 'Enter the client or campaign name.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		self::save( self::CONFIG, $config );
		self::seed_citation_inventory();
		self::seed_month( gmdate( 'Y-m' ) );
		return $config;
	}

	public static function tasks() { return self::saved( self::TASKS ); }
	public static function metrics() { return self::saved( self::METRICS ); }
	public static function history() { return self::saved( self::GSC ); }

	public static function task_templates( array $config ) {
		$plan = self::plans()[ $config['plan'] ] ?? self::plans()['local'];
		$out = array();
		for ( $i = 1; $i <= (int) ( $config['assets'] ?? $plan['assets'] ); $i++ ) {
			$out[] = array( 'content', 'Content asset ' . $i );
		}
		$out[] = array( 'technical', 'Technical SEO and Core Web Vitals review' );
		$out[] = array( 'rank', 'Review search performance and keyword movement' );
		$out[] = array( 'gbp', 'Google Business Profile optimization review' );
		$out[] = array( 'citation', 'Citation accuracy and listing review' );
		if ( 'local' !== $config['plan'] ) {
			$out[] = array( 'competitor', 'Competitor opportunity review' );
			$out[] = array( 'conversions', 'Review organic lead and conversion performance' );
			$out[] = array( 'aeo', 'AI citation-readiness and visibility review' );
		}
		if ( 'authority' === $config['plan'] ) {
			$out[] = array( 'backlinks', 'Digital PR and authority acquisition activity' );
			$out[] = array( 'cro', 'Conversion optimization experiment' );
		}
		return $out;
	}

	public static function seed_month( $month ) {
		$config = self::config();
		if ( empty( $config['name'] ) || ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { return 0; }
		$tasks = self::tasks();
		$added = 0;
		foreach ( self::task_templates( $config ) as $i => $template ) {
			$key = $month . ':' . $template[0] . ':' . $i;
			if ( isset( $tasks[ $key ] ) ) { continue; }
			$tasks[ $key ] = array(
				'id' => $key, 'month' => $month, 'type' => $template[0],
				'title' => $template[1], 'status' => 'todo',
				'notes' => '', 'evidence_url' => '', 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			$added++;
		}
		if ( $added ) {
			// One year of monthly tasks at most, preserve recent history.
			uasort( $tasks, function ( $a, $b ) { return strcmp( (string) $b['month'], (string) $a['month'] ); } );
			self::save( self::TASKS, array_slice( $tasks, 0, 600, true ) );
		}
		return $added;
	}

	public static function update_task( array $raw ) {
		$tasks = self::tasks();
		$id = (string) ( $raw['id'] ?? '' );
		if ( ! isset( $tasks[ $id ] ) ) {
			return new WP_Error( 'scc_campaign_task', 'Task not found.', array( 'status' => 404 ) );
		}
		$status = sanitize_key( (string) ( $raw['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'todo', 'in_progress', 'review', 'done', 'blocked' ), true ) ) {
			return new WP_Error( 'scc_campaign_status', 'Invalid task status.', array( 'status' => 400 ) );
		}
		$tasks[ $id ]['status'] = $status;
		$tasks[ $id ]['notes'] = substr( sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ), 0, 1000 );
		$tasks[ $id ]['evidence_url'] = esc_url_raw( (string) ( $raw['evidence_url'] ?? '' ) );
		$tasks[ $id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		self::save( self::TASKS, $tasks );
		return $tasks[ $id ];
	}

	public static function add_metric( array $raw ) {
		$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
		$allowed = array( 'organic_leads', 'organic_sessions', 'calls', 'appointments', 'revenue', 'ga4_conversions', 'gbp_calls', 'gbp_views', 'ai_citations', 'maps_visibility', 'organic_position', 'backlinks_earned' );
		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error( 'scc_campaign_metric', 'Choose a supported metric type.', array( 'status' => 400 ) );
		}
		if ( ! isset( $raw['value'] ) || ! is_numeric( $raw['value'] ) || ! is_finite( (float) $raw['value'] ) || (float) $raw['value'] < 0 ) {
			return new WP_Error( 'scc_campaign_metric_value', 'Enter a valid nonnegative measurement.', array( 'status' => 400 ) );
		}
		$month = (string) ( $raw['month'] ?? gmdate( 'Y-m' ) );
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', $month ) ) {
			return new WP_Error( 'scc_campaign_month', 'Invalid reporting month.', array( 'status' => 400 ) );
		}
		$rows = self::metrics();
		$entry = array(
			'id' => wp_generate_uuid4(),
			'month' => $month,
			'type' => $type,
			'value' => round( (float) $raw['value'], 2 ),
			'market' => substr( sanitize_text_field( (string) ( $raw['market'] ?? '' ) ), 0, 150 ),
			'note' => substr( sanitize_text_field( (string) ( $raw['note'] ?? '' ) ), 0, 400 ),
			'evidence_url' => esc_url_raw( (string) ( $raw['evidence_url'] ?? '' ) ),
			'provenance' => 'manual_entry',
			'verified' => false, // Submitted evidence is not independently validated.
			'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$rows[] = $entry;
		self::save( self::METRICS, array_slice( $rows, -500 ) );
		return $entry;
	}

	/**
	 * A private per-site directory inventory. Discovery itself does not prove a
	 * listing exists, is claimed or is correct. Every transition is manual.
	 */
	public static function seed_citation_inventory() {
		if ( ! class_exists( 'SCC_Citation_Scanner' ) ) { return 0; }
		$rows = self::saved( self::CITATIONS );
		$created = 0;
		foreach ( SCC_Citation_Scanner::directories() as $directory ) {
			$name = sanitize_text_field( (string) ( $directory['name'] ?? '' ) );
			$domain = strtolower( sanitize_text_field( (string) ( $directory['domain'] ?? '' ) ) );
			if ( '' === $domain ) { continue; }
			$id = md5( $domain );
			if ( isset( $rows[ $id ] ) ) { continue; }
			$rows[ $id ] = array(
				'id' => $id, 'name' => $name, 'domain' => $domain,
				'status' => 'unverified', 'notes' => '', 'evidence_url' => '',
				'updated_at' => '', 'provenance' => 'manual_workflow',
			);
			$created++;
		}
		if ( $created ) { self::save( self::CITATIONS, array_slice( $rows, 0, 120, true ) ); }
		return $created;
	}
	public static function citations() { return self::saved( self::CITATIONS ); }

	public static function update_citation( array $raw ) {
		$id = (string) ( $raw['id'] ?? '' );
		$rows = self::citations();
		if ( ! isset( $rows[ $id ] ) ) { return new WP_Error( 'scc_citation_unknown', 'Unknown directory.', array( 'status' => 404 ) ); }
		$status = sanitize_key( (string) ( $raw['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'unverified', 'missing', 'needs_correction', 'claimed', 'submitted', 'verified', 'not_applicable' ), true ) ) {
			return new WP_Error( 'scc_citation_bad_status', 'Invalid citation status.', array( 'status' => 400 ) );
		}
		$rows[ $id ]['status'] = $status;
		$rows[ $id ]['notes'] = substr( sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ), 0, 600 );
		$rows[ $id ]['evidence_url'] = esc_url_raw( (string) ( $raw['evidence_url'] ?? '' ) );
		$rows[ $id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		self::save( self::CITATIONS, $rows );
		return $rows[ $id ];
	}

	/**
	 * Explicit import only: opportunity analysis never sends outbound mail.
	 */
	public static function sync_outreach() {
		$last = class_exists( 'SCC_Backlink_Gap' ) ? SCC_Backlink_Gap::last() : null;
		if ( ! is_array( $last ) || empty( $last['opportunities'] ) ) {
			return new WP_Error( 'scc_outreach_no_scan', 'Run Backlink Gap in Growth Lab first.', array( 'status' => 400 ) );
		}
		$rows = self::saved( self::OUTREACH );
		$created = 0;
		foreach ( array_slice( $last['opportunities'], 0, 80 ) as $item ) {
			$domain = strtolower( sanitize_text_field( (string) ( $item['domain'] ?? '' ) ) );
			if ( '' === $domain ) { continue; }
			$id = md5( $domain );
			if ( isset( $rows[ $id ] ) ) { continue; }
			$rows[ $id ] = array(
				'id' => $id, 'domain' => $domain,
				'type' => sanitize_key( (string) ( $item['type'] ?? 'website' ) ),
				'priority' => sanitize_key( (string) ( $item['priority'] ?? 'medium' ) ),
				'suggestion' => substr( sanitize_text_field( (string) ( $item['recommended_action'] ?? '' ) ), 0, 350 ),
				'status' => 'identified', 'notes' => '', 'evidence_url' => '',
				'updated_at' => '', 'provenance' => 'backlink_gap_opportunity',
			);
			$created++;
		}
		if ( $created ) { self::save( self::OUTREACH, array_slice( $rows, 0, 200, true ) ); }
		return array( 'imported' => $created, 'total' => count( $rows ) );
	}
	public static function outreach() { return self::saved( self::OUTREACH ); }
	public static function update_outreach( array $raw ) {
		$id = (string) ( $raw['id'] ?? '' );
		$rows = self::outreach();
		if ( ! isset( $rows[ $id ] ) ) { return new WP_Error( 'scc_outreach_unknown', 'Unknown outreach target.', array( 'status' => 404 ) ); }
		$status = sanitize_key( (string) ( $raw['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'identified', 'qualified', 'pitched', 'responded', 'earned', 'declined', 'not_relevant' ), true ) ) {
			return new WP_Error( 'scc_outreach_status', 'Invalid outreach status.', array( 'status' => 400 ) );
		}
		$rows[ $id ]['status'] = $status;
		$rows[ $id ]['notes'] = substr( sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ), 0, 600 );
		$rows[ $id ]['evidence_url'] = esc_url_raw( (string) ( $raw['evidence_url'] ?? '' ) );
		$rows[ $id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		self::save( self::OUTREACH, $rows );
		return $rows[ $id ];
	}

	public static function public_citation_settings( array $raw ) {
		$enabled = ! empty( $raw['enabled'] );
		$paid = $enabled && ! empty( $raw['paid'] );
		update_option( 'scc_citation_public_enabled', $enabled, false );
		update_option( 'scc_citation_public_paid_enabled', $paid, false );
		return array( 'enabled' => $enabled, 'paid' => $paid, 'paid_daily_limit' => 10 );
	}

	public static function refresh_gsc( $force = false ) {
		$config = self::config();
		if ( empty( $config['name'] ) || empty( $config['themes'] ) ) { return new WP_Error( 'scc_campaign_unconfigured', 'Configure search themes before refreshing.', array( 'status' => 400 ) ); }
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) { return new WP_Error( 'scc_campaign_gsc', 'Connect Google Search Console first.', array( 'status' => 400 ) ); }
		$checked = (int) get_option( self::GSC_CHECK, 0 );
		if ( ! $force && $checked > time() - 7 * DAY_IN_SECONDS ) { return array( 'state' => 'cached', 'checked_at' => gmdate( 'c', $checked ) ); }
		// Search Console reports aggregated observed search impressions, not exact
		// positional SERP rankings. End yesterday for less incomplete data.
		$rows = SCC_GSC::query_range( '', array( 'query' ), gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 25000 );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$map = array();
		foreach ( $rows as $row ) {
			$query = strtolower( trim( (string) ( $row['keys'][0] ?? '' ) ) );
			if ( '' !== $query ) { $map[ $query ] = $row; }
		}
		$terms = array();
		foreach ( $config['themes'] as $theme ) {
			$key = strtolower( trim( $theme ) );
			$row = $map[ $key ] ?? null;
			$terms[] = array(
				'theme' => $theme,
				'state' => $row ? 'measured' : 'unavailable',
				'position' => $row ? round( (float) ( $row['position'] ?? 0 ), 2 ) : null,
				'clicks' => $row ? (int) ( $row['clicks'] ?? 0 ) : null,
				'impressions' => $row ? (int) ( $row['impressions'] ?? 0 ) : null,
			);
		}
		$history = self::history();
		$snapshot = array( 'captured_at' => gmdate( 'c' ), 'window_days' => 28, 'source' => 'Google Search Console', 'measurement' => 'average_observed_position', 'terms' => $terms );
		$history[] = $snapshot;
		self::save( self::GSC, array_slice( $history, -52 ) );
		update_option( self::GSC_CHECK, time(), false );
		return $snapshot;
	}

	/**
	 * Optional PAID, admin-triggered SERP scan; never run automatically.
	 * Requires explicit click/confirmation, max five targets per call and 20
	 * per day. WordPress option quotas are best-effort concurrency protection;
	 * maintain a hard budget at the DataForSEO account level as well.
	 */
	public static function scan_serp_ranks( $market_index, $start, $confirmed ) {
		if ( ! $confirmed ) { return new WP_Error( 'scc_serp_consent', 'Confirm paid SERP scanning before continuing.', array( 'status' => 400 ) ); }
		$config = self::config();
		$market_index = max( 0, (int) $market_index );
		$start = max( 0, (int) $start );
		if ( empty( $config['markets'][ $market_index ] ) || empty( $config['themes'] ) ) {
			return new WP_Error( 'scc_serp_config', 'Configure the target market and search themes first.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'SCC_DataForSEO' ) || ! SCC_DataForSEO::is_connected() ) {
			return new WP_Error( 'scc_serp_provider', 'Connect DataForSEO before running paid organic SERP checks.', array( 'status' => 400 ) );
		}
		$themes = array_slice( $config['themes'], $start, 5 );
		if ( empty( $themes ) ) { return new WP_Error( 'scc_serp_empty', 'No themes remain at this offset.', array( 'status' => 400 ) ); }
		$day_key = 'scc_campaign_serp_paid_' . gmdate( 'Ymd' );
		$used = (int) get_option( $day_key, 0 );
		if ( $used + count( $themes ) > 20 ) { return new WP_Error( 'scc_serp_daily_limit', 'Daily limit of 20 paid organic requests reached.', array( 'status' => 429 ) ); }
		$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$market = $config['markets'][ $market_index ];
		$results = array();
		foreach ( $themes as $theme ) {
			// Reserve a request before the network call; even a failed paid task
			// may incur provider charges.
			$used++;
			update_option( $day_key, $used, false );
			$measured = SCC_DataForSEO::domain_position( $theme, $market, $host );
			if ( is_wp_error( $measured ) ) {
				$results[] = array( 'keyword' => $theme, 'location' => $market, 'state' => 'unavailable', 'rank' => null, 'error' => $measured->get_error_message() );
			} else {
				$results[] = $measured;
			}
		}
		$snap = array( 'captured_at' => gmdate( 'c' ), 'market' => $market, 'offset' => $start, 'domain' => $host, 'provider' => 'DataForSEO', 'paid_requests_reserved_today' => $used, 'results' => $results );
		$history = self::saved( self::SERP );
		$history[] = $snap;
		self::save( self::SERP, array_slice( $history, -80 ) );
		return $snap;
	}

	public static function weekly_maintenance() {
		$config = self::config();
		if ( empty( $config['name'] ) ) { return; }
		self::seed_month( gmdate( 'Y-m' ) );
		$result = self::refresh_gsc( false );
		if ( class_exists( 'SCC_GA4' ) ) { SCC_GA4::maybe_capture(); }
		if ( class_exists( 'SCC_Bing_Webmaster' ) ) { SCC_Bing_Webmaster::maybe_capture(); }
		if ( class_exists( 'SCC_Clarity' ) ) { SCC_Clarity::maybe_capture(); }
		if ( class_exists( 'SCC_GBP_Performance' ) ) { SCC_GBP_Performance::maybe_capture(); }
		if ( class_exists( 'SCC_GHL_Attribution' ) ) { SCC_GHL_Attribution::maybe_capture(); }
		if ( is_wp_error( $result ) && class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'campaign', 'GSC snapshot not refreshed: ' . $result->get_error_message() );
		}
	}

	public static function report( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { $month = gmdate( 'Y-m' ); }
		$tasks = array_values( array_filter( self::tasks(), function ( $t ) use ( $month ) { return ( $t['month'] ?? '' ) === $month; } ) );
		$metrics = array_values( array_filter( self::metrics(), function ( $m ) use ( $month ) { return ( $m['month'] ?? '' ) === $month; } ) );
		$done = count( array_filter( $tasks, function ( $t ) { return 'done' === $t['status']; } ) );
		$history = array_values( array_filter( self::history(), function ( $h ) use ( $month ) { return substr( (string) ( $h['captured_at'] ?? '' ), 0, 7 ) === $month; } ) );
		$serp = array_values( array_filter( self::saved( self::SERP ), function ( $h ) use ( $month ) { return substr( (string) ( $h['captured_at'] ?? '' ), 0, 7 ) === $month; } ) );
		return array(
			'month' => $month,
			'campaign' => self::config(),
			'deliverables' => array( 'completed' => $done, 'total' => count( $tasks ), 'tasks' => $tasks ),
			'measurements' => $metrics,
			'search_console' => $history ? end( $history ) : null,
			'google_analytics' => class_exists( 'SCC_GA4' ) ? SCC_GA4::monthly( $month ) : null,
			'gbp_performance' => class_exists( 'SCC_GBP_Performance' ) ? SCC_GBP_Performance::monthly( $month ) : null,
			'ghl_opportunities' => class_exists( 'SCC_GHL_Attribution' ) ? SCC_GHL_Attribution::monthly( $month ) : null,
			'bing_webmaster' => class_exists( 'SCC_Bing_Webmaster' ) ? SCC_Bing_Webmaster::monthly( $month ) : null,
			'clarity_insights' => class_exists( 'SCC_Clarity' ) ? SCC_Clarity::monthly( $month ) : array(),
			'organically_measured_serps' => array_slice( $serp, -10 ),
			'connections' => self::connections(),
			'citations' => array_values( self::citations() ),
			'outreach' => array_values( self::outreach() ),
			'disclaimer' => 'GBP clicks are interactions, not confirmed calls or leads. HighLevel opportunity sources are CRM labels, not proven SEO attribution; won value is not collected revenue. GA4 key events are not necessarily leads. GSC and Bing use separate search metrics. Manual values remain unverified.' ,
		);
	}

	public static function connections() {
		return array(
			'gsc' => class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() ? 'connected' : 'disconnected',
			'gbp' => class_exists( 'SCC_GBP_Performance' ) ? SCC_GBP_Performance::state()['status'] : 'not_available',
			'ga4' => class_exists( 'SCC_GA4' ) ? SCC_GA4::status() : 'not_available',
			'bing' => class_exists( 'SCC_Bing_Webmaster' ) ? SCC_Bing_Webmaster::status() : 'not_available',
			'clarity' => class_exists( 'SCC_Clarity' ) ? SCC_Clarity::state()['status'] : 'not_available',
			'crm' => class_exists( 'SCC_GHL_Attribution' ) ? SCC_GHL_Attribution::state()['status'] : 'not_available',
			'ai_citations' => 'not_connected',
			'paid_citation_public' => (bool) get_option( 'scc_citation_public_paid_enabled', false ),
		);
	}

	public static function state() {
		return rest_ensure_response( array(
			'plans' => self::plans(),
			'campaign' => self::config(),
			'report' => self::report( gmdate( 'Y-m' ) ),
			'history' => self::history(),
			'ga4' => class_exists( 'SCC_GA4' ) ? SCC_GA4::state() : array( 'status' => 'not_available' ),
			'gbp' => class_exists( 'SCC_GBP_Performance' ) ? SCC_GBP_Performance::state() : array( 'status' => 'not_available' ),
			'ghl' => class_exists( 'SCC_GHL_Attribution' ) ? SCC_GHL_Attribution::state() : array( 'status' => 'not_available' ),
			'bing' => class_exists( 'SCC_Bing_Webmaster' ) ? SCC_Bing_Webmaster::state() : array( 'status' => 'not_available' ),
			'clarity' => class_exists( 'SCC_Clarity' ) ? SCC_Clarity::state() : array( 'status' => 'not_available' ),
			'public_citations' => array(
				'enabled' => (bool) get_option( 'scc_citation_public_enabled', false ),
				'paid' => (bool) get_option( 'scc_citation_public_paid_enabled', false ),
			),
		) );
	}

	public static function rest_config( $request ) {
		$result = self::configure( self::input( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_task( $request ) {
		$result = self::update_task( self::input( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_metric( $request ) {
		$result = self::add_metric( self::input( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_gsc_refresh() {
		$result = self::refresh_gsc( true );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_citation( $request ) {
		$result = self::update_citation( self::input( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_outreach_sync() {
		$result = self::sync_outreach();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_outreach( $request ) {
		$result = self::update_outreach( self::input( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_serp_ranks( $request ) {
		$p = self::input( $request );
		$result = self::scan_serp_ranks( $p['market_index'] ?? 0, $p['start'] ?? 0, ! empty( $p['confirm_paid'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function ga4_admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'scc_ga4_forbidden', 'Only administrators can configure or disconnect Analytics.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_ga4_property( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$raw = self::input( $request );
		$result = SCC_GA4::set_property( $raw['property_id'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_ga4_auth_url() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$url = SCC_GA4::auth_url();
		return is_wp_error( $url ) ? $url : rest_ensure_response( array( 'url' => $url ) );
	}

	public static function rest_ga4_refresh( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$raw = self::input( $request );
		$month = sanitize_text_field( (string) ( $raw['month'] ?? gmdate( 'Y-m' ) ) );
		$result = SCC_GA4::capture_month( $month );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_ga4_disconnect() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return rest_ensure_response( SCC_GA4::disconnect() );
	}


	public static function rest_bing_connect( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_Bing_Webmaster::configure( $p['api_key'] ?? '', $p['site_url'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_bing_refresh( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$month = sanitize_text_field( (string) ( $p['month'] ?? gmdate( 'Y-m' ) ) );
		$result = SCC_Bing_Webmaster::capture( $month );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_bing_disconnect() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return rest_ensure_response( SCC_Bing_Webmaster::disconnect() );
	}
	public static function rest_clarity_connect( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_Clarity::configure( $p['api_token'] ?? '', $p['project_id'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_clarity_refresh() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$result = SCC_Clarity::capture();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_clarity_disconnect() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return rest_ensure_response( SCC_Clarity::disconnect() );
	}


	public static function rest_gbp_location( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_GBP_Performance::configure( $p['location_id'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_gbp_auth_url() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$result = SCC_GBP_Performance::auth_url();
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'url' => $result ) );
	}
	public static function rest_gbp_refresh( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_GBP_Performance::capture( sanitize_text_field( (string) ( $p['month'] ?? gmdate( 'Y-m' ) ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_gbp_disconnect() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return rest_ensure_response( SCC_GBP_Performance::disconnect() );
	}
	public static function rest_ghl_connect( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_GHL_Attribution::configure( $p['token'] ?? '', $p['location_id'] ?? '', $p['pipeline_id'] ?? '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_ghl_refresh( $request ) {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$p = self::input( $request );
		$result = SCC_GHL_Attribution::capture( sanitize_text_field( (string) ( $p['month'] ?? gmdate( 'Y-m' ) ) ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
	public static function rest_ghl_disconnect() {
		$allowed = self::ga4_admin_permission();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return rest_ensure_response( SCC_GHL_Attribution::disconnect() );
	}
	public static function rest_report( $request ) {
		$month = sanitize_text_field( (string) $request->get_param( 'month' ) );
		return rest_ensure_response( self::report( $month ) );
	}
	public static function rest_citation_settings( $request ) {
		return rest_ensure_response( self::public_citation_settings( self::input( $request ) ) );
	}
}
