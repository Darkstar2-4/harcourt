<?php

namespace WPML\ATE\Proxies;

use WPML\API\Sanitize;
use WPML\LIB\WP\User;

class Proxy implements \IWPML_Frontend_Action, \IWPML_DIC_Action
{
	const QUERY_VAR_ATE_WIDGET_SCRIPT = null;
	const SCRIPT_NAME                 = null;

	public function add_hooks()
	{
		add_action(
			'template_redirect',
			function () {
				$script = $this->get_script();
				if ($script) {
					while ( ob_get_level() > 0 ) {
						ob_end_clean();
					}
					include $script;
					die();
				}
			},
			-PHP_INT_MAX
		);
	}

	public function get_script()
	{
		if (! User::canManageTranslations()) {
			return false;
		}

		$app = Sanitize::stringProp(static::QUERY_VAR_ATE_WIDGET_SCRIPT, $_GET);

		if (! $this->showScript($app)) {
			return false;
		}

		$script = WPML_TM_PATH . '/res/js/' . static::SCRIPT_NAME . '.php';
		return file_exists($script)
			? $script
			: false;
	}

	protected function showScript($app)
	{
		return static::SCRIPT_NAME === $app;
	}
}
