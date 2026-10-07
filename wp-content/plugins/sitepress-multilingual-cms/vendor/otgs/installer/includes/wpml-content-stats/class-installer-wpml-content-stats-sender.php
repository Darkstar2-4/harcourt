<?php

use OTGS\Installer\CDTClient\Api\Endpoints\v1\Requests\Actions\AddStats\Action as AddStatsAction;

class WPML_Content_Stats_Sender {

	private $installer;

	private $settings;

	private $action;

	public function __construct(
		WP_Installer $installer,
		OTGS_Installer_WP_Share_Local_Components_Setting $settings,
		\OTGS\Installer\CDTClient\Api\Endpoints\v1\Requests\Actions\AddStats\Action $action
	) {
		$this->installer = $installer;
		$this->settings  = $settings;
		$this->action    = $action;
	}

	public function send( array $data ) {
		if ( ! $this->installer->get_repositories() ) {
			$this->installer->load_repositories_list();
		}

		if ( ! $this->installer->get_settings() ) {
			$this->installer->save_settings();
		}

		if ( ! $this->settings->is_repo_allowed( 'wpml' ) ) {
			return false;
		}

		$response = $this->action->run( $data );

		return $response->isSuccessful();
	}

}
