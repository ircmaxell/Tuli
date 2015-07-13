<?php

namespace Tuli\Rule;

use Tuli\Rule;
use Tuli\Type;
use PHPCfg\Operand;
use PHPCfg\Op;

class ConstructorType extends ArgumentType {
    
    public function getName() {
        return "Constructor Argument Types";
    }

    public function execute(array $components) {
        $errors = [];
        foreach ($components['newCalls'] as $new) {
        	$types = [];
        	if ($new->class instanceof Operand\Literal) {
        		$types[] = $new->class->value;
        	} elseif ($new->class->type->type !== Type::TYPE_USER) {
        		$errors[] = ["Unknown class type for new call", $new];
        		continue;
        	} else {
        		$types = $new->class->type->userTypes;
        	}
        	foreach ($types as $type) {
        		$name = strtolower($type);
        		if (!isset($components['resolves'][$name])) {
        			$errors[] = ["Could not find class definition for $type", $new];
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