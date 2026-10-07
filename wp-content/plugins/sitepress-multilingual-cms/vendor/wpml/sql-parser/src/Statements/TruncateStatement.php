<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Statement;

/**
 * `TRUNCATE` statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class TruncateStatement extends Statement
{
    public static $OPTIONS = array(
        'TABLE' => 1
    );

    public $table;

    public function build()
    {
        return 'TRUNCATE TABLE ' . $this->table . ';';
    }
}
