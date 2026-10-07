<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Maintenance statement.
 *
 * They follow the syntax:
 *     STMT [some options] tbl_name [, tbl_name] ... [some more options]
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class MaintenanceStatement extends Statement
{
    public $tables;

    public function after(Parser $parser, TokensList $list, Token $token)
    {
        ++$list->idx;
        $this->options->merge(
            OptionsArray::parse(
                $parser,
                $list,
                static::$OPTIONS
            )
        );
    }
}
