<?php

namespace Tuli\Rule;

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
        foreach ($components['methodCalls'] as $call) {
            if (!$call->name instanceof Operand\Literal) {
                // Variable method call
                continue;
            }
            if ($call->var->type->type !== Type::TYPE_USER) {
                // We don't know the type
                continue;
            }
            $name = strtolower($call->var->type->userType);
            if (!isset($components['typeMatrix'][$name])) {
                // Could not find class
                continue;
            }
            foreach ($components['typeMatrix'][$name] as $class) {
                // For every possible class that can resolve the type
                $cn = $class->name->value;
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
            if (!isset($call->args[$idx]) && !$param->defaultVar) {
                $errors[] = ["Missing required argument $idx for call $name()", $call];
                continue;
            }
            if ($param->type && !$components['typeResolver']->resolves($call->args[$idx]->type, Type::fromDecl($param->type->value))) {
                $errors[] = ["Type mismatch on $name() argument $idx, found {$call->args[$idx]->type} expecting {$param->type->value}", $call];
            }
        }
        return $errors;
    }

}