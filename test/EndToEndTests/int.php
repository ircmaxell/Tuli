<?php
$code = <<<'EOF'
<?php
function foo(int $a) : int {
    return $a + 1.5;
}

foo(1);
foo(1.0);
foo("test");
?>
EOF;

return [
    $code,
    [
        [
            "line" => 7,
            "message" => "Type mismatch on foo() argument 0, found float expecting int",
        ],
        [
            "line" => 8,
            "message" => "Type mismatch on foo() argument 0, found string expecting int",
        ],
        [
            "line" => 3,
            "message" => "Type mismatch on return value, found float expecting int",
        ]
    ]
];