<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

class TypeTest extends \PHPUnit_Framework_TestCase {
    
    public static function provideTestDecl() {
        return [
            ["int", new Type(Type::TYPE_LONG)],
            ["int[]", new Type(Type::TYPE_ARRAY, [new Type(Type::TYPE_LONG)])],
            ["int|float", new Type(Type::TYPE_LONG | Type::TYPE_DOUBLE)],
            ["Traversable|array", new Type(Type::TYPE_ARRAY | Type::TYPE_USER, [], ["Traversable"])],
        ];
    }

    /**
     * @dataProvider provideTestDecl
     */
    public function testDecl($decl, $result) {
        $type = Type::fromDecl($decl);
        $this->assertEquals($result, $type);
        $this->assertEquals($decl, (string) $type);
    }

}