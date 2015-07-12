<?php
$code = <<<'EOF'
<?php

interface A {
    function bar() : int;
}
interface B {
    function foo() : string;
}

function foo(A $a) : int {
    if ($a instanceof B) {
        return $a->foo();
    }
    return $a->bar();
}
?>
EOF;

return [
    $code,
    [
        [
            "line" => 12,
            "message" => "Type mismatch on return value, found string expecting int",
        ]
    ]
];