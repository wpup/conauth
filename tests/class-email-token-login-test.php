<?php

use Frozzare\Email_Token_Login\Email_Token_Login;

class WP_Test_Suite_Test extends \WP_UnitTestCase {

	public function setUp() {
		parent::setUp();
		$this->login = Email_Token_Login::instance();
	}

	public function tearDown() {
		parent::tearDown();
		unset( $this->login );
	}

	public function test_instance() {
		$this->assertInstanceOf(
			'\Frozzare\Email_Token_Login\Email_Token_Login',
			Email_Token_Login::instance()
		);
	}

	public function test_actions() {
		$this->assertSame( 10, has_action( 'login_head', [$this->login, 'login_head'] ) );
		$this->assertSame( 10, has_action( 'login_init', [$this->login, 'generate_token'] ) );
		$this->assertSame( 10, has_action( 'login_init', [$this->login, 'login_user'] ) );
	}

	public function test_login_user_no_token() {
		$_GET['token'] = null;
		$this->assertNull( $this->login->login_user() );
		unset( $_GET['token'] );
	}

	public function test_login_user_standalone() {
		$this->assertNull( $this->login->login_user() );
	}

	public function test_generate_token() {
		global $errors;
		$errors = new WP_Error;

		$user_id = $this->factory->user->create();
		$user = get_user_by( 'id', $user_id );
		$_POST['log'] = $user->user_email;

		tests_add_filter( 'wp_mail', function ( $atts ) {
			preg_match( '/token\=(\w+)/', $atts['message'], $matches );
			if ( empty( $matches ) ) {
				$this->assertFalse( true );
			} else {
				$this->assertNotEmpty( $matches[1] );
			}
		} );

		$this->login->generate_token();
		$messages = $errors->get_error_messages();

		$this->assertSame(
			'We sent you a link to sign in. Please check your inbox.',
			$messages[0]
		);

		unset( $_POST['log'] );
		$errors = null;
	}

	public function test_generate_token_fake_user() {
		global $errors;
		$errors = new WP_Error;
		$_POST['log'] = 'fake@fake.local';

		$this->login->generate_token();
		$messages = $errors->get_error_messages();

		$this->assertSame(
			'Something went wrong. Please try again.',
			$messages[0]
		);

		unset( $_POST['log'] );
		$errors = null;
	}

	public function test_generate_token_dev_mode() {
		global $errors;
		$errors = new WP_Error;

		$user_id = $this->factory->user->create();
		$user = get_user_by( 'id', $user_id );
		$_POST['log'] = $user->user_email;

		tests_add_filter( 'email_token_login/dev_mode', '__return_true' );

		$this->login->generate_token();
		$messages = $errors->get_error_messages();

		$this->assertTrue( (bool) preg_match( '/Development\smode/', $messages[0] ) );

		unset( $_POST['log'] );
		$errors = null;
	}
}
