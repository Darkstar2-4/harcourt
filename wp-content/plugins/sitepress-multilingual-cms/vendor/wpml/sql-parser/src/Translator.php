<?php


namespace PhpMyAdmin\SqlParser;

class Translator
{
    private static $loader;

    private static $translator;

    public static function load()
    {
        if (is_null(self::$loader)) {
            self::$loader = new \PhpMyAdmin\MoTranslator\Loader();

            self::$loader->setlocale(
                self::$loader->detectlocale()
            );

            self::$loader->textdomain('sqlparser');

            self::$loader->bindtextdomain('sqlparser', __DIR__ . '/../locale/');
        }

        if (is_null(self::$translator)) {
            self::$translator = self::$loader->getTranslator();
        }
    }

    public static function gettext($msgid)
    {
        if (! class_exists('\PhpMyAdmin\MoTranslator\Loader', true)) {
            return $msgid;
        }

        self::load();

        return self::$translator->gettext($msgid);
    }
}
