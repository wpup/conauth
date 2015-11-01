<?php

/**
 * Plugin Name: Conauth
 * Plugin URI: https://github.com/frozzare/wp-conauth
 * Description: Signing in to WordPress by link sent to your email
 * Author: Fredrik Forsmo
 * Author URI: https://forsmo.me
 * Version: 1.0.0
 * Textdomain: conauth
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Load plugin class.
require_once __DIR__ . '/src/class-conauth.php';

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
    /**
     * Get shared top domains associated with users.
     *
     * @var array
     */
    if ( add_filter( 'conauth/disabled', false ) === false ) {
        return \Frozzare\Conauth\Conauth::instance();
    }
} );
