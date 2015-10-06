<?php

/**
 * Plugin Name: Email Token Login
 */

class Email_Token_Login {

  /**
	 * The instance this class.
	 *
	 * @var Email_Token_Login
	 */
	private static $instance;

  /**
   * Created meta key.
   *
   * @var string
   */
  protected $created_meta_key = '_email_token_created';

  /**
   * Token meta key.
   *
   * @var string
   */
  protected $token_meta_key = '_email_token_login';

  /**
   * Get the instance of this class.
   *
   * @return Email_Token_Login
   */
  public static function instance() {
    if ( ! isset( self::$instance ) ) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * The constructor.
   */
  protected function __construct() {
    $this->setup_actions();
    $this->setup_filters();
  }

  /**
   * Add endpoint.
   */
  public function add_endpoint() {
    add_rewrite_tag( '%token%', '([^/]*)' );
    add_rewrite_rule( 'wp-admin/login/([^/]*)/?', 'index.php?token=$matches[1]', 'top' );
  }

  /**
   * Auth user.
   *
   * @param  WP_User $user
   * @param  string $username
   * @param  string $password
   *
   * @return WP_User
   */
  public function auth_user( $user, $username, $password ) {
    return get_user_by( 'login', $username );
  }

  /**
   * Clean users meta values.
   *
   * @param  WP_User $user
   */
  protected function clean_user( WP_User $user ) {
    delete_user_meta( $user->ID, $this->token_meta_key );
    delete_user_meta( $user->ID, $this->created_meta_key );
  }

  /**
   * Find user by token.
   *
   * @param  string $token
   *
   * @return null|WP_User
   */
  protected function find_user( $token ) {
    if ( ! is_string( $token ) || empty( $token ) ) {
      return;
    }

    $users = get_users( [
      'meta_key'    => $this->token_meta_key,
      'meta_value'  => $token,
      'number'      => 1,
      'count_total' => false
    ] );

    return empty( $users ) ? null : $users[0];
  }

  /**
   * Determine if email is shared email or not.
   *
   * @param  string $email
   *
   * @return string
   */
  protected function get_user( $email ) {
    if ( ! is_string( $email ) || empty( $email ) ) {
      return '';
    }

    $users = $this->get_shared_users();
    $parts = explode( '@', $email );

    foreach ( $users as $domain => $username ) {
      if ( $domain === $parts[1] ) {
        return $username;
      }
    }

    return '';
  }

  /**
   * Change username to e-mail.
   *
   * @param  string $translated_text
   *
   * @return string
   */
  public function get_email_label( $translated_text ) {
    global $pagenow;

    if ( $pagenow === 'wp-login.php' && $translated_text === 'Username' ) {
      return __( 'E-mail' );
    }

    return $translated_text;
  }

  /**
   * Get mail body.
   *
   * @param  string $url
   *
   * @return string
   */
  protected function get_mail_body( $url ) {
    return sprintf( 'Your login link: <a href="%s">%s</a>', $url, $url );
  }

  /**
   * Get mail title.
   *
   * @return string
   */
  protected function get_mail_title() {
    return apply_filters( 'email_token_login/mail_title', __( 'WordPress Login', 'email-token-login' ) );
  }

  /**
   * Get shared users.
   *
   * @return array
   */
  protected function get_shared_users() {
    $users = apply_filters( 'email_token_login/shared_users', [] );

    foreach ( $users as $domain => $username ) {
      if ( ! preg_match( '/\@\w+\.\w+/', '@' . $domain ) || ! username_exists( $username ) ) {
        unset( $users[$domain] );
      }
    }

    return $users;
  }

  /**
   * Handle endpoint.
   */
  public function handle_endpoint() {
    global $wp_query;

    if ( ! is_object( $wp_query ) ) {
      return;
    }

    // No ajax login is supported at the moment.
  	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

  	if ( ! empty( $_GET['token'] ) ) {
			$wp_query->set(
				'email_token_login',
				sanitize_text_field( $_GET['token'] )
			);
		}

    $token = $wp_query->get( 'email_token_login' );

    // No token, no login!
    if ( empty( $token ) ) {
      return;
    }

    // Base64 is not used for encryption, it's used
    // to get a nicer url token.
    $token = base64_decode( $token );

    // Try to login user with token.
    if ( $user = $this->find_user( $token ) ) {
      if ( $this->valid_user( $user ) ) {
        // Hack to login a user with no password.
        add_filter( 'authenticate', [$this, 'auth_user'], 10, 3 );
        $user = wp_signon( ['user_login' => $user->user_login] );
        remove_filter( 'authenticate', [$this, 'auth_user'], 10, 3 );

        if ( $user instanceof WP_User ) {
          // Clean users meta values.
          $this->clean_user( $user );

          // Set current user.
          wp_set_current_user( $user->ID, $user->user_login );

          // Determine if the user is logged in or not.
          if ( is_user_logged_in() ) {
            wp_safe_redirect( admin_url() );
            exit;
          }
        }
      }
    }

    // If no success, redirect to login page.
    wp_safe_redirect( site_url( 'wp-login.php' ) );
    exit;
  }

  /**
   * Output CSS on login page.
   */
  public function login_head() {
    ?>
    <style type="text/css">
      label[for="user_pass"],
      .login #nav a {
        display: none;
      }
    </style>
    <?php
  }

