<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses the create definition of a partition.
 *
 * Used for parsing `CREATE TABLE` statement.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class PartitionDefinition extends Component
{
    public static $OPTIONS = array(
        'STORAGE ENGINE' => array(
            1,
            'var',
        ),
        'ENGINE' => array(
            1,
            'var',
        ),
        'COMMENT' => array(
            2,
            'var',
        ),
        'DATA DIRECTORY' => array(
            3,
            'var',
        ),
        'INDEX DIRECTORY' => array(
            4,
            'var',
        ),
        'MAX_ROWS' => array(
            5,
            'var',
        ),
        'MIN_ROWS' => array(
            6,
            'var',
        ),
        'TABLESPACE' => array(
            7,
            'var',
        ),
        'NODEGROUP' => array(
            8,
            'var',
        )
    );

    public $isSubpartition;

    public $name;

    public $type;

    public $expr;

    public $subpartitions;

    public $options;

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
                $ret->isSubpartition = ($token->type === Token::TYPE_KEYWORD) && ($token->keyword === 'SUBPARTITION');
                $state = 1;
            } elseif ($state === 1) {
                $ret->name = $token->value;

                while ($nextToken = $list->tokens[++$list->idx]) {
                    if ($nextToken->type !== Token::TYPE_NONE) {
                        break;
                    }
                    $ret->name .= $nextToken->value;
                }
                $idx = $list->idx--;
                $nextToken = $list->tokens[++$idx];

                $state = ($nextToken->type === Token::TYPE_KEYWORD)
                    && ($nextToken->value === 'VALUES')
                    ? 2 : 5;
            } elseif ($state === 2) {
                $state = 3;
            } elseif ($state === 3) {
                $ret->type = $token->value;
                $state = 4;
            } elseif ($state === 4) {
                if ($token->value === 'MAXVALUE') {
                    $ret->expr = $token->value;
                } else {
                    $ret->expr = Expression::parse(
                        $parser,
                        $list,
                        array(
                            'parenthesesDelimited' => true,
                            'breakOnAlias' => true
                        )
                    );
                }
                $state = 5;
            } elseif ($state === 5) {
                $ret->options = OptionsArray::parse($parser, $list, static::$OPTIONS);
                $state = 6;
            } elseif ($state === 6) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $ret->subpartitions = ArrayObj::parse(
                        $parser,
                        $list,
                        array(
                            'type' => 'PhpMyAdmin\\SqlParser\\Components\\PartitionDefinition'
                        )
                    );
                    ++$list->idx;
                }
                break;
            }
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return "(\n" . implode(",\n", $component) . "\n)";
        }

        if ($component->isSubpartition) {
            return trim('SUBPARTITION ' . $component->name . ' ' . $component->options);
        }

        $subpartitions = empty($component->subpartitions) ? '' : ' ' . self::build($component->subpartitions);

        return trim(
            'PARTITION ' . $component->name
            . (empty($component->type) ? '' : ' VALUES ' . $component->type . ' ' . $component->expr . ' ')
            . ((! empty($component->options) && ! empty($component->type)) ? '' : ' ') . $component->options . $subpartitions
        );
    }
}
