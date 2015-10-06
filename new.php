<?php

class Email_Token_Login {

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
   * The constructor.
   */
  public function __construct() {
    $this->add_actions();
    $this->add_filters();
  }

  /**
   * Add actions.
   */
  protected function add_actions() {
    add_action( 'init', [$this, 'add_endpoint'] );
    add_action( 'login_head', [$this, 'login_head'] );
    add_action( 'login_head', [$this, 'login_magic'] );
		add_action( 'parse_query', [$this, 'handle_endpoint'] );
  }

  /**
   * Add filters.
   */
  protected function add_filters() {
    add_filter( 'gettext', [$this, 'get_email_label'] );
    add_filter( 'wp_login_errors', [$this, 'wp_login_errors'], 10, 0 );
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
  protected function shared_user( $email ) {
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

  public function generate_token_mail() {
    $log_key = 'log';
    if ( isset( $_POST['log'] ) ) {
      $email  = $_POST['log'];

      if ( in_array( $parts[1], $shared ) ) {
        if ( $username = $this->get_user( $email ) ) {

        } else {

        }
      }
    }
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
   * Get shared users.
   *
   * @return array
   */
  protected function get_shared_users() {
    $users = apply_filters( 'email_token_login/shared_users' );

    foreach ( $users as $domain => $username ) {
      if ( ! preg_match( '/\@\w+\.\w+/', '@' . $domain ) || ! username_exists( $username ) ) {
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

  	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

  	if ( ! empty( $_GET['token'] ) ) {
			$wp_query->set(
				'email_token_login',
				sanitize_text_field( $_GET['token'] )
			);
		}

    $success = false;
    $token   = $wp_query->get( 'email_token_login' );

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
            $success = true;
            wp_safe_redirect( admin_url() );
            exit;
          }
        }
      }
    }

    // If no success, redirect to login page.
    if ( ! $success ) {
      $this->clean_user()
      wp_safe_redirect( site_url( 'wp-login.php' ) );
      exit;
    }
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
    return $minutes > apply_filters( 'email_login_token/minutes', 15 );
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
