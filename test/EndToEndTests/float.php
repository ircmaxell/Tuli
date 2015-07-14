<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php
function foo(float $a) : float {
    return $a + 1;
}

foo(1);
foo(1.0);
foo("test");
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 8,
            "message" => "Type mismatch on foo() argument 0, found string expecting float",
        ],
    ]
];