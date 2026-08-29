<?php
/**
 * Settings storage + sanitizers. Non-secret settings live in scc_settings;
 * credentials live in scc_credentials (autoload=no, never sent to JS).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings manager.
 */
class SCC_Settings {

	/**
	 * Get all non-secret settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$settings = get_option( 'scc_settings', array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), SCC_Activator::default_settings() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize and persist a settings payload (partial update allowed).
	 *
	 * @param array $input Raw input.
	 * @return array The stored settings.
	 */
	public static function update( array $input ) {
		$current = self::all();

		$map = array(
			'default_provider'         => 'provider',
			'fallback_provider'        => 'provider_or_empty',
			'claude_model'             => 'text',
			'openai_model'             => 'text',
			'default_word_count'       => 'int',
			'max_internal_links'       => 'int',
			'autopilot_enabled'        => 'bool',
			'autopilot_auto_insert'    => 'bool',
			'link_high_confidence'     => 'int',
			'link_medium_confidence'   => 'int',
			'link_max_per_destination' => 'int',
			'link_avoid_headings'      => 'bool',
			'link_vary_anchor'         => 'bool',
			'meta_storage'             => 'text',
			'draft_by_default'         => 'bool',
			'auto_publish'             => 'bool',
			'monthly_budget'           => 'float',
			'max_pages_per_batch'      => 'int',
			'max_articles_per_batch'   => 'int',
			'remove_data_on_uninstall' => 'bool',
		);

		foreach ( $map as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			switch ( $type ) {
				case 'provider':
					$value = in_array( $value, array( 'claude', 'openai' ), true ) ? $value : 'claude';
					break;
				case 'provider_or_empty':
					$value = in_array( $value, array( 'claude', 'openai', '' ), true ) ? $value : '';
					break;
				case 'int':
					$value = SCC_Security::sanitize_int( $value, 0, 100000 );
					break;
				case 'float':
					$value = SCC_Security::sanitize_float( $value, 0, 1000000 );
					break;
				case 'bool':
					$value = SCC_Security::sanitize_bool( $value );
					break;
				default:
					$value = SCC_Security::sanitize_text( $value );
			}
			$current[ $key ] = $value;
		}

		update_option( 'scc_settings', $current );
		return $current;
	}

	/**
	 * Update credentials (API keys). Empty string means "leave unchanged" so a
	 * masked field is never wiped by an unedited save.
	 *
	 * @param array $input Raw input (claude_key, openai_key, ...).
	 * @return void
	 */
	public static function update_credentials( array $input ) {
		$creds = get_option( 'scc_credentials', array() );
		if ( ! is_array( $creds ) ) {
			$creds = array();
		}

		$fields = array( 'claude_key', 'openai_key', 'dataforseo_login', 'dataforseo_key', 'gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token' );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = SCC_Security::sanitize_key_value( $input[ $field ] );
			if ( '' === $value ) {
				// Explicit clear sentinel.
				if ( isset( $input[ $field . '_clear' ] ) && SCC_Security::sanitize_bool( $input[ $field . '_clear' ] ) ) {
					unset( $creds[ $field ] );
				}
				continue; // Leave unchanged on empty.
			}
			$creds[ $field ] = $value;
		}

		update_option( 'scc_credentials', $creds, false ); // autoload = no.
	}

	/**
	 * Masked view of credentials for the UI. Never returns raw keys.
	 *
	 * @return array field => ['configured'=>bool,'hint'=>string]
	 */
	public static function credential_hints() {
		$creds  = get_option( 'scc_credentials', array() );
		$fields = array( 'claude_key', 'openai_key', 'dataforseo_login', 'dataforseo_key', 'gsc_client_id', 'gsc_client_secret', 'gsc_refresh_token' );
		$out    = array();
		foreach ( $fields as $field ) {
			$value = isset( $creds[ $field ] ) ? (string) $creds[ $field ] : '';
			$out[ $field ] = array(
				'configured' => '' !== $value,
				'hint'       => SCC_Security::mask( $value ),
			);
		}
		return $out;
	}
}
