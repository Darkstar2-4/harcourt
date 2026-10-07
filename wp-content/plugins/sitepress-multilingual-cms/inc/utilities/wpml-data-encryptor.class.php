<?php

class WPML_Data_Encryptor {

	const SALT_CHARS  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
	const SALT_LENGTH = 64;

	private $method;
	private $key;
	private $iv;

	private $library = false;

	public function __construct( $key_salt = '', $method = 'AES-256-CTR' ) {

		if ( ! $key_salt ) {
			$key_salt = $this->get_key_salt();
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' )
			 && version_compare( phpversion(), '5.3.2', '>' ) ) {

			$methods = openssl_get_cipher_methods();
			if ( ! in_array( $method, $methods ) && ! empty( $methods ) ) {
				$this->method = $methods[0];
			} else {
				$this->method = $method;
			}
			$this->library = 'openssl';
			$this->key     = substr( sha1( $key_salt, true ), 0, 16 );
			$this->iv      = substr( $key_salt, 0, 16 );

		} elseif ( function_exists( 'mcrypt_encrypt' ) && function_exists( 'mcrypt_decrypt' ) ) {
			$this->library = 'mcrypt';
			$this->key     = substr( NONCE_KEY, 0, 24 );
			$this->iv      = mcrypt_create_iv( mcrypt_get_iv_size( MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB ), MCRYPT_RAND );

		}
	}

	public function encrypt( $data ) {
		if ( $this->library === 'openssl' ) {
			$encrypted_data = openssl_encrypt( $data, $this->method, $this->key, OPENSSL_RAW_DATA, $this->iv );
		} elseif ( $this->library === 'mcrypt' ) {
			$encrypted_data = mcrypt_encrypt( MCRYPT_RIJNDAEL_256, $this->key, $data, MCRYPT_MODE_ECB, $this->iv );
			$encrypted_data = preg_replace( '/\x00/', '', $encrypted_data );
		} else {
			$encrypted_data = $data;
		}

		return $encrypted_data;
	}

	public function decrypt( $encrypted_data ) {

		if ( $this->library === 'openssl' ) {
			$data = openssl_decrypt( $encrypted_data, $this->method, $this->key, OPENSSL_RAW_DATA, $this->iv );
		} elseif ( $this->library === 'mcrypt' ) {
			$data = mcrypt_decrypt( MCRYPT_RIJNDAEL_256, $this->key, $encrypted_data, MCRYPT_MODE_ECB, $this->iv );
			$data = preg_replace( '/\x00/', '', $data );
		} else {
			$data = $encrypted_data;
		}

		return $data;
	}

	public function set_crypt_library( $library ) {
		$this->library = $library;
	}

	public function get_crypt_library() {
		return $this->library;
	}

	private function get_key_salt() {
		if ( defined( 'NONCE_SALT' ) ) {
			return NONCE_SALT;
		}

		return $this->generate_salt_key();
	}

	private function generate_salt_key() {
		$salt_key = '';
		for ( $i = 0; $i < self::SALT_LENGTH; $i++ ) {
			$salt_key .= substr( self::SALT_CHARS, mt_rand( 0, strlen( self::SALT_CHARS ) - 1 ), 1 );
		}

		return $salt_key;
	}
}
