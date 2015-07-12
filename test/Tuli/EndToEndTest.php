<?php

namespace Tuli;


use PhpParser\Parser;
use PhpParser\Lexer;
use PHPCfg\Parser as CFGParser;

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
            $tests[] = require($file);
        }
        return $tests;
    }

    private $analyzer;
    private $parser;

    public function setUp() {
        $this->analyzer = new AnalyzeCommand;
        $this->analyzer->loadRules();
        $this->parser = new CFGParser(new Parser(new Lexer));
    }

    /**
     * @dataProvider provideTest
     */
    public function testDecl($code, $expected) {
        $blocks = ["file.php" => $this->parser->parse($code, "file.php")];
        ob_start();
        $actual = $this->analyzer->analyzeGraphs($blocks);
        $output = ob_get_clean();
        while ($error = array_pop($expected)) {
            foreach ($actual as $key => $tmp) {
                if ($tmp[1]->getLine() !== $error['line']) {
                    continue;
                }
                if ($tmp[0] === $error['message']) {
                    unset($actual[$key]);
                    continue 2;
                }
            }
            $this->fail("Did not find error in result: " . $error['message'] . " expected on line " . $error['line']);
        }
        foreach ($actual as $value) {
            $this->fail("Unexpected error: " . $value[0] . " on line " . $value[1]->getLine());
        }
    }

}