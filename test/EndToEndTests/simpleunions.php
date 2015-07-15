<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php
/**
 * @param string|int $a
 */
function foo($a) {

}
foo(1.5);
foo(1);
foo("a");
foo([1]);
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 8,
            "message" => "Type mismatch on foo() argument 0, found float expecting string|int",
        ],
        [
            "line"    => 11,
            "message" => "Type mismatch on foo() argument 0, found int[] expecting string|int",
        ],
        
    ]
];