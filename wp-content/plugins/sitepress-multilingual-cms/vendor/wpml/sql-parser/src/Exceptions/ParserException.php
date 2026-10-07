<?php


namespace PhpMyAdmin\SqlParser\Exceptions;

use PhpMyAdmin\SqlParser\Token;

/**
 * Exception thrown by the parser.
 *
 * @category   Exceptions
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class ParserException extends \Exception
{
    public $token;

    public function __construct($msg = '', ?Token $token = null, $code = 0)
    {
        parent::__construct($msg, $code);
        $this->token = $token;
    }
}
