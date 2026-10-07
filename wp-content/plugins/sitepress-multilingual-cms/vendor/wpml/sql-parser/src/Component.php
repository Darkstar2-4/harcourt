<?php


namespace PhpMyAdmin\SqlParser;

/**
 * A component (of a statement) is a part of a statement that is common to
 * multiple query types.
 *
 * @category Components
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
abstract class Component
{
    public static function parse(
        Parser $parser,
        TokensList $list,
        array $options = array()
    ) {
        throw new \Exception(Translator::gettext('Not implemented yet.'));
    }

    public static function build($component, array $options = array())
    {
        throw new \Exception(Translator::gettext('Not implemented yet.'));
    }

    public function __toString()
    {
        return static::build($this);
    }
}
