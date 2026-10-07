<?php

namespace WPML\XMLConfig\RemoteNotices;

class Conditions {

	private $activePlugins;

	private $activePluginNames;

	private $themeName;

	private $themeParentName;

	public function __construct( \WPML_Active_Plugin_Provider $activePlugins ) {
		$this->activePlugins = $activePlugins;
	}

	public function meetConditions( $conditions ) {
		$relation = $conditions['relation'] ?? 'AND';

		$results = array_merge(
			self::getPluginResults( $conditions['plugin'] ?? [] ),
			self::getThemeResults( $conditions['theme'] ?? [] )
		);

		if ( isset( $conditions['conditions'] ) && is_array( $conditions['conditions'] ) ) {
			foreach ( $conditions['conditions'] as $conditions ) {
				$results = array_merge(
					$results,
					[ $this->meetConditions( $conditions ) ]
				);
			}
		}

		if ( empty( $results ) ) {
			return true;
		}

		if ( 'AND' === $relation ) {
			return ! in_array( false, $results, true );
		} else {
			return in_array( true, $results, true );
		}
	}

	private function getPluginResults( $plugins ) {
		return array_map( [ $this, 'isPluginActive' ], $plugins );
	}

	private function isPluginActive( $pluginName ) {
		if ( null === $this->activePluginNames ) {
			$this->activePluginNames = $this->activePlugins->get_active_plugin_names();
		}

		return in_array( $pluginName, $this->activePluginNames, true );
	}

	private function getThemeResults( $themes ) {
		if ( ! $themes ) {
			return [];
		}

		if ( null === $this->themeName ) {
			$this->themeName       = wp_get_theme()->get( 'Name' );
			$this->themeParentName = wp_get_theme()->get( 'Template' );
		}

		$hasTheme = in_array( $this->themeName, $themes, true )
			|| in_array( $this->themeParentName, $themes, true );

		return [ $hasTheme ];
	}
}
