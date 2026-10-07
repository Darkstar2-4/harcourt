<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Util;

use WPML\Core\Twig\Environment;
use WPML\Core\Twig\Error\SyntaxError;
use WPML\Core\Twig\Source;
class DeprecationCollector
{
    private $twig;
    private $deprecations;
    public function __construct(\WPML\Core\Twig\Environment $twig)
    {
        $this->twig = $twig;
    }
    public function collectDir($dir, $ext = '.twig')
    {
        $iterator = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir), \RecursiveIteratorIterator::LEAVES_ONLY), '{' . \preg_quote($ext) . '$}');
        return $this->collect(new \WPML\Core\Twig\Util\TemplateDirIterator($iterator));
    }
    public function collect(\Traversable $iterator)
    {
        $this->deprecations = [];
        \set_error_handler([$this, 'errorHandler']);
        foreach ($iterator as $name => $contents) {
            try {
                $this->twig->parse($this->twig->tokenize(new \WPML\Core\Twig\Source($contents, $name)));
            } catch (\WPML\Core\Twig\Error\SyntaxError $e) {
            }
        }
        \restore_error_handler();
        $deprecations = $this->deprecations;
        $this->deprecations = [];
        return $deprecations;
    }
    public function errorHandler($type, $msg)
    {
        if (\E_USER_DEPRECATED === $type) {
            $this->deprecations[] = $msg;
        }
    }
}
\class_alias('WPML\\Core\\Twig\\Util\\DeprecationCollector', 'WPML\\Core\\Twig_Util_DeprecationCollector');
