<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

use PHPCfg\Parser as CFGParser;
use PhpParser\ParserFactory;

class EndToEndTest extends \PHPUnit_Framework_TestCase {
    
    public static function provideTest() {
        $it = new \CallbackFilterIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(__DIR__ . "/../EndToEndTests/")
                ),
                function ($file) {
                    return $file->getExtension() === 'php';
                }
        );
        $tests = [];
        foreach ($it as $file) {
            $tests[] = array_merge([basename($file)], require($file));
        }
        return $tests;
    }

    private $analyzer;
    private $parser;

    public function setUp() {
        $this->analyzer = new Command\Analyze;
        $this->analyzer->loadRules();
        $this->parser = new CFGParser((new ParserFactory)->create(ParserFactory::PREFER_PHP7));
    }

    /**
     * @dataProvider provideTest
     */
    public function testDecl($file, $code, $expected) {
        $blocks = ["file.php" => $this->parser->parse($code, "file.php")];
        ob_start();
        $components = $this->analyzer->analyzeGraphs($blocks);
        $rules = [];
        $rules[] = new Rule\ArgumentType;
        $rules[] = new Rule\ReturnType;
        $rules[] = new Rule\ConstructorType;
        $errors = [];
        foreach ($rules as $rule) {
            $errors = array_merge($errors, $rule->execute($components));
        }
        $results = [];
        foreach ($errors as $tmp) {
            $results[] = [
                "line"    => $tmp[1]->getLine(),
                "message" => $tmp[0],
            ];
        }
        $output = ob_get_clean();
        $sort = function($a, $b) {
            if ($a['line'] !== $b['line']) {
                return $a['line'] - $b['line'];
            }
            return strcmp($a['message'], $b['message']);
        };
        usort($expected, $sort);
        usort($results, $sort);
        $this->assertEquals($expected, $results);
    }

}