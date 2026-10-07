<?php

class Translation_Service_Info {

	public function add_hooks() {

		add_action( 'installer_fetched_subscription_data', array( $this, 'save_info' ), 10, 2 );

	}

	public function save_info( $data, $repository_id ) {
		$installer = WP_Installer::instance();
		$settings = $installer->get_settings();

		$ts_info = isset( $settings['repositories'][ $repository_id ]['ts_info'] ) ?
			$settings['repositories'][ $repository_id ]['ts_info'] : false;

		$save_settings = false;

		if ( isset( $data->ts_info['preferred'] ) && empty( $ts_info['preferred'] ) ) {
			$settings['repositories'][ $repository_id ]['ts_info']['preferred'] = $data->ts_info['preferred'];

			$save_settings = true;
		}

		if ( isset( $data->ts_info['referal'] ) && empty( $ts_info['referal'] ) ) {
			$settings['repositories'][ $repository_id ]['ts_info']['referal'] = $data->ts_info['referal'];

			$save_settings = true;
		}

		if ( ! empty( $data->ts_info['client_id'] ) ) {
			$settings['repositories'][ $repository_id ]['ts_info']['client_id'] = $data->ts_info['client_id'];

			$save_settings = true;
		}

		if ( $save_settings ) {
			$installer->save_settings( $settings );
		}

	}

}

