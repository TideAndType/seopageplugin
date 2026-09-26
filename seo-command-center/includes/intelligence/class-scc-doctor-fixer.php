<?php
/**
 * SEO Doctor one-click fixes.
 *
 * Every fix has two modes:
 *   - preview (dry run): describes exactly what would change — nothing is written;
 *   - apply: makes that change, only on an explicit user click.
 *
 * Fixes reuse the plugin's existing, reversible systems (meta history, schema
 * change history, the internal-link inserter) so every change can be undone
 * from its usual screen. Nothing here runs on a schedule or from Autopilot.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click fix runner.
 */
class SCC_Doctor_Fixer {

	const TYPES = array( 'meta_description', 'schema', 'social_tags', 'internal_links' );

	/** Fixes that change the whole site rather than one page. */
	const SITE_TYPES = array( 'social_tags' );

	/** @var SCC_AI_Manager|null */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager|null $ai AI manager (only needed by the meta optimizer).
	 */
	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Preview or apply a fix.
	 *
	 * @param string $type    Fix type.
	 * @param int    $post_id Target post (0 for site-level fixes).
	 * @param bool   $apply   False = preview only.
	 * @return array|WP_Error {type, post_id, applied, summary, before, after, details[]}
	 */
	public function run( $type, $post_id, $apply = false ) {
		$type    = (string) $type;
		$post_id = (int) $post_id;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'scc_bad_fix', __( 'Unknown fix.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		if ( in_array( $type, self::SITE_TYPES, true ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'scc_forbidden', __( 'Only administrators can change site-wide settings.', 'seo-command-center' ), array( 'status' => 403 ) );
			}
		} else {
			$post = $post_id > 0 ? get_post( $post_id ) : null;
			if ( ! $post ) {
				return new WP_Error( 'scc_no_post', __( 'This fix needs a specific page, and none was found.', 'seo-command-center' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'scc_forbidden', __( 'You cannot edit this page.', 'seo-command-center' ), array( 'status' => 403 ) );
			}
		}

		switch ( $type ) {
			case 'meta_description':
				return $this->meta_description( $post_id, $apply );
			case 'schema':
				return $this->schema( $post_id, $apply );
			case 'social_tags':
				return $this->social_tags( $apply );
			case 'internal_links':
				return $this->internal_links( $post_id, $apply );
		}
		return new WP_Error( 'scc_bad_fix', __( 'Unknown fix.', 'seo-command-center' ), array( 'status' => 400 ) );
	}

	/**
	 * Write a page-specific meta description drawn from the page's own content.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $apply   Apply or preview.
	 * @return array|WP_Error
	 */
	protected function meta_description( $post_id, $apply ) {
		$post    = get_post( $post_id );
		$current = SCC_Metadata::current( $post_id );
		$text    = class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::get_plain_text( $post ) : wp_strip_all_tags( (string) $post->post_content );
		$draft   = self::draft_description( $text, (string) $post->post_excerpt );

		if ( '' === $draft ) {
			return new WP_Error( 'scc_no_copy', __( 'This page has too little text to write an accurate description from. Add a description in the Meta Editor instead.', 'seo-command-center' ), array( 'status' => 422 ) );
		}

		$result = array(
			'type'    => 'meta_description',
			'post_id' => $post_id,
			'applied' => false,
			'summary' => sprintf( __( 'Set the meta description of “%s” to a summary written from the page’s own content.', 'seo-command-center' ), get_the_title( $post ) ),
			'before'  => (string) ( $current['description'] ?? '' ),
			'after'   => $draft,
			'details' => array( __( 'Recorded in meta history — revert any time from the Meta Editor.', 'seo-command-center' ) ),
		);
		if ( ! $apply ) {
			return $result;
		}

		$optimizer = new SCC_Meta_Optimizer( $this->ai ? $this->ai : new SCC_AI_Manager() );
		$applied   = $optimizer->apply( $post_id, array( 'description' => $draft ), __( 'SEO Doctor one-click fix', 'seo-command-center' ), true );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$result['applied'] = true;
		return $result;
	}

