<?php
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
    		"line" => 18,
    		"message" => "Type mismatch on C::__construct() argument 0, found int expecting string",
    	]
    ]
];