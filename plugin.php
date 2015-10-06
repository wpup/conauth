<?php

/**
 * Plugin Name: Email Token Login
 * Plugin URI: https://github.com/frozzare/wp-email-token-login
 * Description: Signing in to WordPress by email without password.
 * Author: Fredrik Forsmo
 * Author URI: https://forsmo.me
 * Version: 1.0.0
 * Textdomain: email-token-login
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Load plugin class.
require_once __DIR__ . '/src/class-email-token-login.php';

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
  return \Frozzare\Email_Token_Login\Email_Token_Login::instance();
} );
