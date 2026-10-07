<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\FunctionCall;
use PhpMyAdmin\SqlParser\Statement;

/**
 * `CALL` statement.
 *
 * CALL sp_name([parameter[,...]])
 *
 * or
 *
 * CALL sp_name[()]
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class CallStatement extends Statement
{
    public $call;

    public function build()
    {
        return "CALL " . $this->call->name . "(" . ($this->call->parameters ? implode(",", $this->call->parameters->raw) : "") . ")";
    }
}
