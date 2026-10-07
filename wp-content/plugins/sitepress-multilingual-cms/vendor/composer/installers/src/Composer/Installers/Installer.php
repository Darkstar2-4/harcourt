<?php

namespace Composer\Installers;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

class Installer extends LibraryInstaller
{

    private $supportedTypes = array(
        'aimeos'       => 'AimeosInstaller',
        'asgard'       => 'AsgardInstaller',
        'attogram'     => 'AttogramInstaller',
        'agl'          => 'AglInstaller',
        'annotatecms'  => 'AnnotateCmsInstaller',
        'bitrix'       => 'BitrixInstaller',
        'bonefish'     => 'BonefishInstaller',
        'cakephp'      => 'CakePHPInstaller',
        'chef'         => 'ChefInstaller',
        'civicrm'      => 'CiviCrmInstaller',
        'ccframework'  => 'ClanCatsFrameworkInstaller',
        'cockpit'      => 'CockpitInstaller',
        'codeigniter'  => 'CodeIgniterInstaller',
        'concrete5'    => 'Concrete5Installer',
        'craft'        => 'CraftInstaller',
        'croogo'       => 'CroogoInstaller',
        'dframe'       => 'DframeInstaller',
        'dokuwiki'     => 'DokuWikiInstaller',
        'dolibarr'     => 'DolibarrInstaller',
        'decibel'      => 'DecibelInstaller',
        'drupal'       => 'DrupalInstaller',
        'elgg'         => 'ElggInstaller',
        'eliasis'      => 'EliasisInstaller',
        'ee3'          => 'ExpressionEngineInstaller',
        'ee2'          => 'ExpressionEngineInstaller',
        'ezplatform'   => 'EzPlatformInstaller',
        'fuel'         => 'FuelInstaller',
        'fuelphp'      => 'FuelphpInstaller',
        'grav'         => 'GravInstaller',
        'hurad'        => 'HuradInstaller',
        'tastyigniter' => 'TastyIgniterInstaller',
        'imagecms'     => 'ImageCMSInstaller',
        'itop'         => 'ItopInstaller',
        'joomla'       => 'JoomlaInstaller',
        'kanboard'     => 'KanboardInstaller',
        'kirby'        => 'KirbyInstaller',
        'known'	       => 'KnownInstaller',
        'kodicms'      => 'KodiCMSInstaller',
        'kohana'       => 'KohanaInstaller',
        'lms'      => 'LanManagementSystemInstaller',
        'laravel'      => 'LaravelInstaller',
        'lavalite'     => 'LavaLiteInstaller',
        'lithium'      => 'LithiumInstaller',
        'magento'      => 'MagentoInstaller',
        'majima'       => 'MajimaInstaller',
        'mantisbt'     => 'MantisBTInstaller',
        'mako'         => 'MakoInstaller',
        'maya'         => 'MayaInstaller',
        'mautic'       => 'MauticInstaller',
        'mediawiki'    => 'MediaWikiInstaller',
        'miaoxing'     => 'MiaoxingInstaller',
        'microweber'   => 'MicroweberInstaller',
        'modulework'   => 'MODULEWorkInstaller',
        'modx'         => 'ModxInstaller',
        'modxevo'      => 'MODXEvoInstaller',
        'moodle'       => 'MoodleInstaller',
        'october'      => 'OctoberInstaller',
        'ontowiki'     => 'OntoWikiInstaller',
        'oxid'         => 'OxidInstaller',
        'osclass'      => 'OsclassInstaller',
        'pxcms'        => 'PxcmsInstaller',
        'phpbb'        => 'PhpBBInstaller',
        'pimcore'      => 'PimcoreInstaller',
        'piwik'        => 'PiwikInstaller',
        'plentymarkets'=> 'PlentymarketsInstaller',
        'ppi'          => 'PPIInstaller',
        'puppet'       => 'PuppetInstaller',
        'radphp'       => 'RadPHPInstaller',
        'phifty'       => 'PhiftyInstaller',
        'porto'        => 'PortoInstaller',
        'processwire'  => 'ProcessWireInstaller',
        'quicksilver'  => 'PantheonInstaller',
        'redaxo'       => 'RedaxoInstaller',
        'redaxo5'      => 'Redaxo5Installer',
        'reindex'      => 'ReIndexInstaller',
        'roundcube'    => 'RoundcubeInstaller',
        'shopware'     => 'ShopwareInstaller',
        'sitedirect'   => 'SiteDirectInstaller',
        'silverstripe' => 'SilverStripeInstaller',
        'smf'          => 'SMFInstaller',
        'starbug'      => 'StarbugInstaller',
        'sydes'        => 'SyDESInstaller',
        'sylius'       => 'SyliusInstaller',
        'symfony1'     => 'Symfony1Installer',
        'tao'          => 'TaoInstaller',
        'thelia'       => 'TheliaInstaller',
        'tusk'         => 'TuskInstaller',
        'typo3-cms'    => 'TYPO3CmsInstaller',
        'typo3-flow'   => 'TYPO3FlowInstaller',
        'userfrosting' => 'UserFrostingInstaller',
        'vanilla'      => 'VanillaInstaller',
        'whmcs'        => 'WHMCSInstaller',
        'winter'       => 'WinterInstaller',
        'wolfcms'      => 'WolfCMSInstaller',
        'wordpress'    => 'WordPressInstaller',
        'yawik'        => 'YawikInstaller',
        'zend'         => 'ZendInstaller',
        'zikula'       => 'ZikulaInstaller',
        'prestashop'   => 'PrestashopInstaller'
    );

