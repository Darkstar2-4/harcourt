<?php


namespace PhpMyAdmin\SqlParser\Exceptions;

/**
 * Exception thrown by the lexer.
 *
 * @category   Exceptions
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class LexerException extends \Exception
{
    public $ch;

    public $pos;

    public function __construct($msg = '', $ch = '', $pos = 0, $code = 0)
    {
        parent::__construct($msg, $code);
        $this->ch = $ch;
        $this->pos = $pos;
    }
}
