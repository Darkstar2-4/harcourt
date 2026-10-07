<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig;

class FileExtensionEscapingStrategy
{
    public static function guess($name)
    {
        if (\in_array(\substr($name, -1), ['/', '\\'])) {
            return 'html';
        }
        if ('.twig' === \substr($name, -5)) {
            $name = \substr($name, 0, -5);
        }
        $extension = \pathinfo($name, \PATHINFO_EXTENSION);
        switch ($extension) {
            case 'js':
                return 'js';
            case 'css':
                return 'css';
            case 'txt':
                return \false;
            default:
                return 'html';
        }
    }
}
\class_alias('WPML\\Core\\Twig\\FileExtensionEscapingStrategy', 'WPML\\Core\\Twig_FileExtensionEscapingStrategy');