	/**
	 * Add accurate structured data for the page's type.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $apply   Apply or preview.
	 * @return array|WP_Error
	 */
	protected function schema( $post_id, $apply ) {
		$generated = SCC_Schema_Engine::generate( $post_id );
		$nodes     = (array) ( $generated['nodes'] ?? array() );
		if ( empty( $nodes ) ) {
			$why = ! empty( $generated['warnings'] ) ? ' ' . implode( ' ', array_map( 'strval', (array) $generated['warnings'] ) ) : '';
			return new WP_Error( 'scc_no_schema', __( 'Accurate schema could not be generated for this page yet — check your business details under Schema settings.', 'seo-command-center' ) . $why, array( 'status' => 422 ) );
		}
		$types = array();
		foreach ( $nodes as $node ) {
			foreach ( (array) ( $node['@type'] ?? array() ) as $t ) {
				$types[] = (string) $t;
			}
		}
		$types = array_values( array_unique( $types ) );

		$result = array(
			'type'    => 'schema',
			'post_id' => $post_id,
			'applied' => false,
			'summary' => sprintf( __( 'Add %1$s structured data to “%2$s”.', 'seo-command-center' ), implode( ', ', $types ), get_the_title( $post_id ) ),
			'before'  => '',
			'after'   => implode( ', ', $types ),
			'details' => array_merge(
				array( __( 'Validated before saving and recorded in change history (revertible).', 'seo-command-center' ) ),
				array_map( 'strval', (array) ( $generated['warnings'] ?? array() ) )
			),
		);
		if ( ! $apply ) {
			return $result;
		}

		$saved = SCC_Schema_Engine::save( $post_id, $nodes );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$result['applied'] = true;
		return $result;
	}

	/**
	 * Turn on TideOrbit's Open Graph / Twitter card output. Refused when another
	 * SEO plugin is active, because that plugin already owns those tags and two
	 * sets would conflict.
	 *
	 * @param bool $apply Apply or preview.
	 * @return array|WP_Error
	 */
	protected function social_tags( $apply ) {
		$plugin = SCC_SEO_Meta::detect();
		if ( SCC_SEO_Meta::PLUGIN_NONE !== $plugin ) {
			return new WP_Error(
				'scc_seo_plugin_owns_social',
				sprintf(
					/* translators: %s: SEO plugin name */
					__( '%s is active and controls your social tags, so TideOrbit will not add a second, conflicting set. Turn on its Open Graph / social settings instead (Yoast: Settings → Social sharing; Rank Math: Titles & Meta → Social Meta).', 'seo-command-center' ),
					SCC_SEO_Meta::label( $plugin )
				),
				array( 'status' => 409 )
			);
		}
		$result = array(
			'type'    => 'social_tags',
			'post_id' => 0,
			'applied' => false,
			'summary' => __( 'Output og:title, og:description, og:image, og:url and a Twitter card on every public page, using each page’s SEO title, meta description and featured image.', 'seo-command-center' ),
			'before'  => __( 'Off', 'seo-command-center' ),
			'after'   => __( 'On', 'seo-command-center' ),
			'details' => array( __( 'Turn it off again any time under Settings → “Social sharing tags”.', 'seo-command-center' ) ),
		);
		if ( ! $apply ) {
			return $result;
		}
		SCC_Settings::update( array( 'output_social_tags' => true ) );
		$result['applied'] = true;
		return $result;
	}

