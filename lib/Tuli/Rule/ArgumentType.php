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
use Tuli\Rule;
use Tuli\Type;

class ArgumentType implements Rule {
    
    public function getName() {
        return "Function and Method Call Argument Types";
    }

    public function execute(array $components) {
        $errors = [];
        foreach ($components['functionLookup'] as $name => $functions) {
            $calls = $components['callResolver']->getCallsForFunction($name);
            foreach ($functions as $func) {
                foreach ($calls as $call) {
                    $errors = array_merge($errors, $this->verifyCall($func, $call[0], $components, $name));
                }
            }
        }
        foreach ($components['internalTypeInfo']->functions as $name => $arginfo) {
            $calls = $components['callResolver']->getCallsForFunction($name);
            foreach ($calls as $call) {
                $errors = array_merge($errors, $this->verifyInternalCall($arginfo, $call[0], $components, $name));
            }
        }
        foreach ($components['methodCalls'] as $call) {
            if (!$call->name instanceof Operand\Literal) {
                // Variable method call
                continue;
            }
            if ($call->var->type->type !== Type::TYPE_OBJECT) {
                // We don't know the type
                continue;
            }
            $name = strtolower($call->var->type->userType);
            if (!isset($components['resolves'][$name])) {
                // Could not find class
                continue;
            }
            foreach ($components['resolves'][$name] as $cn => $class) {
                // For every possible class that can resolve the type
                $method = $this->findMethod($class, $name);
                if (!$method) {
                    // Class does not *directly* implement method
                    continue;
                }
                $errors = array_merge($errors, $this->verifyCall($method, $call, $components, $cn . "->" . $name));
            }
        }
        return $errors;
    }

    protected function findMethod($class, $name) {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Stmt\ClassMethod) {
                if (strtolower($stmt->name->value) === $name) {
                    return $stmt;
                }
            }
        }
        if ($name !== '__call') {
            return $this->findMethod($class, '__call');
        }
        return null;
    }


    protected function verifyCall($func, $call, $components, $name) {
        $errors = [];
        foreach ($func->params as $idx => $param) {
            if (!isset($call->args[$idx])) {
                if (!$param->defaultVar) {
                    $errors[] = ["Missing required argument $idx for call $name()", $call];
                }
                continue;
            }
            if ($param->type) {
                $type = Type::fromDecl($param->type->value);
            } else {
                $type = Type::extractTypeFromComment("param", $param->function->getAttribute('doccomment'), $param->name->value);
                if (Type::mixed()->equals($type)) {
                    continue;
                }
            }
            if (!$components['typeResolver']->resolves($call->args[$idx]->type, $type)) {
                $errors[] = ["Type mismatch on $name() argument $idx, found {$call->args[$idx]->type} expecting {$type}", $call];
            }
        }
        return $errors;
    }

    protected function verifyInternalCall($func, $call, $components, $name) {
        $errors = [];
        foreach ($func['params'] as $idx => $param) {
            if (!isset($call->args[$idx])) {
                if (substr($param['name'], -1) !== '=') {
                    $errors[] = ["Missing required argument $idx for call $name()", $call];
                }
                continue;
            }
            if ($param['type']) {
                $type = Type::fromDecl($param['type']);
                if (is_string($call->args[$idx]->type)) {
                    $call->args[$idx]->type = Type::fromDecl($call->args[$idx]->type);
                }
                if (!$components['typeResolver']->resolves($call->args[$idx]->type, $type)) {
                    $errors[] = ["Type mismatch on $name() argument $idx, found {$call->args[$idx]->type} expecting {$type}", $call];
                }
            }
        }
        return $errors;
    }

}