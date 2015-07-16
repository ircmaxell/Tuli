<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli\Command;

use PHPCfg\Printer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tuli\Command;

class PrintVars extends Command {

    protected function configure() {
        parent::configure();
        $this->setName('print-vars')
            ->setDescription('Print the CFG Variables')
            ->addOption('image', 'i', InputOption::VALUE_REQUIRED, "filename to generate as image", '');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $components = parent::execute($input, $output);
        $image = $input->getOption("image");
        if ($image) {
            $parts = explode('.', $image);
            (new Printer\GraphViz)->printVars($components['cfg'])->export(end($parts), $image);
        } else {
            echo (new Printer\Text)->printVars($components['cfg']);
        }
    }
 
}