<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

use PHPCfg\Block;
use PHPCfg\Dumper;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Parser as CFGParser;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use PhpParser\ParserFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AnalyzeCommand extends Command {

    /**
     * @var string[]
     */
    protected $defaultSkipExtensions = [
        'md',
        'markdown',
        'xml',
        'rst',
        'phpt',
        '.git',
        'json',
        'yml',
        'dist',
        'test',
        'tests',
        'Tests',
        'parser',
        'build',
        'sh',
        '.gitignore',
        'LICENSE',
        'template',
        'Template',
        'xsd',
    ];

    /**
     * @var Tuli\Rule[]
     */
    protected $rules = [];

    protected function configure() {
        $this->setName('analyze')
            ->setDescription('Analyze the provided files')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Extensions To Exclude?", $this->defaultSkipExtensions)
            ->addOption('dump', 'd', InputOption::VALUE_NONE, "Dump the graph", null)
            ->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The files to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->loadRules();
        $parser = new CFGParser((new ParserFactory)->create(ParserFactory::PREFER_PHP7));
        $graphs = $this->getGraphsFromFiles($input->getArgument('files'), $input->getOption("exclude"), $parser);
        $errors = $this->analyzeGraphs($graphs);
        if ($input->getOption('dump')) {
            $dumper = new Dumper;
            foreach ($graphs as $key => $graph) {
                echo "Graph #$key\n";
                echo $dumper->dump($graph);
                echo "\n";
            }
        }
        if ($errors) {
            echo "\nErrors found:\n";
            foreach ($errors as $error) {
                $this->emitError($error[0], $error[1]);
            }
        }
    }

    public function analyzeGraphs(array $graphs) {
        $components = $this->preProcess($graphs);
        $components = $this->computeTypeMatrix($components);
        $components['typeResolver'] = new TypeResolver($components);

        echo "Determining Variable Types\n";
        $typeReconstructor = new TypeReconstructor;
        $typeReconstructor->resolve($components);

        $errors = [];
        foreach ($this->rules as $rule) {
            echo "Executing rule: " . $rule->getName() . "\n";
            $errors = array_merge($errors, $rule->execute($components));
        }
        return $errors;
    }

    public function loadRules() {
        $this->rules[] = new Rule\ArgumentType;
        $this->rules[] = new Rule\ReturnType;
        $this->rules[] = new Rule\ConstructorType;
    }

    protected function getGraphsFromFiles(array $files, array $exclude, CFGParser $parser) {
        $excludeParts = [];
        foreach ($exclude as $part) {
            $excludeParts[] = preg_quote($part);
        }
        $part = implode('|', $excludeParts);
        $excludeRegex = "(((\\.($part)($|/))|((^|/)($part)($|/))))";
        $graphs = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $local = [$file];
            } elseif (is_dir($file)) {
                $it = new \CallbackFilterIterator(
                    new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($file)
                    ),
                    function(\SplFileInfo $file) use ($excludeRegex) {
                        if (preg_match($excludeRegex, $file->getPathName())) {
                            return false;
                        }
                        return $file->isFile();
                    }
                );
                $local = [];
                foreach ($it as $file) {
                    $local[] = $file->getPathName(); // since __toString would be too difficult...
                }
            } else {
                throw new \RuntimeException("Error: $file is not a file or directory");
            }
            foreach ($local as $file) {
                echo "Analyzing $file\n";
                $graphs[$file] = $parser->parse(file_get_contents($file), $file);
            }
        }
        return $graphs;
    }

    /**
     * @param PHPCfg\Block[] $blocks
     *
     * @return array The result
     */
    protected function preProcess(array $blocks) {
        $traverser = new Traverser;
        $declarations = new Visitor\DeclarationFinder;
        $calls = new Visitor\CallFinder;
        $variables = new Visitor\VariableFinder;
        $dagComputer = new Visitor\VariableDagComputer;
        $traverser->addVisitor(new Visitor\Simplifier);
        $traverser->addVisitor($dagComputer);
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);
        foreach ($blocks as $block) {
            $traverser->traverse($block);
        }
        return [
            "cfg"              => $blocks,
            "constants"        => $declarations->getConstants(),
            "traits"           => $declarations->getTraits(),
            "classes"          => $declarations->getClasses(),
            "methods"          => $declarations->getMethods(),
            "functions"        => $declarations->getFunctions(),
            "functionLookup"   => $this->buildFunctionLookup($declarations->getFunctions()),
            "interfaces"       => $declarations->getInterfaces(),
            "variables"        => $variables->getVariables(),
            "callResolver"     => $calls,
            "methodCalls"      => $this->findMethodCalls($blocks),
            "newCalls"         => $this->findNewCalls($blocks),
            "internalTypeInfo" => new InternalArgInfo,
        ];
    }

    protected function findNewCalls(array $blocks) {
        $newCalls = [];
        foreach ($blocks as $block) {
            $newCalls = $this->findTypedBlock("Expr_New", $block, $newCalls);
        }
        return $newCalls;
    }

    protected function findMethodCalls(array $blocks) {
        $methodCalls = [];
        foreach ($blocks as $block) {
            $methodCalls = $this->findTypedBlock("Expr_MethodCall", $block, $methodCalls);
        }
        return $methodCalls;
    }

    /**
     * @param array $components
     *
     * @return array The result
     */
    protected function computeTypeMatrix($components) {
        // TODO: This is dirty, and needs cleaning
        // A extends B
        $map = []; // a => [a, b], b => [b]
        $interfaceMap = [];
        $classMap = [];
        $toProcess = [];
        foreach ($components['interfaces'] as $interface) {
            $name = strtolower($interface->name->value);
            $map[$name] = [$name => $interface];
            $interfaceMap[$name] = [];
            if ($interface->extends) {
                foreach ($interface->extends as $extends) {
                    $sub = strtolower($extends->value);
                    $interfaceMap[$name][] = $sub;
                    $map[$sub][$name] = $interface;
                }
            }
        }
        foreach ($components['classes'] as $class) {
            $name = strtolower($class->name->value);
            $map[$name] = [$name => $class];
            $classMap[$name] = [$name];
            foreach ($class->implements as $interface) {
                $iname = strtolower($interface->value);
                $classMap[$name][] = $iname;
                $map[$iname][$name] = $class;
                if (isset($interfaceMap[$iname])) {
                    foreach ($interfaceMap[$iname] as $sub) {
                        $classMap[$name][] = $sub;
                        $map[$sub][$name] = $class;
                    }
                }
            }
            if ($class->extends) {
                $toProcess[] = [$name, strtolower($class->extends->value), $class];
            }
        }
        foreach ($toProcess as $ext) {
            $name = $ext[0];
            $extends = $ext[1];
            $class = $ext[2];
            if (isset($classMap[$extends])) {
                foreach ($classMap[$extends] as $mapped) {
                    $map[$mapped][$name] = $class;
                }
            } else {
                echo "Could not find parent $extends\n";
            }
        }
        $components['resolves'] = $map;
        $components['resolvedBy'] = [];
        foreach ($map as $child => $parent) {
            foreach ($parent as $name => $_) {
                if (!isset($components['resolvedBy'][$name])) {
                    $components['resolvedBy'][$name] = [];
                }
                //allows iterating and looking udm_cat_path(agent, category)
                $components['resolvedBy'][$name][$child] = $child;
            }
        }
        return $components;
    }

    protected function buildFunctionLookup(array $functions) {
        $lookup = [];
        foreach ($functions as $function) {
            assert($function->name instanceof Operand\Literal);
            $name = strtolower($function->name->value);
            if (!isset($lookup[$name])) {
                $lookup[$name] = [];
            }
            $lookup[$name][] = $function;
        }
        return $lookup;
    }

    protected function findTypedBlock($type, Block $block, $result = []) {
        $toProcess = new \SplObjectStorage;
        $processed = new \SplObjectStorage;
        $toProcess->attach($block);
        while (count($toProcess) > 0) {
            foreach ($toProcess as $block) {
                $toProcess->detach($block);
                $processed->attach($block);
                foreach ($block->children as $op) {
                    if ($op->getType() === $type) {
                        $result[] = $op;
                    }
                    foreach ($op->getSubBlocks() as $name) {
                        $sub = $op->$name;
                        if (is_null($sub)) {
                            continue;
                        }
                        if (!is_array($sub)) {
                            $sub = [$sub];
                        }
                        foreach ($sub as $subb) {
                            if (!$processed->contains($subb)) {
                                $toProcess->attach($subb);
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    protected function emitError($msg, Op $op) {
        echo $msg;
        echo " ";
        echo $op->getFile() . ":" . $op->getLine();
        echo "\n";
    }

}