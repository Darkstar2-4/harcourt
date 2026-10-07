<?php

namespace WPML\Core;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use WPML\Core\Twig\Loader\ExistsLoaderInterface;
use WPML\Core\Twig\Loader\LoaderInterface;
use WPML\Core\Twig\Loader\SourceContextLoaderInterface;
use WPML\Core\Twig\Source;
@\trigger_error('The Twig_Loader_String class is deprecated since version 1.18.1 and will be removed in 2.0. Use "Twig\\Loader\\ArrayLoader" instead or "Twig\\Environment::createTemplate()".', \E_USER_DEPRECATED);
class Twig_Loader_String implements \WPML\Core\Twig\Loader\LoaderInterface, \WPML\Core\Twig\Loader\ExistsLoaderInterface, \WPML\Core\Twig\Loader\SourceContextLoaderInterface
{
    public function getSource($name)
    {
        @\trigger_error(\sprintf('Calling "getSource" on "%s" is deprecated since 1.27. Use getSourceContext() instead.', \get_class($this)), \E_USER_DEPRECATED);
        return $name;
    }
    public function getSourceContext($name)
    {
        return new \WPML\Core\Twig\Source($name, $name);
    }
    public function exists($name)
    {
        return \true;
    }
    public function getCacheKey($name)
    {
        return $name;
    }
    public function isFresh($name, $time)
    {
        return \true;
    }
}
