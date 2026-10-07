<?php
namespace Composer\Installers;

use Composer\Package\PackageInterface;

class OxidInstaller extends BaseInstaller
{
	const VENDOR_PATTERN = '/^modules\/(?P<vendor>.+)\/.+/';

    protected $locations = array(
        'module'    => 'modules/{$name}/',
        'theme'  => 'application/views/{$name}/',
        'out'    => 'out/{$name}/',
    );

	public function getInstallPath(PackageInterface $package, $frameworkType = '')
	{
		$installPath = parent::getInstallPath($package, $frameworkType);
		$type = $this->package->getType();
		if ($type === 'oxid-module') {
			$this->prepareVendorDirectory($installPath);
		}
		return $installPath;
	}

	protected function prepareVendorDirectory($installPath)
	{
		$matches = '';
		$hasVendorDirectory = preg_match(self::VENDOR_PATTERN, $installPath, $matches);
		if (!$hasVendorDirectory) {
			return;
		}

		$vendorDirectory = $matches['vendor'];
		$vendorPath = getcwd() . '/modules/' . $vendorDirectory;
		if (!file_exists($vendorPath)) {
			mkdir($vendorPath, 0755, true);
		}

		$vendorMetaDataPath = $vendorPath . '/vendormetadata.php';
		touch($vendorMetaDataPath);
	}
}
