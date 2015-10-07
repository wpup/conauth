<?php

namespace Frozzare\Conauth;

use WP_Error;
use WP_User;

class Conauth {

	/**
	 * The instance of this class.
	 *
	 * @var Conauth
	 */
    private static $instance;

	/**
	 * Created meta key.
	 *
	 * @var string
	 */
    protected $created_meta_key = '_conauth_token_created';

	/**
	 * Token meta key.
	 *
	 * @var string
	 */
    protected $token_meta_key = '_conauth_token';

	/**
	 * Get the instance of this class.
	 *
	 * @return Conauth
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
	 * Auth user.
	 *
	 * @param  WP_User $user
	 * @param  string  $username
	 * @param  string  $password
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
	 * @param  string $untranslated_text
	 * @param  string $domain
	 *
	 * @return string
	 */
    public function get_email_label( $translated_text, $untranslated_text ) {
		global $pagenow;

        if ( $pagenow === 'wp-login.php' && $untranslated_text === 'Username'  ) {
            remove_filter( current_filter(), [$this, 'get_email_label'], 99, 2 );
            return __( 'E-mail', 'conauth' );
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
		/**
		 * Change mail body.
		 *
		 * @param string $url
		 */
		$body = apply_filters( 'conauth/mail_body', $url );

        // Don't return a mail body with only the url.
        if ( ! empty( $body ) && $body !== $url ) {
            return $body;
        }

        $ob_level = ob_get_level();
		ob_start();

        try {
            require_once __DIR__ . '/views/mail.php';
		} catch ( Exception $e ) {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

        return trim( ob_get_clean() );
    }

	/**
	 * Get mail title.
	 *
	 * @return string
	 */
    protected function get_mail_title() {
		$title = sprintf(
			__( 'Sign in to %s', 'conauth' ),
			get_bloginfo( 'name' )
		);

		/**
		 * Change mail title.
		 *
		 * @param string $title
		 */
		return apply_filters( 'conauth/mail_title', $title );
    }

	/**
	 * Generate token mail if a user is found
	 * or create a error.
	 */
    public function generate_token() {
        if ( isset( $_POST['log'] ) ) {
			global $errors;

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
                $url = sprintf( '%s?token=%s', site_url( 'wp-login.php', 'login' ), $token );

                // Send mail!
                $mail_sent = wp_mail( $email, wp_specialchars_decode( $this->get_mail_title() ), $this->get_mail_body( $url ) );

                // If `WP_ENV` is used and in development Conauth is in dev mode.
                $couch_mode = defined( 'WP_ENV' ) && WP_ENV === 'development';

                /**
                 * Output login link as a message on `wp-login.php`.
                 *
                 * @param bool $debug
                 */
                $couch_mode = apply_filters( 'conauth/couch_mode', $couch_mode );

                if ( $couch_mode ) {
                    $errors->add(
                        'conauth_info',
                        sprintf( '%s: <a href="%s">%s</a>', __( 'Couch Mode', 'conauth' ), $url, __( 'Click to log in', 'conauth' ) ),
                        'message'
                    );
                } else {
                    if ( $mail_sent ) {
                        $errors->add(
                            'conauth_info',
                            __( 'We sent you a link to sign in. Please check your inbox.', 'conauth' ),
                            'message'
                        );
                    } else {
                        $errors->add(
                            'conauth_error',
                            __( 'The email could not be sent. Possible reason: your host may have disabled the mail() function.', 'conauth' )
                        );
                    }
                }
            } else {
                $errors->add(
                    'conauth_error',
                    __( 'Something went wrong. Please try again.', 'conauth' )
                );
            }
        }
    }

	/**
	 * Get shared users.
	 *
	 * @return array
	 */
    protected function get_shared_users() {
		/**
		 * Get shared top domains associated with users.
		 *
		 * @var array
		 */
		$shared = apply_filters( 'conauth/shared', [] );

        foreach ( $shared as $domain => $username ) {
            $username = is_array( $username ) ? $username : [$username];

            if ( ! preg_match( '/\@\w+\.\w+/', '@' . $domain ) || ! username_exists( $username ) ) {
                unset( $users[$domain] );
            }
        }

        return $shared;
    }

	/**
	 * Handle endpoint.
	 */
    public function login_user() {
		// No ajax login is supported at the moment.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

		// Get the token.
        $token = isset( $_GET['token'] ) ?
			esc_attr( wp_unslash( $_GET['token'] ) ) : '';

		// No token, no login!
        if ( empty( $token ) ) {
            return;
        }

		// Base64 is not used for encryption, it's used
		// to get a nicer url token.
        $token = base64_decode( $token );
        $user  = $this->find_user( $token );

        // No user errror.
        if ( empty( $user ) ) {
            global $errors;
            $errors->add(
                'conauth_error',
                __( 'The sign-in link expired. Please try again.', 'conauth' )
            );
            return;
        }

		// Don't continue if the user is not valid.
        if ( ! $this->valid_user( $user ) ) {
            global $errors;
            $errors->add(
                'conauth_error',
                __( 'The sign-in link expired. Please try again.', 'conauth' )
            );
            return;
        }

		// Hack to login a user with no password.
        add_filter( 'authenticate', [$this, 'auth_user'], 10, 3 );
        $user = wp_signon( ['user_login' => $user->user_login] );
        remove_filter( 'authenticate', [$this, 'auth_user'], 10, 3 );

		// Don't continue if the user is not valid.
        if ( $user instanceof WP_User === false ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

		// Clean users meta values.
        $this->clean_user( $user );

		// Set current user.
        wp_set_current_user( $user->ID, $user->user_login );

		// Determine if the user is logged in or not.
        if ( is_user_logged_in() ) {
            wp_safe_redirect( admin_url() );
        } else {
            wp_safe_redirect( wp_login_url() );
        }

        exit;
    }

	/**
	 * Output CSS on login page.
	 * The CSS will only hide elements.
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
     * Set mail content type.
     *
     * @return string
     */
    public function set_mail_content_type() {
        return 'text/html';
    }

	/**
	 * Setup actions.
	 */
    protected function setup_actions() {
        add_action( 'login_head', [$this, 'login_head'] );
        add_action( 'login_head', [$this, 'generate_token'] );
        add_action( 'login_head', [$this, 'login_user'] );
    }

	/**
	 * Setup filters.
	 */
    protected function setup_filters() {
        add_filter( 'gettext', [$this, 'get_email_label'], 99, 2 );
        add_filter( 'wp_login_errors', [$this, 'wp_login_errors'], 10, 0 );
        add_filter( 'wp_mail_content_type', [$this, 'set_mail_content_type'] );
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
        return $minutes <= apply_filters( 'conauth/minutes', 15 );
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
