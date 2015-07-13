<?php
$code = <<<'EOF'
<?php

function foo(int $a) : int {
	if ($a > 0 && $a < 100) {
		return $a;
	}
	return false;
}
?>
EOF;

return [
    $code,
    [
        [
            "line" => 7,
            "message" => "Type mismatch on return value, found bool expecting int",
        ],
    ],
];