<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Node;

use WPML\Core\Twig\Compiler;
use WPML\Core\Twig\Node\Expression\ConstantExpression;
use WPML\Core\Twig\Node\Expression\FilterExpression;
class SandboxedPrintNode extends \WPML\Core\Twig\Node\PrintNode
{
    public function compile(\WPML\Core\Twig\Compiler $compiler)
    {
        $compiler->addDebugInfo($this)->write('echo ');
        $expr = $this->getNode('expr');
        if ($expr instanceof \WPML\Core\Twig\Node\Expression\ConstantExpression) {
            $compiler->subcompile($expr)->raw(";\n");
        } else {
            $compiler->write('$this->env->getExtension(\'\\WPML\\Core\\Twig\\Extension\\SandboxExtension\')->ensureToStringAllowed(')->subcompile($expr)->raw(");\n");
        }
    }
    protected function removeNodeFilter(\WPML\Core\Twig\Node\Node $node)
    {
        if ($node instanceof \WPML\Core\Twig\Node\Expression\FilterExpression) {
            return $this->removeNodeFilter($node->getNode('node'));
        }
        return $node;
    }
}
\class_alias('WPML\\Core\\Twig\\Node\\SandboxedPrintNode', 'WPML\\Core\\Twig_Node_SandboxedPrint');
