<?php

namespace WPML\CLI\Core;

use WPML\CLI\Core\Commands\ClearCacheFactory;
use WPML\CLI\Core\Commands\ICommand;

class BootStrap {
	const MAIN_COMMAND = 'wpml';

	public function init() {
		$commands_factory = [
			ClearCacheFactory::class,
		];

		foreach ( $commands_factory as $command_factory ) {
			$command_factory_obj = new $command_factory();

			$command = $command_factory_obj->create();

			$this->add_command( $this->getFullCommand( $command ), $command );
		}
	}

	private function add_command( $command_text, $command ) {
		\WP_CLI::add_command( $command_text, $command );
	}

	private function getFullCommand( $command ) {
		return trim( self::MAIN_COMMAND . ' ' . $command->get_command() );
	}
}
