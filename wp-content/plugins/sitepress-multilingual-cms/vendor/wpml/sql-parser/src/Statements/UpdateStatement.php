<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Components\SetOperation;
use PhpMyAdmin\SqlParser\Statement;

/**
 * `UPDATE` statement.
 *
 * UPDATE [LOW_PRIORITY] [IGNORE] table_reference
 *     SET col_name1={expr1|DEFAULT} [, col_name2={expr2|DEFAULT}] ...
 *     [WHERE where_condition]
 *     [ORDER BY ...]
 *     [LIMIT row_count]
 *
 * or
 *
 * UPDATE [LOW_PRIORITY] [IGNORE] table_references
 *     SET col_name1={expr1|DEFAULT} [, col_name2={expr2|DEFAULT}] ...
 *     [WHERE where_condition]
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class UpdateStatement extends Statement
{
    public static $OPTIONS = array(
        'LOW_PRIORITY' => 1,
        'IGNORE' => 2
    );

    public static $CLAUSES = array(
        'UPDATE' => array(
            'UPDATE',
            2,
        ),
        '_OPTIONS' => array(
            '_OPTIONS',
            1,
        ),
        '_UPDATE' => array(
            'UPDATE',
            1,
        ),
        'SET' => array(
            'SET',
            3,
        ),
        'WHERE' => array(
            'WHERE',
            3,
        ),
        'ORDER BY' => array(
            'ORDER BY',
            3,
        ),
        'LIMIT' => array(
            'LIMIT',
            3,
        )
    );

    public $tables;

    public $set;

    public $where;

    public $order;

    public $limit;
}
