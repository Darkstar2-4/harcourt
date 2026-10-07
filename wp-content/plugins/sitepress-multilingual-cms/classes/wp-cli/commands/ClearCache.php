<?php
namespace WPML\CLI\Core\Commands;

class ClearCache implements ICommand {

	private $cache_directory;

	public function __construct( \WPML_Cache_Directory $cache_directory ) {
		$this->cache_directory = $cache_directory;
	}

	public function __invoke( $args, $assoc_args ) {
		icl_cache_clear();
		$this->cache_directory->remove();
		\WPML_Translation_Roles_Records::delete_cache();

		\WP_CLI::success( 'WPML cache cleared' );
	}

	public function get_command() {
		return 'clear-cache';
	}

}