  /**
   * Generate token mail if a user is found
   * or create a error.
   */
  public function login_user() {
    if ( isset( $_POST['log'] ) ) {
      $email = $_POST['log'];

      if ( $username = $this->get_user( $email ) ) {
        $user = get_user_by( 'login', $username );
      } else {
        $user = get_user_by( 'email', $email );
      }

      if ( $user instanceof WP_User ) {
        $token = wp_hash_password( $email );

        // Update user meta with token and created time.
        update_user_meta( $user->ID, $this->token_meta_key, $token );
        update_user_meta( $user->ID, $this->created_meta_key, time() );

        // Base64 is not used for encryption, it's used
        // to get a nicer url token.
        $token = base64_encode( $token );

        // Generate login url.
        $url = admin_url( 'login/?token=' . $token );

        // Send mail!
        $mail_sent = ! wp_mail( $email, wp_specialchars_decode( $this->get_mail_title() ), $this->get_mail_body( $url ) );

        global $errors;

        if ( apply_filters( 'email_token_login/dev_mode', defined( 'WP_ENV' ) && WP_ENV === 'development' ) ) {
          $errors->add( 'token', sprintf( 'Development mode: <a href="%s">Login</a>', $url ), 'message' );
        } else {
          if ( $mail_sent ) {
            $errors->add( 'token', __( 'We sent you a link to sign in. Please check your inbox.', 'email-token-login' ), 'message' );
          } else {
            $errors->add( 'token', __( 'The email could not be sent. Possible reason: your host may have disabled the mail() function.', 'email-token-login' ) );
          }
        }
      } else {
        global $errors;
        $errors->add( 'no_user_found', __( 'No user found!', 'email-token-login' ) );
      }
    }
  }

  /**
   * Setup actions.
   */
  protected function setup_actions() {
    add_action( 'init', [$this, 'add_endpoint'] );
    add_action( 'login_head', [$this, 'login_head'] );
    add_action( 'login_head', [$this, 'login_user'] );
		add_action( 'parse_query', [$this, 'handle_endpoint'] );
  }

  /**
   * Setup filters.
   */
  protected function setup_filters() {
    add_filter( 'gettext', [$this, 'get_email_label'] );
    add_filter( 'wp_login_errors', [$this, 'wp_login_errors'], 10, 0 );
    remove_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
  }

  /**
   * Determine if we have valid user to login with.
   *
   * @param  WP_User $user
   *
   * @return bool
   */
  protected function valid_user( WP_User $user ) {
    $created = get_user_meta( $user->ID, $this->created_meta_key, true );
    $created = intval( $created );
    $minutes = round( abs( time() - $created ) / 60, 2 );
    return $minutes <= apply_filters( 'email_login_token/minutes', 15 );
  }

  /**
   * Clear login errors.
   *
   * @return WP_Error
   */
  public function wp_login_errors() {
    return new WP_Error();
  }

}

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
  return Email_Token_Login::instance();
} );

//add_filter( 'email_token_login/dev_mode', '__return_true' );
