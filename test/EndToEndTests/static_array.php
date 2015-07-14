<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php

$array = [1, 2, 3];

foreach ($array as $value) {
    var_dump(ord($value));
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 6,
            "message" => "Type mismatch on ord() argument 0, found int expecting string",
        ]
    ]
];