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
	public function __construct(int $value) {

	}
}

class C implements A {
	public function __construct(string $value) {
		
	}
}

function foo(A $a): A {
	return new $a(123);
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 18,
            "message" => "Type mismatch on C::__construct() argument 0, found int expecting string",
        ]
    ]
];