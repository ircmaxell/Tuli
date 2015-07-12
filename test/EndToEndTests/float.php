<?php
$code = <<<'EOF'
<?php
function foo(float $a) : float {
    return $a + 1;
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
            "line" => 8,
            "message" => "Type mismatch on foo() argument 0, found string expecting float",
        ],
    ]
];