<?php

namespace WPML\Core;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 * (c) Arnaud Le Blanc
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use WPML\Core\Twig\Node\Node;
interface Twig_FunctionInterface
{
    public function compile();
    public function needsEnvironment();
    public function needsContext();
    public function getSafe(\WPML\Core\Twig\Node\Node $filterArgs);
    public function setArguments($arguments);
    public function getArguments();
}
