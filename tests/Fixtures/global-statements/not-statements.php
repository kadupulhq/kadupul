<?php

// global $comment;
/* global $block, $comment; */
/** global $doc; */
$single = 'global $string;';
$double = "global $single;";
$heredoc = <<<TEXT
global \$heredoc, \$names;
TEXT;
$nowdoc = <<<'TEXT'
global $nowdoc;
TEXT;
$GLOBALS['config'] = [];
$value = $GLOBALS['config'];

final class Keywords
{
    public $global = 1;

    public function global(): int
    {
        return $this->global;
    }

    public static function call(): int
    {
        return self::global() + named(global: 1);
    }
}

function named(int $global): int
{
    return $global;
}
?>
global $inline_html;
