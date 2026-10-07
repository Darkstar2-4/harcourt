<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 * (c) Armin Ronacher
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Error;

class SyntaxError extends \WPML\Core\Twig\Error\Error
{
    public function addSuggestions($name, array $items)
    {
        if (!($alternatives = self::computeAlternatives($name, $items))) {
            return;
        }
        $this->appendMessage(\sprintf(' Did you mean "%s"?', \implode('", "', $alternatives)));
    }
    public static function computeAlternatives($name, $items)
    {
        $alternatives = [];
        foreach ($items as $item) {
            $lev = \levenshtein($name, $item);
            if ($lev <= \strlen($name) / 3 || \false !== \strpos($item, $name)) {
                $alternatives[$item] = $lev;
            }
        }
        \asort($alternatives);
        return \array_keys($alternatives);
    }
}
\class_alias('WPML\\Core\\Twig\\Error\\SyntaxError', 'WPML\\Core\\Twig_Error_Syntax');
