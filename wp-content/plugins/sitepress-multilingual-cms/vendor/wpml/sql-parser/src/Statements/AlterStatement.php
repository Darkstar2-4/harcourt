<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\AlterOperation;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\OptionsArray;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `ALTER` statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class AlterStatement extends Statement
{
    public $table;

    public $altered = array();

    public static $OPTIONS = array(
        'ONLINE' => 1,
        'OFFLINE' => 1,
        'IGNORE' => 2,

        'DATABASE' => 3,
        'EVENT' => 3,
        'FUNCTION' => 3,
        'PROCEDURE' => 3,
        'SERVER' => 3,
        'TABLE' => 3,
        'TABLESPACE' => 3,
        'USER' => 3,
        'VIEW' => 3
    );

    public function parse(Parser $parser, TokensList $list)
    {
        ++$list->idx;
        $this->options = OptionsArray::parse(
            $parser,
            $list,
            static::$OPTIONS
        );
        ++$list->idx;

        $this->table = Expression::parse(
            $parser,
            $list,
            array(
                'parseField' => 'table',
                'breakOnAlias' => true
            )
        );
        ++$list->idx;

        $state = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if ($state === 0) {
                $options = array();
                if ($this->options->has('DATABASE')) {
                    $options = AlterOperation::$DB_OPTIONS;
                } elseif ($this->options->has('TABLE')) {
                    $options = AlterOperation::$TABLE_OPTIONS;
                } elseif ($this->options->has('VIEW')) {
                    $options = AlterOperation::$VIEW_OPTIONS;
                } elseif ($this->options->has('USER')) {
                    $options = AlterOperation::$USER_OPTIONS;
                }

                $this->altered[] = AlterOperation::parse($parser, $list, $options);
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === ',')) {
                    $state = 0;
                }
            }
        }
    }

    public function build()
    {
        $tmp = array();
        foreach ($this->altered as $altered) {
            $tmp[] = $altered::build($altered);
        }

        return 'ALTER ' . OptionsArray::build($this->options)
            . ' ' . Expression::build($this->table)
            . ' ' . implode(', ', $tmp);
    }
}