    public function __construct(
        IOInterface $io,
        Composer $composer,
        $type = 'library',
        Filesystem $filesystem = null,
        BinaryInstaller $binaryInstaller = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem,
            $binaryInstaller);
        $this->removeDisabledInstallers();
    }

    public function getInstallPath(PackageInterface $package)
    {
        $type = $package->getType();
        $frameworkType = $this->findFrameworkType($type);

        if ($frameworkType === false) {
            throw new \InvalidArgumentException(
                'Sorry the package type of this package is not yet supported.'
            );
        }

        $class = 'Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
        $installer = new $class($package, $this->composer, $this->getIO());

        return $installer->getInstallPath($package, $frameworkType);
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installPath = $this->getPackageBasePath($package);
        $io = $this->io;
        $outputStatus = function () use ($io, $installPath) {
            $io->write(sprintf('Deleting %s - %s', $installPath, !file_exists($installPath) ? '<comment>deleted</comment>' : '<error>not deleted</error>'));
        };

        $promise = parent::uninstall($repo, $package);

        if ($promise instanceof PromiseInterface) {
            return $promise->then($outputStatus);
        }

        $outputStatus();

        return null;
    }

    public function supports($packageType)
    {
        $frameworkType = $this->findFrameworkType($packageType);

        if ($frameworkType === false) {
            return false;
        }

        $locationPattern = $this->getLocationPattern($frameworkType);

        return preg_match('#' . $frameworkType . '-' . $locationPattern . '#', $packageType, $matches) === 1;
    }

    protected function findFrameworkType($type)
    {
        krsort($this->supportedTypes);

        foreach ($this->supportedTypes as $key => $val) {
            if ($key === substr($type, 0, strlen($key))) {
                return substr($type, 0, strlen($key));
            }
        }

        return false;
    }

    protected function getLocationPattern($frameworkType)
    {
        $pattern = false;
        if (!empty($this->supportedTypes[$frameworkType])) {
            $frameworkClass = 'Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
            $framework = new $frameworkClass(null, $this->composer, $this->getIO());
            $locations = array_keys($framework->getLocations());
            $pattern = $locations ? '(' . implode('|', $locations) . ')' : false;
        }

        return $pattern ? : '(\w+)';
    }

    private function getIO()
    {
        return $this->io;
    }

    protected function removeDisabledInstallers()
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (!isset($extra['installer-disable']) || $extra['installer-disable'] === false) {
            return;
        }

        $disable = $extra['installer-disable'];

        if (!is_array($disable)) {
            $disable = array($disable);
        }

        $all = array(true, "all", "*");
        $intersect = array_intersect($all, $disable);
        if (!empty($intersect)) {
            $this->supportedTypes = array();
        } else {
            foreach ($disable as $key => $installer) {
                if (is_string($installer) && key_exists($installer, $this->supportedTypes)) {
                    unset($this->supportedTypes[$installer]);
                }
            }
        }
    }
}
