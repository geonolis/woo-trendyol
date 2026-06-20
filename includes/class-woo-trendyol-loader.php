<?php
/**
 * Register all actions and filters for the plugin.
 *
 * Maintains a list of all hooks that are registered throughout the plugin,
 * and registers them with the WordPress API. Call the run() method to
 * execute the list of actions and filters.
 *
 * @link    https://developers.trendyol.com
 * @since   1.0.0
 * @package Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Woo_Trendyol_Loader
 *
 * Collects all WordPress action and filter registrations and fires them
 * together when run() is called. This pattern keeps hook registration
 * centralised and testable.
 *
 * @since      1.0.0
 * @package    Woo_Trendyol
 * @subpackage Woo_Trendyol/includes
 */
class Woo_Trendyol_Loader {

    /**
     * The array of actions registered with WordPress.
     *
     * @since  1.0.0
     * @access protected
     * @var    array $actions
     */
    protected array $actions = [];

    /**
     * The array of filters registered with WordPress.
     *
     * @since  1.0.0
     * @access protected
     * @var    array $filters
     */
    protected array $filters = [];

    // -----------------------------------------------------------------------
    // Registration methods
    // -----------------------------------------------------------------------

    /**
     * Add a new action to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook          The name of the WordPress action hook.
     * @param object $component     A reference to the instance of the object on which the action is defined.
     * @param string $callback      The name of the function definition on the $component.
     * @param int    $priority      Optional. The priority at which the function should be fired. Default 10.
     * @param int    $accepted_args Optional. The number of arguments that should be passed to the $callback. Default 1.
     */
    public function add_action(
        string $hook,
        object $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Add a new filter to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook          The name of the WordPress filter hook.
     * @param object $component     A reference to the instance of the object on which the filter is defined.
     * @param string $callback      The name of the function definition on the $component.
     * @param int    $priority      Optional. The priority at which the function should be fired. Default 10.
     * @param int    $accepted_args Optional. The number of arguments that should be passed to the $callback. Default 1.
     */
    public function add_filter(
        string $hook,
        object $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
    }

    // -----------------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------------

    /**
     * Register the filters and actions with WordPress.
     *
     * @since 1.0.0
     */
    public function run(): void {
        foreach ( $this->filters as $hook ) {
            add_filter(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ( $this->actions as $hook ) {
            add_action(
                $hook['hook'],
                [ $hook['component'], $hook['callback'] ],
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * A utility function that is used to register the actions and hooks into a
     * single collection.
     *
     * @since  1.0.0
     * @access private
     * @param  array  $hooks         The collection of hooks already registered.
     * @param  string $hook          The name of the WordPress filter/action hook.
     * @param  object $component     A reference to the instance of the object.
     * @param  string $callback      The name of the function on the $component.
     * @param  int    $priority      The priority at which the function should be fired.
     * @param  int    $accepted_args The number of arguments that should be passed to the $callback.
     * @return array  The collection of actions and filters registered with WordPress.
     */
    private function add(
        array $hooks,
        string $hook,
        object $component,
        string $callback,
        int $priority,
        int $accepted_args
    ): array {
        $hooks[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];

        return $hooks;
    }
}
