<?php


namespace PhpMyAdmin\SqlParser\Utils;

use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;

/**
 * Error related utilities.
 *
 * @category   Exceptions
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Error
{
    public static function get($objs)
    {
        $ret = array();

        foreach ($objs as $obj) {
            if ($obj instanceof Lexer) {
                foreach ($obj->errors as $err) {
                    $ret[] = array(
                        $err->getMessage(),
                        $err->getCode(),
                        $err->ch,
                        $err->pos
                    );
                }
            } elseif ($obj instanceof Parser) {
                foreach ($obj->errors as $err) {
                    $ret[] = array(
                        $err->getMessage(),
                        $err->getCode(),
                        $err->token->token,
                        $err->token->position
                    );
                }
            }
        }

        return $ret;
    }

    public static function format(
        $errors,
        $format = '#%1$d: %2$s (near "%4$s" at position %5$d)'
    ) {
        $ret = array();

        $i = 0;
        foreach ($errors as $key => $err) {
            $ret[$key] = sprintf(
                $format,
                ++$i,
                $err[0],
                $err[1],
                htmlspecialchars($err[2]),
                $err[3]
            );
        }

        return $ret;
    }
}
