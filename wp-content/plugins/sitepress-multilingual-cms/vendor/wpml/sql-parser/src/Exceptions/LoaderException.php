<?php


namespace PhpMyAdmin\SqlParser\Exceptions;

/**
 * Exception thrown by the lexer.
 *
 * @category   Exceptions
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class LoaderException extends \Exception
{
    public $name;

    public function __construct($msg = '', $name = '', $code = 0)
    {
        parent::__construct($msg, $code);
        $this->name = $name;
    }
}
