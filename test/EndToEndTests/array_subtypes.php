<?php
$code = <<<'EOF'
<?php
function foo(int $a) : array {
    return [$a];
}

foo(0);
foo(1);
?>
EOF;

return [
    $code,
    [

    ]
];