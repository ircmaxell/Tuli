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
use PHPTypes\Type;
use PHPTypes\State;

class ArgumentType implements Rule {
    
    /**
     * @return string
     */
    public function getName() {
        return "Function and Method Call Argument Types";
    }

    /**
     * @param State $state
     *
     * @return array
     */
    public function execute(State $state) {
        $errors = [];
        foreach ($state->functionLookup as $name => $functions) {
            $calls = $state->callFinder->getCallsForFunction($name);
            foreach ($functions as $func) {
                foreach ($calls as $call) {
                    $errors = array_merge($errors, $this->verifyCall($func, $call[0], $state, $name));
                }
            }
        }
        foreach ($state->internalTypeInfo->functions as $name => $arginfo) {
            $calls = $state->callFinder->getCallsForFunction($name);
            foreach ($calls as $call) {
                $errors = array_merge($errors, $this->verifyInternalCall($arginfo, $call[0], $state, $name));
            }
        }
        foreach ($state->methodCalls as $call) {
            if (!$call->name instanceof Operand\Literal) {
                // Variable method call
                continue;
            }
            if (!$call->var->type || $call->var->type->type !== Type::TYPE_OBJECT) {
                // We don't know the type
                continue;
            }
            $name = strtolower($call->var->type->userType);
            if (!isset($state->classResolves[$name])) {
                // Could not find class
                continue;
            }
            foreach ($state->classResolves[$name] as $cn => $class) {
                // For every possible class that can resolve the type
                $method = $this->findMethod($class, $name);
                if (!$method) {
                    // Class does not *directly* implement method
                    continue;
                }
                $errors = array_merge($errors, $this->verifyCall($method, $call, $state, $cn . "->" . $name));
            }
        }
        return $errors;
    }

    /**
     * @return Op\Stmt\ClassMethod|null
     */
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

    /**
     * @return array
     */
    protected function verifyCall($func, $call, $state, $name) {
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
            if (!$state->resolver->resolves($call->args[$idx]->type, $type)) {
                $errors[] = ["Type mismatch on $name() argument $idx, found {$call->args[$idx]->type} expecting {$type}", $call];
            }
        }
        return $errors;
    }

    /**
     * @return array
     */
    protected function verifyInternalCall($func, $call, $state, $name) {
        $errors = [];
        foreach ($func['params'] as $idx => $param) {
            if (!isset($call->args[$idx])) {
                if (substr($param['name'], -1) !== '=') {
                    $errors[] = ["Missing required argument $idx for call $name()", $call];
                }
                continue;
            }
            if ($param['type'] && isset($call->args[$idx]->type)) {
                $type = Type::fromDecl($param['type']);
                if (is_string($call->args[$idx]->type)) {
                    $call->args[$idx]->type = Type::fromDecl($call->args[$idx]->type);
                }
                if (!$state->resolver->resolves($call->args[$idx]->type, $type)) {
                    $errors[] = ["Type mismatch on $name() argument $idx, found {$call->args[$idx]->type} expecting {$type}", $call];
                }
            }
        }
        return $errors;
    }

}