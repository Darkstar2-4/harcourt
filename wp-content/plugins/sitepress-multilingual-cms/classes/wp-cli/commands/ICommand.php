<?php
namespace WPML\CLI\Core\Commands;

interface ICommand {

	public function __invoke( $args, $assoc_args );

	public function get_command();
}