	/**
	 * Link an orphaned page from relevant pages, using the high-confidence
	 * recommendations from the internal-link engine.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $apply   Apply or preview.
	 * @return array|WP_Error
	 */
	protected function internal_links( $post_id, $apply ) {
		if ( ! class_exists( 'SCC_Link_Engine' ) || ! class_exists( 'SCC_Action_Queue' ) ) {
			return new WP_Error( 'scc_unavailable', __( 'The internal-link engine is not available.', 'seo-command-center' ), array( 'status' => 500 ) );
		}

		$engine = new SCC_Link_Engine();
		$engine->analyze( $post_id, true );
		$high = (int) SCC_Settings::get( 'link_high_confidence', 80 );
		$recs = array();
		foreach ( SCC_Link_Engine::recommendations( array( 'min_confidence' => $high, 'limit' => 50 ) ) as $rec ) {
			if ( $post_id === (int) ( $rec['target_post_id'] ?? 0 ) || $post_id === (int) ( $rec['source_post_id'] ?? 0 ) ) {
				$recs[] = $rec;
			}
		}
		if ( empty( $recs ) ) {
			return new WP_Error( 'scc_no_links', __( 'No high-confidence link placements were found for this page yet. Open Internal Links to review lower-confidence suggestions, or add a link from a related page by hand.', 'seo-command-center' ), array( 'status' => 422 ) );
		}

		$details = array();
		foreach ( array_slice( $recs, 0, 6 ) as $rec ) {
			$details[] = sprintf(
				/* translators: 1: anchor text, 2: source page, 3: target page */
				__( 'Link “%1$s” on “%2$s” → “%3$s”', 'seo-command-center' ),
				(string) ( $rec['anchor'] ?? '' ),
				get_the_title( (int) ( $rec['source_post_id'] ?? 0 ) ),
				get_the_title( (int) ( $rec['target_post_id'] ?? 0 ) )
			);
		}
		$details[] = __( 'Each inserted link can be removed again from Internal Links.', 'seo-command-center' );

		$result = array(
			'type'    => 'internal_links',
			'post_id' => $post_id,
			'applied' => false,
			'summary' => sprintf( _n( 'Insert %1$d high-confidence internal link for “%2$s”.', 'Insert %1$d high-confidence internal links for “%2$s”.', count( $recs ), 'seo-command-center' ), count( $recs ), get_the_title( $post_id ) ),
			'before'  => '',
			'after'   => '',
			'details' => $details,
		);
		if ( ! $apply ) {
			return $result;
		}

		$action_id = SCC_Action_Queue::promote(
			array(
				'id'          => 'doctor-orphan-' . $post_id,
				'action_type' => 'fix_orphan',
				'title'       => sprintf( __( 'Add internal links to “%s”', 'seo-command-center' ), get_the_title( $post_id ) ),
				'target'      => array( 'post_id' => $post_id ),
				'priority'    => 'high',
				'reason'      => __( 'SEO Doctor: the page has no internal links pointing to it.', 'seo-command-center' ),
				'source'      => 'seo_doctor',
			),
			'approved'
		);
		if ( ! $action_id ) {
			return new WP_Error( 'scc_queue_failed', __( 'Could not create the linking action.', 'seo-command-center' ), array( 'status' => 500 ) );
		}
		$ran = SCC_Action_Queue::execute( $action_id );
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$result['applied'] = true;
		$result['summary'] = (string) ( $ran['message'] ?? $result['summary'] );
		return $result;
	}

	/**
	 * Draft a meta description from a page's own words: the excerpt when one was
	 * written, otherwise the opening sentences, cut at a sentence or word boundary
	 * near 155 characters. Returns '' when there is too little real copy.
	 *
	 * @param string $text    Plain page text.
	 * @param string $excerpt Manual excerpt (preferred).
	 * @param int    $max     Target maximum length.
	 * @return string
	 */
	public static function draft_description( $text, $excerpt = '', $max = 155 ) {
		$clean = function ( $value ) {
			$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
			return trim( preg_replace( '/\s+/u', ' ', $value ) );
		};
		$len = function ( $value ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		};
		$cut = function ( $value, $n ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $n ) : substr( $value, 0, $n );
		};

		$source = $clean( $excerpt );
		if ( $len( $source ) < 50 ) {
			$source = $clean( $text );
		}
		if ( $len( $source ) < 50 ) {
			return '';
		}
		if ( $len( $source ) <= $max ) {
			return $source;
		}

		// Prefer whole sentences that fit.
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $source );
		$out = '';
		foreach ( (array) $sentences as $sentence ) {
			$next = '' === $out ? $sentence : $out . ' ' . $sentence;
			if ( $len( $next ) > $max ) {
				break;
			}
			$out = $next;
		}
		if ( $len( $out ) >= 70 ) {
			return $out;
		}

		// Otherwise cut at the last word boundary and close with an ellipsis.
		$slice = $cut( $source, $max - 1 );
		$space = strrpos( $slice, ' ' );
		if ( false !== $space && $space > 60 ) {
			$slice = substr( $slice, 0, $space );
		}
		return rtrim( $slice, " ,;:-–—" ) . '…';
	}
}
