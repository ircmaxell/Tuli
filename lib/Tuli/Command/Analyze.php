<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli\Command;

use PHPCfg\Op;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tuli\Command;
use Tuli\Rule;

class Analyze extends Command {

    /**
     * @var Tuli\Rule[]
     */
    protected $rules = [];

    protected function configure() {
        parent::configure();
        $this->setName('analyze')
            ->setDescription('Analyze the provided files');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $components = parent::execute($input, $output);
        $this->loadRules();
        $errors = [];
        foreach ($this->rules as $rule) {
            echo "Executing rule: " . $rule->getName() . "\n";
            $errors = array_merge($errors, $rule->execute($components));
        }
        foreach ($errors as $error) {
            $this->emitError($error[0], $error[1]);
        }
    }


    public function loadRules() {
        $this->rules[] = new Rule\ArgumentType;
        $this->rules[] = new Rule\ReturnType;
        $this->rules[] = new Rule\ConstructorType;
    }

    protected function emitError($msg, Op $op) {
        echo $msg;
        echo " ";
        echo $op->getFile() . ":" . $op->getLine();
        echo "\n";
    }

}