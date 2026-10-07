<?php


namespace PhpMyAdmin\SqlParser\Utils;

use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Token utilities.
 *
 * @category   Token
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Tokens
{
    public static function match(Token $token, array $pattern)
    {
        if (isset($pattern['token'])
            && ($pattern['token'] !== $token->token)
        ) {
            return false;
        }

        if (isset($pattern['value'])
            && ($pattern['value'] !== $token->value)
        ) {
            return false;
        }

        if (isset($pattern['value_str'])
            && strcasecmp($pattern['value_str'], $token->value)
        ) {
            return false;
        }

        if (isset($pattern['type'])
            && ($pattern['type'] !== $token->type)
        ) {
            return false;
        }

        if (isset($pattern['flags'])
            && (($pattern['flags'] & $token->flags) === 0)
        ) {
            return false;
        }

        return true;
    }

    public static function replaceTokens($list, array $find, array $replace)
    {
        $isList = $list instanceof TokensList;

        if (! $isList) {
            $list = Lexer::getTokens($list);
        }

        $newList = array();

        $findCount = count($find);

        $i = 0;

        while ($i < $list->count) {
            if ($list->tokens[$i]->type === Token::TYPE_COMMENT) {
                $newList[] = $list->tokens[$i];
                ++$i;
                continue;
            }

            $j = $i;

            $k = 0;

            while (($j < $list->count) && ($k < $findCount)) {
                if ($list->tokens[$j]->type === Token::TYPE_COMMENT) {
                    ++$j;
                }

                if (! static::match($list->tokens[$j], $find[$k])) {
                    break;
                }

                ++$j;
                ++$k;
            }

            if ($k === $findCount) {
                foreach ($replace as $token) {
                    $newList[] = $token;
                }

                $i = $j;
            } else {
                $newList[] = $list->tokens[$i];
                ++$i;
            }
        }

        return $isList ?
            new TokensList($newList) : TokensList::build($newList);
    }
}
