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
		$text = trim( $this->content );
		// Strip Markdown code fences if the model wrapped the JSON.
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $data : null;
	}
}
