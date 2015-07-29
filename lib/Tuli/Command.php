<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

use PHPCfg\Parser as CFGParser;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use PhpParser\ParserFactory;
use PHPTypes\State;
use PHPTypes\TypeReconstructor;
use Symfony\Component\Console\Command\Command as CoreCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends CoreCommand {

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

    protected function configure() {
        $this->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Extensions To Exclude?", $this->defaultSkipExtensions)
            ->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The files to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $parser = new CFGParser((new ParserFactory)->create(ParserFactory::PREFER_PHP7));
        $graphs = $this->getGraphsFromFiles($input->getArgument('files'), $input->getOption("exclude"), $parser);
        return $this->analyzeGraphs($graphs);
    }

    public function analyzeGraphs(array $graphs) {
        $state = new State(array_values($graphs));

        echo "Determining Variable Types\n";
        $typeReconstructor = new TypeReconstructor;
        $typeReconstructor->resolve($state);
        return $state;
        
    }

    protected function getGraphsFromFiles(array $files, array $exclude, CFGParser $parser) {
        $excludeParts = [];
        foreach ($exclude as $part) {
            $excludeParts[] = preg_quote($part);
        }
        $part = implode('|', $excludeParts);
        $excludeRegex = "(((\\.($part)($|/))|((^|/)($part)($|/))))";
        $graphs = [];
        $traverser = new Traverser;
        $traverser->addVisitor(new Visitor\Simplifier);
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
                $traverser->traverse($graphs[$file]);
            }
        }
        return $graphs;
    }

}