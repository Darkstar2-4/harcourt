<?php

class OTGS_Installer_Plugin_Finder {

	private $plugin_factory;
	private $installer;
	private $_repositories;
	private $_plugins;

	private $installed_plugins;

	public function __construct( OTGS_Installer_Plugin_Factory $plugin_factory, $installer = null ) {
		$this->plugin_factory = $plugin_factory;
		$this->installer      = $installer && $installer instanceof WP_Installer ? $installer : null;
	}

	private function repositories() {
		if ( null === $this->_repositories ) {
			$installer           = $this->installer ?: OTGS_Installer();
			$settings            = $installer->get_settings() ?? [];
			$this->_repositories = $settings['repositories'];
		}

		return $this->_repositories;
	}

	private function plugins() {
		if ( null === $this->_plugins ) {
			$this->_plugins = array();
			foreach ( $this->repositories() as $repo_key => $repository ) {
				foreach ( $repository['data']['downloads']['plugins'] as $slug => $plugin ) {
					$plugin_id = $this->get_installed_plugin_id_by_slug( $plugin['slug'] );

					if ( ! $plugin_id ) {
						$plugin_id = $this->get_installed_plugin_id_by_name( $plugin['name'] );
					}

					$this->_plugins[] = $this->plugin_factory->create( array(
						'name'              => $plugin['name'],
						'slug'              => $plugin['slug'],
						'description'       => $plugin['description'],
						'changelog'         => isset( $plugin['changelog'] ) ? $plugin['changelog'] : '',
						'version'           => $plugin['version'],
						'installed_version' => isset( $this->installed_plugins[ $plugin_id ]['Version'] ) ? $this->installed_plugins[ $plugin_id ]['Version'] : null ,
						'date'              => $plugin['date'],
						'url'               => $plugin['url'],
						'free_on_wporg'     => isset( $plugin['free-on-wporg'] ) ? $plugin['free-on-wporg'] : '',
						'fallback_on_wporg' => isset( $plugin['fallback-free-on-wporg'] ) ? $plugin['fallback-free-on-wporg'] : '',
						'basename'          => $plugin['basename'],
						'external_repo'     => isset( $plugin['external-repo'] ) ? $plugin['external-repo'] : '',
						'is_lite'           => isset( $plugin['is-lite'] ) ? $plugin['is-lite'] : '',
						'repo'              => $repo_key,
						'id'                => $plugin_id,
						'channel'           => isset( $plugin['channel'] ) ? $plugin['channel'] : '',
						'tested'            => isset( $plugin['tested'] ) ? $plugin['tested'] : null,
					) );
				}
			}
		}

		return $this->_plugins;
	}

	public function get_all() {
		return $this->plugins();
	}

	public function getLocalPluginVersions() {
		$versions = [];

		foreach ( $this->plugins() as $plugin ) {
			$installed_version = $plugin->get_installed_version();
			if ( $installed_version ) {
				$versions[ $plugin->get_slug() ] = $installed_version;
			}
		}

		return $versions;
	}

	public function get_otgs_installed_plugins_by_repository() {
		return $this->getOTGSInstalledPluginsByRepository();
	}

	public function getOTGSInstalledPluginsByRepository( $withActiveFlag = false, $withVersions = false ) {
		$installed_plugins = [];

		foreach ( $this->plugins() as $plugin ) {
			if ( $plugin->get_installed_version() ) {
				$pluginInfo = [
					'id'   => $plugin->get_id(),
					'slug' => $plugin->get_slug(),
					'name' => $plugin->get_name(),
				];

				if ( $withActiveFlag ) {
					$pluginInfo['active'] = is_plugin_active( $plugin->get_id() );
				}

				if ( $withVersions ) {
					$pluginInfo['current_version']   = $plugin->get_version();
					$pluginInfo['installed_version'] = $plugin->get_installed_version();
				}

				$installed_plugins[ $plugin->get_repo() ][] = $pluginInfo;
			}
		}

		return $installed_plugins;
	}

	public function get_plugin( $slug, $repo = '' ) {
		foreach ( $this->plugins() as $plugin ) {
			if ( $slug === $plugin->get_slug() ) {

				if ( ! $repo || $plugin->get_repo() === $repo ) {
					return $plugin;
				}
			}
		}

		return null;
	}

	public function get_plugin_by_name( $name ) {
		foreach ( $this->plugins() as $plugin ) {
			if ( $name === strip_tags( $plugin->get_name() ) ) {
				return $plugin;
			}
		}

		return null;
	}

	private function get_installed_plugin_id_by_slug( $slug ) {
		foreach ( $this->get_installed_plugins() as $plugin_id => $plugin ) {
			$plugin_slug = explode( '/', $plugin_id );

			if ( $slug === $plugin_slug[0] ) {
				return $plugin_id;
			}
		}

		return null;
	}

	private function get_installed_plugins() {
		if ( ! $this->installed_plugins ) {
			$this->installed_plugins = get_plugins();
		}

		return $this->installed_plugins;
	}

	private function get_installed_plugin_id_by_name( $name ) {
		$plugin_id = array_keys( wp_list_filter( $this->get_installed_plugins(), array( 'Name' => $name ) ) );

		return current( $plugin_id );
	}
}
