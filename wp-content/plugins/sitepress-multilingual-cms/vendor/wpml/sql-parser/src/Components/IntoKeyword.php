<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `INTO` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class IntoKeyword extends Component
{
    public static $FIELDS_OPTIONS = array(
        'TERMINATED BY' => array(
            1,
            'expr',
        ),
        'OPTIONALLY' => 2,
        'ENCLOSED BY' => array(
            3,
            'expr',
        ),
        'ESCAPED BY' => array(
            4,
            'expr',
        )
    );

    public static $LINES_OPTIONS = array(
        'STARTING BY' => array(
            1,
            'expr',
        ),
        'TERMINATED BY' => array(
            2,
            'expr',
        )
    );

    public $type;

    public $dest;

    public $columns;

    public $values;

    public $fields_options;

    public $fields_keyword;

    public $lines_options;

    public function __construct(
        $type = null,
        $dest = null,
        $columns = null,
        $values = null,
        $fields_options = null,
        $fields_keyword = null
    ) {
        $this->type = $type;
        $this->dest = $dest;
        $this->columns = $columns;
        $this->values = $values;
        $this->fields_options = $fields_options;
        $this->fields_keyword = $fields_keyword;
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

            if (($token->type === Token::TYPE_KEYWORD) && ($token->flags & Token::FLAG_KEYWORD_RESERVED)) {
                if (($state === 0) && ($token->keyword === 'OUTFILE')) {
                    $ret->type = 'OUTFILE';
                    $state = 2;
                    continue;
                }

                if ($state !== 4) {
                    break;
                }
            }

            if ($state === 0) {
                if ((isset($options['fromInsert'])
                    && $options['fromInsert'])
                    || (isset($options['fromReplace'])
                    && $options['fromReplace'])
                ) {
                    $ret->dest = Expression::parse(
                        $parser,
                        $list,
                        array(
                            'parseField' => 'table',
                            'breakOnAlias' => true
                        )
                    );
                } else {
                    $ret->values = ExpressionArray::parse($parser, $list);
                }
                $state = 1;
            } elseif ($state === 1) {
                if (($token->type === Token::TYPE_OPERATOR) && ($token->value === '(')) {
                    $ret->columns = ArrayObj::parse($parser, $list)->values;
                    ++$list->idx;
                }
                break;
            } elseif ($state === 2) {
                $ret->dest = $token->value;

                $state = 3;
            } elseif ($state === 3) {
                $ret->parseFileOptions($parser, $list, $token->value);
                $state = 4;
            } elseif ($state === 4) {
                if ($token->type === Token::TYPE_KEYWORD && $token->keyword !== 'LINES') {
                    break;
                }

                $ret->parseFileOptions($parser, $list, $token->value);
                $state = 5;
            }
        }

        --$list->idx;

        return $ret;
    }

    public function parseFileOptions(Parser $parser, TokensList $list, $keyword = 'FIELDS')
    {
        ++$list->idx;

        if ($keyword === 'FIELDS' || $keyword === 'COLUMNS') {
            $this->fields_options = OptionsArray::parse(
                $parser,
                $list,
                static::$FIELDS_OPTIONS
            );

            $this->fields_keyword = ($keyword === 'FIELDS');
        } else {
            $this->lines_options = OptionsArray::parse(
                $parser,
                $list,
                static::$LINES_OPTIONS
            );
        }
    }

    public static function build($component, array $options = array())
    {
        if ($component->dest instanceof Expression) {
            $columns = ! empty($component->columns) ? '(`' . implode('`, `', $component->columns) . '`)' : '';

            return $component->dest . $columns;
        } elseif (isset($component->values)) {
            return ExpressionArray::build($component->values);
        }

        $ret = 'OUTFILE "' . $component->dest . '"';

        $fields_options_str = OptionsArray::build($component->fields_options);
        if (trim($fields_options_str) !== '') {
            $ret .= $component->fields_keyword ? ' FIELDS' : ' COLUMNS';
            $ret .= ' ' . $fields_options_str;
        }

        $lines_options_str = OptionsArray::build($component->lines_options, array('expr' => true));
        if (trim($lines_options_str) !== '') {
            $ret .= ' LINES ' . $lines_options_str;
        }

        return $ret;
    }
}
