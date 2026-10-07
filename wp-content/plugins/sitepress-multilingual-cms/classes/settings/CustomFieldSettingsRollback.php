<?php

namespace WPML\TM\Settings;

class CustomFieldSettingsRollback {

	const MIGRATED_FLAG = 'wpml_meta_settings_migrated';
	const STARTED_FLAG  = 'wpml_meta_settings_migration_started';

	const BLOB_KEYS = [
		'post' => 'custom_fields_translation',
		'term' => 'custom_term_fields_translation',
	];

	public static function restoreIfNeeded(): void {
		if ( ! self::siteCameBackFromWpml5() ) {
			return;
		}

		if ( self::storeTableExists() && ! self::copyMapsBackIntoSettings() ) {
			return;
		}

		self::clearWpml5Flags();
	}

	private static function siteCameBackFromWpml5(): bool {
		return (bool) get_option( self::MIGRATED_FLAG );
	}

	private static function storeTableExists(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', self::storeTableName() )
		);
	}

	private static function copyMapsBackIntoSettings(): bool {
		$settings = get_option( 'icl_sitepress_settings' );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		if ( ! isset( $settings['translation-management'] ) || ! is_array( $settings['translation-management'] ) ) {
			$settings['translation-management'] = [];
		}

		$maps = [];
		foreach ( self::BLOB_KEYS as $type => $blobKey ) {
			$maps[ $blobKey ]                               = self::readMapFromStore( $type );
			$settings['translation-management'][ $blobKey ] = $maps[ $blobKey ];
		}

		update_option( 'icl_sitepress_settings', $settings );

		return self::settingsContainMaps( $maps );
	}

	private static function readMapFromStore( string $type ): array {
		global $wpdb;

		$map   = [];
		$table = self::storeTableName();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT name, mode FROM `{$table}` WHERE element_type = %s", $type ),
			ARRAY_N
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ $row[0] ] = (int) $row[1];
			}
		}

		return $map;
	}

	private static function settingsContainMaps( array $maps ): bool {
		$persisted = get_option( 'icl_sitepress_settings' );
		foreach ( $maps as $blobKey => $expected ) {
			$written = isset( $persisted['translation-management'][ $blobKey ] )
				? $persisted['translation-management'][ $blobKey ]
				: null;
			if ( $written !== $expected ) {
				return false;
			}
		}

		return true;
	}

	private static function clearWpml5Flags(): void {
		delete_option( self::MIGRATED_FLAG );
		delete_option( self::STARTED_FLAG );
	}

	private static function storeTableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'icl_meta_settings';
	}
}
