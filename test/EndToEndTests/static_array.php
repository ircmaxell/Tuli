<?php
$code = <<<'EOF'
<?php

$array = [1, 2, 3];

foreach ($array as $value) {
    var_dump(ord($value));
}
?>
EOF;

return [
    $code,
    [
        [
            "line" => 6,
            "message" => "Type mismatch on ord() argument 0, found int expecting string",
        ]
    ]
];