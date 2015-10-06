<?php

/**
 * Plugin Name: Email Login
 */

  function i_login_user( $user, $username, $password ) {
    return get_user_by( 'login', $username );
  };

add_action( 'login_head', 'wpse_121687_hide_login' );
function wpse_121687_hide_login() {
    $style = '';
    $style .= '<style type="text/css">';
    $style .= 'label[for="user_pass"]{ display: none }';
    $style .= '.login #nav a, .login #backtoblog a { display: none }';
    $style .= '</style>';

    echo $style;
}

	function email_login_auth_username_label( $translated_text, $untranslated_text, $domain ) {
		if ( $untranslated_text == 'Username' ) {
			$translated_text = __( 'E-mail' );
		}
		return $translated_text;
	}

	function register_email_login_auth_label() {
		add_filter( 'gettext', 'email_login_auth_username_label', 99, 3 );
	}
	add_filter( 'login_init', 'register_email_login_auth_label' );

add_filter( 'wp_login_errors', function ( $errors, $redirect_to ) {
  return new WP_Error();
}, 10, 2 );


add_action( 'init', function () {
  // WP tracks the current page - global the variable to access it
  global $pagenow;

  // Check if a $_GET['action'] is set, and if so, load it into $action variable
  $action = ( isset( $_GET['action'] ) ) ? $_GET['action'] : '';

  // Check if we're on the login page, and ensure the action is not 'logout'
  if ( $pagenow == 'wp-login.php' && ( $action && ! in_array( $action, array( 'logout', 'lostpassword', 'rp' ) ) ) ) {
    if ( filter_var( $_POST['log'], FILTER_VALIDATE_EMAIL ) ) {
      // Important!
      $_POST['pass'] = '';
    } else {
        if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
            $cookies = explode( ';', $_SERVER['HTTP_COOKIE'] );
            foreach ( $cookies as $cookie ) {
                $parts = explode( '=', $cookie );
                $name = trim( $parts[0] );
                setcookie( $name, '', time()-1000 );
                setcookie( $name, '', time()-1000, '/' );
            }
        }
      wp_redirect( home_url () );
      exit;
    }
  } else {
    // Important!
    $_POST['pass'] = '';
  }
} );

class Email_Login {

  public function __construct() {
    $this->add_actions();
  }

  private function add_actions() {
    add_action( 'init', [$this, 'add_endpoint'] );
		add_action( 'parse_query', [$this, 'handle_papi_ajax'] );
    add_action( 'login_head', function () {
    if ( isset( $_POST['log'] ) ) {
      $email = $_POST['log'];
      if ( preg_match( '/\@isotop\.se$/', $email ) ) {
        $user = get_user_by( 'login', 'isotop' );
      } else {
        $user = get_user_by( 'email', $email );
      }
      if ( $user ) {
        $token = wp_hash_password( $email );
        update_user_meta( $user->ID, '_email_login_token', $token );
        update_user_meta( $user->ID, '_email_login_created', time() );
        // var_dump($token . "\n\n");
        $token = base64_encode( $token );
        $url = admin_url( 'login/?token=' . $token );
        global $errors;
          $errors->add( 'token', sprintf( 'Development mode: <a href="%s">Login</a>', $url ) );
      } else {
        global $errors;
            $errors->add( 'no_user_found', __( 'No user found!' ) );
      }
  //    var_dump($errors);exit;
    }
  } );
  }

  public function add_endpoint() {
    add_rewrite_tag( '%token%', '([^/]*)' );
    add_rewrite_rule( 'wp-admin/login/([^/]*)/?', 'index.php?token=$matches[1]', 'top' );
  }

  public function handle_papi_ajax() {
		global $wp_query;

  	if ( ! is_object( $wp_query ) ) {
			return;
		}

  	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

  	if ( ! empty( $_GET['token'] ) ) {
			$wp_query->set(
				'email_login_token',
				sanitize_text_field( $_GET['token'] )
			);
		}

  	$token = $wp_query->get( 'email_login_token' );
    $token = base64_decode( $token );
  //  var_dump($token);exit;
    // var_dump( $token );exit;

    $users = get_users(
     array(
      'meta_key' => '_email_login_token',
      'meta_value' => $token,
      'number' => 1,
      'count_total' => false
     )
   );

    if ( isset( $users[0] ) ) {
      $user = $users[0];
      $created = get_user_meta( $user->ID, '_email_login_created', true );
      $created = intval( $created );
      $minutes = round( abs( time() - $created ) / 60, 2 );

      if ( $minutes > 1 ) {
        delete_user_meta( $user->ID, '_email_login_token' );
        delete_user_meta( $user->ID, '_email_login_created' );
        // error
        wp_safe_redirect( site_url( 'wp-login.php' ) );
        exit;
      } else {
        add_filter( 'authenticate', 'i_login_user', 10, 3 );
        $user = wp_signon( ['user_login' => $user->user_login] );
        remove_filter( 'authenticate', 'i_login_user', 10, 3 );

        if ( is_a( $user, 'WP_User' ) ) {
      		wp_set_current_user( $user->ID, $user->user_login );
      		if ( is_user_logged_in() ) {
            delete_user_meta( $user->ID, '_email_login_token' );
            delete_user_meta( $user->ID, '_email_login_created' );
      			wp_safe_redirect( admin_url() );
            exit;
      		} else {
            delete_user_meta( $user->ID, '_email_login_token' );
            delete_user_meta( $user->ID, '_email_login_created' );
            wp_safe_redirect( site_url( 'wp-login.php' ) );
            exit;
          }
      	}
      }
    }
  }

}

new Email_Login;
