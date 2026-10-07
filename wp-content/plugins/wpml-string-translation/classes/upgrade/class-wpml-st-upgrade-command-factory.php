<?php

use function WPML\Container\make;
use WPML\ST\Upgrade\Command\RegenerateMoFilesWithStringNames;
use WPML\ST\Upgrade\Command\MigrateMultilingualWidgets;
use WPML\ST\Upgrade\Command\UpgradeWpSettingsStrings;
use WPML\ST\Upgrade\Command\DeleteFileHashingOption;

class WPML_ST_Upgrade_Command_Factory {
	private $wpdb;

	private $sitepress;

	public function __construct( wpdb $wpdb, SitePress $sitepress ) {
		$this->wpdb      = $wpdb;
		$this->sitepress = $sitepress;
	}

	public function create( $class_name ) {
		switch ( $class_name ) {
			case 'WPML_ST_Upgrade_Migrate_Originals':
				$result = new WPML_ST_Upgrade_Migrate_Originals( $this->wpdb, $this->sitepress );
				break;
			case 'WPML_ST_Upgrade_Display_Strings_Scan_Notices':
				$themes_and_plugins_settings = new WPML_ST_Themes_And_Plugins_Settings();
				$result                      = new WPML_ST_Upgrade_Display_Strings_Scan_Notices( $themes_and_plugins_settings );
				break;
			case 'WPML_ST_Upgrade_DB_String_Packages':
				$result = new WPML_ST_Upgrade_DB_String_Packages( $this->wpdb );
				break;
			case 'WPML_ST_Upgrade_MO_Scanning':
				$result = new WPML_ST_Upgrade_MO_Scanning( $this->wpdb );
				break;
			case 'WPML_ST_Upgrade_DB_String_Name_Index':
				$result = new WPML_ST_Upgrade_DB_String_Name_Index( $this->wpdb );
				break;
			case 'WPML_ST_Upgrade_DB_Longtext_String_Value':
				$result = new WPML_ST_Upgrade_DB_Longtext_String_Value( $this->wpdb );
				break;
			case 'WPML_ST_Upgrade_DB_Strings_Add_Translation_Priority_Field':
				$result = new WPML_ST_Upgrade_DB_Strings_Add_Translation_Priority_Field( $this->wpdb );
				break;
			case 'WPML_ST_Upgrade_DB_String_Packages_Word_Count':
				$result = new WPML_ST_Upgrade_DB_String_Packages_Word_Count( wpml_get_upgrade_schema() );
				break;
			case 'WPML_ST_Upgrade_DB_String_Packages_Translator_Note':
				$result = new WPML_ST_Upgrade_DB_String_Packages_Translator_Note( wpml_get_upgrade_schema() );
				break;
			case '\WPML\ST\Upgrade\Command\RegenerateMoFilesWithStringNames':
				$isBackground = true;
				$result       = new RegenerateMoFilesWithStringNames(
					\WPML\ST\MO\Generate\Process\ProcessFactory::createStatus( $isBackground ),
					\WPML\ST\MO\Generate\Process\ProcessFactory::createSingle( $isBackground )
				);
				break;
			case 'WPML\ST\Upgrade\Command\UpgradeAutoregisteringStrings':
				$result = new \WPML\ST\Upgrade\Command\UpgradeAutoregisteringStrings( $this->wpdb, $this->sitepress );
				break;
			case MigrateMultilingualWidgets::class:
				$result = new MigrateMultilingualWidgets();
				break;
			case UpgradeWpSettingsStrings::class:
				$result = new UpgradeWpSettingsStrings();
				break;
			case DeleteFileHashingOption::class:
				$result = new DeleteFileHashingOption();
				break;
			default:
				throw new WPML_ST_Upgrade_Command_Not_Found_Exception( $class_name );
		}

		return $result;
	}
}
