<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `REFERENCES` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Reference extends Component
{
    public static $REFERENCES_OPTIONS = array(
        'MATCH' => array(
            1,
            'var',
        ),
        'ON DELETE' => array(
            2,
            'var',
        ),
        'ON UPDATE' => array(
            3,
            'var',
        )
    );

    public $table;

    public $columns;

    public $options;

    public function __construct($table = null, array $columns = array(), $options = null)
    {
        $this->table = $table;
        $this->columns = $columns;
        $this->options = $options;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

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
                $ret->table = Expression::parse(
                    $parser,
                    $list,
                    array(
                        'parseField' => 'table',
                        'breakOnAlias' => true
                    )
                );
                $state = 1;
            } elseif ($state === 1) {
                $ret->columns = ArrayObj::parse($parser, $list)->values;
                $state = 2;
            } elseif ($state === 2) {
                $ret->options = OptionsArray::parse($parser, $list, static::$REFERENCES_OPTIONS);
                ++$list->idx;
                break;
            }
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        return trim(
            $component->table
            . ' (' . implode(', ', Context::escape($component->columns)) . ') '
            . $component->options
        );
    }
}
