<?php

global $file_scope;

function outer()
{
    function inner()
    {
        global $inner;
    }

    $closure = static function () {
        global $closure_a, $closure_b;
    };

    $arrow = static fn() => $GLOBALS['arrow'];

    return new class () {
        public function method()
        {
            global $method;
        }
    };
}
