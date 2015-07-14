<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php

namespace Bar;

class Foo {
	/**
	 * @var array
	 */
	public $bar = [];

	public function foo(): array {
		return $this->bar;
	}

	public function something(): int {
		return $this->bar;
	}
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 16,
            "message" => "Type mismatch on return value, found array expecting int",
        ]
    ]
];