<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php
function foo(int $a) : bool {
    $return = false;
    if ($a > 0) {
        $return = true;
    }
    return $return;
}

foo(0);
foo(1);
?>
EOF;

return [
    $code,
    [

    ]
];