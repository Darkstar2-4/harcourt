<?php

namespace WPML\classes\wizard;

class WPML_Reset_Wizard_Ajax_Hooks
{
	public function add_hooks()
	{
		add_action('wp_ajax_reset_wpml_wizard', [$this, 'handle_reset_wizard_ajax']);
	}

	public function handle_reset_wizard_ajax() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if (!wp_verify_nonce($nonce, 'reset_wpml_wizard')) {
			wp_send_json_error([
				'message' => __('Invalid security token.', 'sitepress'),
			]);
			exit;
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'sitepress'),
			]);
			exit;
		}


		$reset_wizard = new WPML_Reset_Wizard();
		$result       = $reset_wizard->execute();

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error([
				'message' => __('Failed to reset the wizard. Please try again.', 'sitepress'),
			]);
		}

		exit;
	}
}