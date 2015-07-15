<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli\Rule;

use PHPCfg\Op;
use PHPCfg\Operand;
use Tuli\Type;

class ConstructorType extends ArgumentType {
    
    public function getName() {
        return "Constructor Argument Types";
    }

    public function execute(array $components) {
        $errors = [];
        foreach ($components['newCalls'] as $new) {
            $type = null;
            if ($new->class instanceof Operand\Literal) {
                $type = $new->class->value;
            } elseif ($new->class->type->type !== Type::TYPE_OBJECT) {
                $errors[] = ["Unknown class type for new call", $new];
                continue;
            } else {
                $type = $new->class->type->userType;
            }
            $name = strtolower($type);
            if (!isset($components['resolves'][$name])) {
                if (isset($components['internalTypeInfo']->methods[$name])) {
                    // TODO
                } else {
                    $errors[] = ["Could not find class definition for $type", $new];
                }
                continue;
            }
            foreach ($components['resolves'][$name] as $sub => $class) {
                $constructor = $this->findConstructor($class);
                if ($constructor) {
                    // validate parameters!!!
                    $errors = array_merge($errors, $this->verifyCall($constructor, $new, $components, "{$class->name->value}::__construct"));
                }
            }
        }
        return $errors;
    }

    protected function findConstructor($class) {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Stmt\ClassMethod) {
                if (strtolower($stmt->name->value) === "__construct") {
                    return $stmt;
                }
            }
        }
        return null;
    }

}