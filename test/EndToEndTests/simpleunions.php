<?php
$code = <<<'EOF'
<?php
/**
 * @param string|int $a
 */
function foo($a) {

}
foo(1.5);
foo(1);
foo("a");
foo([1]);
?>
EOF;

return [
    $code,
    [
        [
            "line" => 8,
            "message" => "Type mismatch on foo() argument 0, found float expecting int|string",
        ],
        [
            "line" => 11,
            "message" => "Type mismatch on foo() argument 0, found int[] expecting int|string",
        ],
        
    ]
];