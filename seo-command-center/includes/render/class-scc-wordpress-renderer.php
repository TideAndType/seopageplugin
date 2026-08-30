<?php
/**
 * Native WordPress renderer — classic semantic HTML.
 *
 * Produces standard post_content that works in the Classic Editor and any
 * theme, and remains valid WordPress content if the plugin is removed.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress renderer.
 */
class SCC_WordPress_Renderer implements SCC_Renderer_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'wordpress';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Native WordPress (Classic)', 'seo-command-center' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available( $content_type = '' ) {
		return true; // Always works.
	}

	/**
	 * @inheritDoc
	 */
	public function render( SCC_Content_Object $content, SCC_Template $template ) {
		$vars = $content->variables();
		$html = '';

		foreach ( $template->sections() as $section ) {
			$section_html = '';
			$is_hero      = ( 'hero' === ( $section['key'] ?? '' ) );

			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				$value = self::field_value( $field, $vars );
				if ( '' === trim( wp_strip_all_tags( (string) $value ) ) && 'image' !== ( $field['type'] ?? '' ) ) {
					continue;
				}
				$key = $field['key'] ?? '';

				if ( 'h1' === $key ) {
					// The theme renders the post title as H1; use H2 in-body to avoid duplicate H1s.
					$section_html .= '<h2>' . esc_html( wp_strip_all_tags( $value ) ) . "</h2>\n";
				} elseif ( 'faq' === ( $field['type'] ?? '' ) ) {
					$section_html .= "<h2>" . esc_html__( 'Frequently asked questions', 'seo-command-center' ) . "</h2>\n" . $this->kses( $value );
				} elseif ( 'list' === ( $field['type'] ?? '' ) ) {
					$section_html .= $this->kses( $value );
				} else {
					$section_html .= $this->kses( $value );
				}
			}

			if ( '' === $section_html ) {
				continue;
			}

			// Add a section heading for non-hero sections with a label.
			if ( ! $is_hero && ! empty( $section['label'] ) && ! preg_match( '/^\s*<h[12]/i', $section_html ) ) {
				$html .= '<h2>' . esc_html( $section['label'] ) . "</h2>\n";
			}
			$html .= $section_html . "\n";
		}

		if ( '' === trim( $html ) ) {
			// Never emit an empty page — fall back to the raw content.
			$html = $this->kses( $content->content );
		}

		return array(
			'post_content' => trim( $html ),
			'post_meta'    => array(),
			'post_name'    => $content->slug ? sanitize_title( $this->last_segment( $content->slug ) ) : sanitize_title( $content->title ),
		);
	}

	/**
	 * Resolve a field's value from the variable map.
	 *
	 * @param array $field Field.
	 * @param array $vars  Variables.
	 * @return string
	 */
	protected static function field_value( array $field, array $vars ) {
		$token = ! empty( $field['variable'] ) ? $field['variable'] : strtoupper( (string) ( $field['key'] ?? '' ) );
		if ( isset( $vars[ $token ] ) && '' !== $vars[ $token ] ) {
			return (string) $vars[ $token ];
		}
		return (string) ( $field['default'] ?? '' );
	}

	/**
	 * Sanitize field HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected function kses( $html ) {
		return wp_kses( (string) $html, wp_kses_allowed_html( 'post' ) );
	}

	/**
	 * Last path segment of a URL/path.
	 *
	 * @param string $url URL/path.
	 * @return string
	 */
	protected function last_segment( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$segs = array_filter( explode( '/', (string) ( $path ? $path : $url ) ) );
		return $segs ? (string) end( $segs ) : '';
	}
}
