<?php
$code = <<<'EOF'
<?php
function foo(int $a) : bool {
    $return = false;
    if ($a > 0) {
        $return = true;
    }
    return $return;
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