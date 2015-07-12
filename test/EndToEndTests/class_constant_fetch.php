<?php
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
            "line" => 13,
            "message" => "Type mismatch on return value, found int|string expecting int",
        ]
    ]
];