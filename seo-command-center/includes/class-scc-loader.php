<?php
/**
 * Action/filter loader.
 *
 * Collects hook registrations so components can declare hooks and have them
 * registered in one place, keeping the wiring explicit and testable.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers actions and filters with WordPress.
 */
class SCC_Loader {

	/** @var array<int,array> */
	protected $actions = array();

	/** @var array<int,array> */
	protected $filters = array();

	/**
	 * Add an action.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component      Object instance.
	 * @param string $callback       Method name.
	 * @param int    $priority       Priority.
	 * @param int    $accepted_args  Accepted args.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Add a filter.
	 *
	 * @param string $hook          Hook name.
	 * @param object $component      Object instance.
	 * @param string $callback       Method name.
	 * @param int    $priority       Priority.
	 * @param int    $accepted_args  Accepted args.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all collected hooks with WordPress.
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}
}
