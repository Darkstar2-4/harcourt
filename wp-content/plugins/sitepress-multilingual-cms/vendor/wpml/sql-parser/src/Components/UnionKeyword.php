<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;

/**
 * `UNION` keyword builder.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class UnionKeyword extends Component
{
    public static function build($component, array $options = array())
    {
        $tmp = array();
        foreach ($component as $componentPart) {
            $tmp[] = $componentPart[0] . ' ' . $componentPart[1];
        }

        return implode(' ', $tmp);
    }
}
