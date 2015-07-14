<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php
namespace NS1 {
    interface A {}
}
namespace NS2 {
    use NS1\A;
    interface B extends A {}
}
namespace NS3 {
    use NS2\B;
    class C implements B {}
}
namespace NS4 {
    use NS1\A;
    class C implements A {}
}
namespace {
    use NS2\B;
    function foo(B $c) {

    }
    foo(new NS3\C);
    foo(new NS4\C);
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 23,
            "message" => "Type mismatch on foo() argument 0, found NS4\C expecting NS2\B",
        ]
    ]
];