<?php
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
            "line" => 16,
            "message" => "Type mismatch on return value, found array expecting int",
        ]
    ]
];