<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Statement;

/**
 * `DROP` statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class DropStatement extends Statement
{
    public static $OPTIONS = array(
        'DATABASE' => 1,
        'EVENT' => 1,
        'FUNCTION' => 1,
        'INDEX' => 1,
        'LOGFILE' => 1,
        'PROCEDURE' => 1,
        'SCHEMA' => 1,
        'SERVER' => 1,
        'TABLE' => 1,
        'VIEW' => 1,
        'TABLESPACE' => 1,
        'TRIGGER' => 1,
        'USER' => 1,

        'TEMPORARY' => 2,
        'IF EXISTS' => 3
    );

    public static $CLAUSES = array(
        'DROP' => array(
            'DROP',
            2,
        ),
        '_OPTIONS' => array(
            '_OPTIONS',
            1,
        ),
        'DROP_' => array(
            'DROP',
            1,
        ),
        'ON' => array(
            'ON',
            3,
        )
    );

    public $fields;

    public $table;
}
