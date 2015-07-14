<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php

interface A {
    function bar() : int;
}
interface B {
    function foo() : string;
}

function foo(A $a) : int {
    if ($a instanceof B) {
        return $a->foo();
    }
    return $a->bar();
}

function bar($abc) : int {
    if (is_int($abc)) {
        return $abc;
    }
    return 10;
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 12,
            "message" => "Type mismatch on return value, found string expecting int",
        ]
    ]
];