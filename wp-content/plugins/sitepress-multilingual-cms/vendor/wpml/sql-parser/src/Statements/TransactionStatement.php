<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Transaction statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class TransactionStatement extends Statement
{
    const TYPE_BEGIN = 1;

    const TYPE_END = 2;

    public $type;

    public $statements;

    public $end;

    public static $OPTIONS = array(
        'START TRANSACTION' => 1,
        'BEGIN' => 1,
        'COMMIT' => 1,
        'ROLLBACK' => 1,
        'WITH CONSISTENT SNAPSHOT' => 2,
        'WORK' => 2,
        'AND NO CHAIN' => 3,
        'AND CHAIN' => 3,
        'RELEASE' => 4,
        'NO RELEASE' => 4
    );

    public function parse(Parser $parser, TokensList $list)
    {
        parent::parse($parser, $list);

        if ($this->options->has('START TRANSACTION')
            || $this->options->has('BEGIN')
        ) {
            $this->type = self::TYPE_BEGIN;
        } elseif ($this->options->has('COMMIT')
            || $this->options->has('ROLLBACK')
        ) {
            $this->type = self::TYPE_END;
        }
    }

    public function build()
    {
        $ret = OptionsArray::build($this->options);
        if ($this->type === self::TYPE_BEGIN) {
            foreach ($this->statements as $statement) {
                $ret .= ';' . $statement->build();
            }
            $ret .= ';' . $this->end->build();
        }

        return $ret;
    }
}
