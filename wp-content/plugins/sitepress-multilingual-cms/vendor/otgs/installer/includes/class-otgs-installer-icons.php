<?php

class OTGS_Installer_Icons {

	private $installer;

	public function __construct( WP_Installer $installer ) {
		$this->installer = $installer;
	}

	public function add_hooks() {
		add_filter( 'otgs_installer_upgrade_check_response', array( $this, 'add_icons_on_response' ), 10, 2 );
	}

	public function add_icons_on_response( $response, $name ) {
		$repositories = array_keys( $this->installer->get_repositories() );
		$product = '';
		$repository = '';
		$settings = $this->installer->get_settings();

		foreach( $repositories as $repository_id ) {
			if ( isset( $settings['repositories'][ $repository_id ]['data']['products-map'][ $response->plugin ] ) ) {
				$product = $settings['repositories'][ $repository_id ]['data']['products-map'][ $response->plugin ];
				$repository = $repository_id;
				break;
			} elseif ( isset( $settings['repositories'][ $repository_id ]['data']['products-map'][ $name ] ) ) {
				$product = $settings['repositories'][ $repository_id ]['data']['products-map'][ $name ];
				$repository = $repository_id;
				break;
			}
		}

		if ( $product && $repository ) {
			$base            = $this->installer->plugin_url() . '/../icons/plugin-icons/' . $repository . '/' . $product . '/icon';
			$response->icons = array(
				'svg' => $base . '.svg',
				'1x'  => $base . '-128x128.png',
				'2x'  => $base . '-256x256.png',
			);
		}

		return $response;
	}
}
