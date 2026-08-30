<?php
/**
 * Normalized AI response value object.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider-independent completion result.
 */
class SCC_AI_Response {

	/** @var string */
	public $content = '';

	/** @var int */
	public $input_tokens = 0;

	/** @var int */
	public $output_tokens = 0;

	/** @var string */
	public $model = '';

	/** @var string */
	public $provider = '';

	/** @var float */
	public $cost = 0.0;

	/** @var WP_Error|null */
	public $error = null;

	/**
	 * Whether the call succeeded.
	 *
	 * @return bool
	 */
	public function is_error() {
		return $this->error instanceof WP_Error;
	}

	/**
	 * Attempt to decode the content as JSON (used when json mode requested).
	 *
	 * @return array|null
	 */
	public function json() {
		$text = (string) $this->content;

		// 1) Straight decode of the trimmed text.
		$data = self::try_decode( trim( $text ) );
		if ( null !== $data ) {
			return $data;
		}

		// 2) Strip Markdown code fences the model may have wrapped it in.
		$stripped = preg_replace( '/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim( $text ) );
		$data     = self::try_decode( trim( (string) $stripped ) );
		if ( null !== $data ) {
			return $data;
		}

		// 3) Small local models often add prose before/after the JSON, or a
		// ```json block mid-message. Extract the largest {...} or [...] span.
		$candidate = self::extract_json_span( $text );
		if ( '' !== $candidate ) {
			$data = self::try_decode( $candidate );
			if ( null !== $data ) {
				return $data;
			}
			// 4) Last resort: repair common breakage (smart quotes, trailing
			// commas, an unterminated tail) and try once more.
			$data = self::try_decode( self::repair_json( $candidate ) );
			if ( null !== $data ) {
				return $data;
			}
		}

		return null;
	}

	/**
	 * Decode JSON, returning null on any error.
	 *
	 * @param string $text JSON text.
	 * @return mixed|null
	 */
	protected static function try_decode( $text ) {
		if ( '' === $text ) {
			return null;
		}
		$data = json_decode( $text, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $data : null;
	}

	/**
	 * Extract the largest balanced {...} or [...] span from arbitrary text.
	 *
	 * @param string $text Raw text.
	 * @return string The span, or '' if none.
	 */
	protected static function extract_json_span( $text ) {
		$text  = (string) $text;
		$first = strcspn( $text, '{[' );
		if ( $first >= strlen( $text ) ) {
			return '';
		}
		$open  = $text[ $first ];
		$close = ( '{' === $open ) ? '}' : ']';
		$last  = strrpos( $text, $close );
		if ( false === $last || $last <= $first ) {
			// No closing bracket (truncated) — take from the first bracket on so
			// repair_json can try to close it.
			return substr( $text, $first );
		}
		return substr( $text, $first, $last - $first + 1 );
	}

	/**
	 * Best-effort repair of near-miss JSON from small models: normalize smart
	 * quotes, drop trailing commas, and balance unclosed brackets/strings.
	 *
	 * @param string $text Candidate JSON.
	 * @return string
	 */
	protected static function repair_json( $text ) {
		$text = (string) $text;
		// Normalize typographic quotes to plain quotes.
		$text = strtr( $text, array( '“' => '"', '”' => '"', '‘' => "'", '’' => "'" ) );
		// Remove trailing commas before } or ].
		$text = preg_replace( '/,\s*([}\]])/', '$1', $text );

		// If it looks truncated, balance quotes and brackets.
		$in_string = false;
		$escaped   = false;
		$stack     = array();
		$len       = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $ch ) {
					$escaped = true;
				} elseif ( '"' === $ch ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in_string = true;
			} elseif ( '{' === $ch || '[' === $ch ) {
				$stack[] = $ch;
			} elseif ( '}' === $ch || ']' === $ch ) {
				array_pop( $stack );
			}
		}
		if ( $in_string ) {
			$text .= '"';
		}
		// Drop any dangling trailing comma created by truncation.
		$text = preg_replace( '/,\s*$/', '', $text );
		while ( ! empty( $stack ) ) {
			$open   = array_pop( $stack );
			$text  .= ( '{' === $open ) ? '}' : ']';
		}
		return $text;
	}
}
