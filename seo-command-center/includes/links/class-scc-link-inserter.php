<?php
/**
 * Internal link inserter.
 *
 * Inserts an approved recommendation into the source post by wrapping the first
 * natural, unlinked occurrence of the anchor phrase in a body text node.
 *
 * Anti-spam guards:
 *  - never exceeds the per-page internal-link cap (Settings);
 *  - never links the same target twice from one page;
 *  - only the FIRST occurrence is linked (no exact-match spam);
 *  - never links inside existing anchors or headings;
 *  - only links a phrase that genuinely appears in the content.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link inserter.
 */
class SCC_Link_Inserter {

	/**
	 * Apply a stored recommendation.
	 *
	 * @param int    $link_id scc_internal_links row id.
	 * @param string $trigger Trigger source (manual|autopilot|batch).
	 * @return array|WP_Error {source_post_id, target_post_id, anchor}
	 */
	public function apply( $link_id, $trigger = 'manual' ) {
		$row = SCC_DB::get( 'internal_links', $link_id );
		if ( ! $row ) {
			return new WP_Error( 'scc_no_link', __( 'Recommendation not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		if ( 'applied' === $row['status'] ) {
			return new WP_Error( 'scc_already', __( 'This link was already applied.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$source_id = (int) $row['source_post_id'];
		$target_id = (int) $row['target_post_id'];
		$anchor    = (string) $row['anchor'];

		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit the source post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}

		$source = get_post( $source_id );
		$target_url = get_permalink( $target_id );
		if ( ! $source || ! $target_url ) {
			return new WP_Error( 'scc_missing', __( 'Source or target no longer exists.', 'seo-command-center' ), array( 'status' => 404 ) );
		}

		// Elementor-built pages keep their visible text in _elementor_data, not in
		// post_content — so a link written to post_content would never appear on
		// the page. Detect that and route the insertion into the Elementor widget.
		$is_elementor = class_exists( 'SCC_Elementor' )
			&& SCC_Elementor::is_elementor_post( $source_id )
			&& is_array( SCC_Elementor::get_data( $source_id ) );

		if ( $is_elementor ) {
			return $this->apply_elementor( $link_id, $row, $source_id, $target_id, $target_url, $anchor, $trigger );
		}

		$content = $source->post_content;

		// Per-page cap. There is no hard SEO limit, so a manual insert is never
		// blocked by it — the user chose to add this link. The cap only governs
		// AUTOMATIC (autopilot) insertion, and even then acts as a high safety
		// ceiling rather than a small quota.
		if ( 'autopilot' === $trigger ) {
			$max = (int) SCC_Settings::get( 'max_internal_links', 8 );
			if ( $max > 0 && $this->count_internal_links( $content ) >= $max ) {
				return new WP_Error( 'scc_cap', __( 'Autopilot skipped this link: the page is already at your configured internal-link limit (raise it in Settings). You can still insert it manually.', 'seo-command-center' ), array( 'status' => 409 ) );
			}
		}

		// Guard: max links to the SAME destination (avoid over-linking one page).
		$per_dest = (int) SCC_Settings::get( 'link_max_per_destination', 1 );
		if ( $per_dest > 0 && substr_count( $content, $target_url ) >= $per_dest ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_exists', __( 'The source already links to the target the allowed number of times.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$new_content = $this->insert_anchor( $content, $anchor, $target_url );
		if ( null === $new_content ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'skipped' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_no_anchor', __( 'The anchor phrase was not found in the content to link naturally.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $source_id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );

		// Record for revert (previous_value = full content before insertion).
		if ( class_exists( 'SCC_Change_History' ) ) {
			SCC_Change_History::record(
				array(
					'post_id'        => $source_id,
					'change_type'    => 'internal_link',
					'previous_value' => $content,
					'new_value'      => sprintf( 'Linked “%s” -> %s', $anchor, $target_url ),
					'reason'         => (string) ( $row['reason'] ?? '' ),
					'confidence'     => (int) ( $row['confidence'] ?? 0 ),
					'trigger_source' => $trigger,
				)
			);
		}

		// Keep the index fresh for the modified source.
		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( $source_id );
		}

		SCC_Logger::info( 'link-inserter', 'Internal link applied', array( 'source' => $source_id, 'target' => $target_id, 'trigger' => $trigger ) );

		return array(
			'source_post_id' => $source_id,
			'target_post_id' => $target_id,
			'anchor'         => $anchor,
		);
	}

	/**
	 * Apply a recommendation to an Elementor-built page by editing the widget
	 * that actually holds the anchor text (settings.editor / settings.text), so
	 * the link appears on the rendered page instead of in unused post_content.
	 *
	 * @param int    $link_id    Recommendation row id.
	 * @param array  $row        The recommendation row.
	 * @param int    $source_id  Source post id.
	 * @param int    $target_id  Target post id.
	 * @param string $target_url Target URL.
	 * @param string $anchor     Anchor phrase.
	 * @param string $trigger    Trigger source.
	 * @return array|WP_Error
	 */
	protected function apply_elementor( $link_id, array $row, $source_id, $target_id, $target_url, $anchor, $trigger ) {
		$data = SCC_Elementor::get_data( $source_id );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'scc_no_anchor', __( 'The Elementor layout could not be read to place the link.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$combined = $this->collect_elementor_html( $data );

		// Per-page cap governs autopilot only (no hard SEO limit on manual inserts).
		if ( 'autopilot' === $trigger ) {
			$max = (int) SCC_Settings::get( 'max_internal_links', 8 );
			if ( $max > 0 && $this->count_internal_links( $combined ) >= $max ) {
				return new WP_Error( 'scc_cap', __( 'Autopilot skipped this link: the page is already at your configured internal-link limit (raise it in Settings). You can still insert it manually.', 'seo-command-center' ), array( 'status' => 409 ) );
			}
		}

		// Don't over-link the same destination.
		$per_dest = (int) SCC_Settings::get( 'link_max_per_destination', 1 );
		if ( $per_dest > 0 && substr_count( $combined, $target_url ) >= $per_dest ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_exists', __( 'The source already links to the target the allowed number of times.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$done    = false;
		$new_data = $this->walk_elementor( $data, $anchor, $target_url, $done );
		if ( ! $done ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'skipped' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_no_anchor', __( 'The anchor phrase was not found in the page’s Elementor text to link naturally.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$json = wp_json_encode( $new_data );
		if ( ! $json ) {
			return new WP_Error( 'scc_encode', __( 'Could not save the updated Elementor layout.', 'seo-command-center' ), array( 'status' => 500 ) );
		}
		// Elementor stores _elementor_data slashed.
		update_post_meta( $source_id, '_elementor_data', wp_slash( $json ) );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );

		if ( class_exists( 'SCC_Change_History' ) ) {
			SCC_Change_History::record(
				array(
					'post_id'        => $source_id,
					'change_type'    => 'internal_link',
					'previous_value' => wp_json_encode( $data ),
					'new_value'      => sprintf( 'Linked “%s” -> %s (Elementor)', $anchor, $target_url ),
					'reason'         => (string) ( $row['reason'] ?? '' ),
					'confidence'     => (int) ( $row['confidence'] ?? 0 ),
					'trigger_source' => $trigger,
				)
			);
		}

		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( $source_id );
		}

		SCC_Logger::info( 'link-inserter', 'Internal link applied (Elementor)', array( 'source' => $source_id, 'target' => $target_id, 'trigger' => $trigger ) );

		return array(
			'source_post_id' => $source_id,
			'target_post_id' => $target_id,
			'anchor'         => $anchor,
		);
	}

	/**
	 * Text/HTML fields on an Elementor widget that can carry body copy.
	 *
	 * @param array $settings Widget settings.
	 * @return array<int,string> Field keys present with non-empty string values.
	 */
	protected function elementor_text_fields( array $settings ) {
		$fields = array();
		foreach ( array( 'editor', 'text', 'description_text', 'title_text' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$fields[] = $key;
			}
		}
		return $fields;
	}

	/**
	 * Concatenate all Elementor widget text so link-count / dedup guards can run.
	 *
	 * @param array $elements Elementor elements.
	 * @return string
	 */
	protected function collect_elementor_html( array $elements ) {
		$html = '';
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $this->elementor_text_fields( $el['settings'] ) as $key ) {
					$html .= ' ' . $el['settings'][ $key ];
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$html .= ' ' . $this->collect_elementor_html( $el['elements'] );
			}
		}
		return $html;
	}

	/**
	 * Walk the Elementor tree and link the first natural occurrence of the anchor
	 * inside a text/HTML widget field. Sets $done true once placed.
	 *
	 * @param array  $elements   Elements.
	 * @param string $anchor     Anchor phrase.
	 * @param string $target_url Target URL.
	 * @param bool   $done       By-ref flag; true once a link was placed.
	 * @return array Modified elements.
	 */
	protected function walk_elementor( array $elements, $anchor, $target_url, &$done ) {
		foreach ( $elements as &$el ) {
			if ( $done || ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $this->elementor_text_fields( $el['settings'] ) as $key ) {
					$field = $el['settings'][ $key ];
					// Don't add a second link to a field that already targets this URL.
					if ( false !== strpos( $field, $target_url ) ) {
						continue;
					}
					$updated = $this->insert_anchor( $field, $anchor, $target_url );
					if ( null !== $updated ) {
						$el['settings'][ $key ] = $updated;
						$done                   = true;
						break;
					}
				}
			}
			if ( ! $done && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $this->walk_elementor( $el['elements'], $anchor, $target_url, $done );
			}
		}
		unset( $el );
		return $elements;
	}

	/**
	 * Insert a link into an HTML string (used pre-render on a content object).
	 * Returns the original HTML unchanged if the anchor can't be placed naturally.
	 *
	 * @param string $html       HTML.
	 * @param string $anchor     Anchor phrase.
	 * @param string $target_url Target URL.
	 * @return string
	 */
	public function insert_link_in_html( $html, $anchor, $target_url ) {
		if ( '' === trim( (string) $html ) || false !== strpos( $html, $target_url ) ) {
			return $html;
		}
		$result = $this->insert_anchor( $html, $anchor, $target_url );
		return ( null === $result ) ? $html : $result;
	}

	/**
	 * Count existing internal links in content.
	 *
	 * @param string $content Content.
	 * @return int
	 */
	protected function count_internal_links( $content ) {
		if ( ! preg_match_all( '/<a\s[^>]*href=("|\')(.*?)\1/i', $content, $m ) ) {
			return 0;
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$count     = 0;
		foreach ( $m[2] as $href ) {
			if ( 0 === strpos( $href, '#' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home_host || 0 === strpos( $href, '/' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Insert an anchor around the first natural, unlinked occurrence.
	 *
	 * @param string $content    Post content HTML.
	 * @param string $anchor     Anchor phrase.
	 * @param string $target_url Target URL.
	 * @return string|null New content, or null if the anchor was not placed.
	 */
	protected function insert_anchor( $content, $anchor, $target_url ) {
		$anchor = trim( $anchor );
		if ( '' === $anchor ) {
			return null;
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="scc-root">' . $content . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );

		// Text nodes not inside links or headings.
		$text_nodes = $xpath->query( '//div[@id="scc-root"]//text()[not(ancestor::a) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4) and not(ancestor::h5) and not(ancestor::h6)]' );

		foreach ( $text_nodes as $node ) {
			$value = $node->nodeValue;
			$pos   = stripos( $value, $anchor );
			if ( false === $pos ) {
				continue;
			}

			// Split: before | anchor | after.
			$before  = substr( $value, 0, $pos );
			$matched = substr( $value, $pos, strlen( $anchor ) );
			$after   = substr( $value, $pos + strlen( $anchor ) );

			$parent = $node->parentNode;

			$link = $dom->createElement( 'a' );
			$link->setAttribute( 'href', $target_url );
			$link->appendChild( $dom->createTextNode( $matched ) );

			if ( '' !== $before ) {
				$parent->insertBefore( $dom->createTextNode( $before ), $node );
			}
			$parent->insertBefore( $link, $node );
			if ( '' !== $after ) {
				$parent->insertBefore( $dom->createTextNode( $after ), $node );
			}
			$parent->removeChild( $node );

			return $this->inner_html( $dom, $xpath );
		}

		return null;
	}

	/**
	 * Serialize the inner HTML of the scc-root wrapper.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 * @return string
	 */
	protected function inner_html( DOMDocument $dom, DOMXPath $xpath ) {
		$root = $xpath->query( '//div[@id="scc-root"]' )->item( 0 );
		if ( ! $root ) {
			return '';
		}
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
