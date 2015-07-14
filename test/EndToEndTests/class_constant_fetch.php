<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php

interface A {}

class B implements A {
    const VAL = 1;
}

class C implements A {
    const VAL = "test";
}
function foo(A $a) : int {
    return $a::VAL;
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 13,
            "message" => "Type mismatch on return value, found int|string expecting int",
        ]
    ]
];