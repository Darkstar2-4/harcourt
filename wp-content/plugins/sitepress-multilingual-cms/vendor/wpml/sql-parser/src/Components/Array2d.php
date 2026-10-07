<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;
use PhpMyAdmin\SqlParser\Translator;

/**
 * `VALUES` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Array2d extends Component
{
    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = array();

        $count = -1;

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
                break;
            }

            if ($state === 0) {
                if ($token->value === '(') {
                    $arr = ArrayObj::parse($parser, $list, $options);
                    $arrCount = count($arr->values);
                    if ($count === -1) {
                        $count = $arrCount;
                    } elseif ($arrCount !== $count) {
                        $parser->error(
                            sprintf(
                                Translator::gettext('%1$d values were expected, but found %2$d.'),
                                $count,
                                $arrCount
                            ),
                            $token
                        );
                    }
                    $ret[] = $arr;
                    $state = 1;
                } else {
                    break;
                }
            } elseif ($state === 1) {
                if ($token->value === ',') {
                    $state = 0;
                } else {
                    break;
                }
            }
        }

        if ($state === 0) {
            $parser->error(
                'An opening bracket followed by a set of values was expected.',
                $list->tokens[$list->idx]
            );
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        return ArrayObj::build($component);
    }
}
