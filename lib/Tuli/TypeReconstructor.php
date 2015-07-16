<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

use PHPCfg\Assertion;
use PHPCfg\Op;
use PHPCfg\Operand;

class TypeReconstructor {

    protected $components;

    public function resolve(array $components) {
        $this->components = $components;
        // First resolve properties
        $this->resolveAllProperties();
        $resolved = new \SplObjectStorage;
        $unresolved = new \SplObjectStorage;
        foreach ($components['variables'] as $op) {
            if (!empty($op->type) && $op->type->type !== Type::TYPE_UNKNOWN) {
                $resolved[$op] = $op->type;
            } elseif ($op instanceof Operand\BoundVariable && $op->scope === Operand\BoundVariable::SCOPE_OBJECT) {
                $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
            } elseif ($op instanceof Operand\Literal) {
                $resolved[$op] = $op->type = Type::fromValue($op->value);
            } else {
                $unresolved[$op] = Type::unknown();
            }
        }

        if (count($unresolved) === 0) {
            // short-circuit
            return;
        }

        $round = 1;
        do {
            echo "Round " . $round++ . " (" . count($unresolved) . " unresolved variables out of " . count($components['variables']) . ")\n";
            $start = count($resolved);
            $i = 0;
            $toRemove = [];
            foreach ($unresolved as $k => $var) {
                $i++;
                if ($i % 10 === 0) {
                    echo ".";
                }
                if ($i % 800 === 0) {
                    echo "\n";
                }

                  $type = $this->resolveVar($var, $resolved);
                if ($type) {
                    $toRemove[] = $var;
                    $resolved[$var] = $type;
                }
            }
            foreach ($toRemove as $remove) {
                $unresolved->detach($remove);
            }
            echo "\n";
        } while (count($unresolved) > 0 && $start < count($resolved));
        foreach ($resolved as $var) {
            $var->type = $resolved[$var];
        }
        foreach ($unresolved as $var) {
            $var->type = $unresolved[$var];
        }
        echo count($unresolved) . " Variables Left Unresolved\n";
    }

    /**
     * @param Type[] $types
     *
     * @return Type
     */
    protected function computeMergedType(array $types) {
        if (count($types) === 1) {
            return $types[0];
        }
        $same = null;
        foreach ($types as $key => $type) {
            if (!$type instanceof Type) {
                var_dump($types);
                throw new \RuntimeException("Invalid type found");
            }
            if (is_null($same)) {
                $same = $type;
            } elseif ($same && !$same->equals($type)) {
                $same = false;
            }
            if ($type->type === Type::TYPE_UNKNOWN) {
                return false;
            }
        }
        if ($same) {
            return $same;
        }
        return (new Type(Type::TYPE_UNION, $types))->simplify();
    }

    protected function resolveVar(Operand $var, \SplObjectStorage $resolved) {
        $types = [];
        foreach ($var->ops as $prev) {
            $type = $this->resolveVarOp($var, $prev, $resolved);
            if ($type) {
                if (!is_array($type)) {
                    throw new \LogicException("Handler for " . get_class($prev) . " returned a non-array");
                }
                foreach ($type as $t) {
                    assert($t instanceof Type);
                    $types[] = $t;
                }
            } else {
                return false;
            }
        }
        if (empty($types)) {
            return false;
        }
        return $this->computeMergedType($types);
    }

    protected function resolveVarOp(Operand $var, Op $op, \SplObjectStorage $resolved) {
        switch ($op->getType()) {
            case 'Expr_Array':
                $types = [];
                foreach ($op->values as $value) {
                    if (!isset($resolved[$value])) {
                        return false;
                    }
                    $types[] = $resolved[$value];
                }
                if (empty($types)) {
                    return [new Type(Type::TYPE_ARRAY)];
                }
                $r = $this->computeMergedType($types);
                if ($r) {
                    return [new Type(Type::TYPE_ARRAY, [$r])];
                }
            case 'Expr_Cast_Array':
                // Todo: determine subtypes better
                return [new Type(Type::TYPE_ARRAY)];
            case 'Expr_ArrayDimFetch':
                if ($resolved->contains($op->var)) {
                    // Todo: determine subtypes better
                    $type = $resolved[$op->var];
                    if ($type->subTypes) {
                        return $type->subTypes;
                    }
                    if ($type->type === Type::TYPE_STRING) {
                        return [$type];
                    }
                    return [Type::mixed()];
                }
                break;
            case 'Expr_Assign':
            case 'Expr_AssignRef':
                if ($resolved->contains($op->expr)) {
                    return [$resolved[$op->expr]];
                }
                break;
            case 'Expr_InstanceOf':
            case 'Expr_BinaryOp_Equal':
            case 'Expr_BinaryOp_NotEqual':
            case 'Expr_BinaryOp_Greater':
            case 'Expr_BinaryOp_GreaterOrEqual':
            case 'Expr_BinaryOp_Identical':
            case 'Expr_BinaryOp_NotIdentical':
            case 'Expr_BinaryOp_Smaller':
            case 'Expr_BinaryOp_SmallerOrEqual':
            case 'Expr_BinaryOp_LogicalAnd':
            case 'Expr_BinaryOp_LogicalOr':
            case 'Expr_BinaryOp_LogicalXor':
            case 'Expr_BooleanNot':
            case 'Expr_Cast_Bool':
            case 'Expr_Empty':
            case 'Expr_Isset':
                return [Type::bool()];
            case 'Expr_BinaryOp_BitwiseAnd':
            case 'Expr_BinaryOp_BitwiseOr':
            case 'Expr_BinaryOp_BitwiseXor':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_STRING, Type::TYPE_STRING]:
                            return [Type::string()];
                        default:
                            return [Type::int()];
                    }
                }
                break;
            case 'Expr_BitwiseNot':
                if ($resolved->contains($op->expr)) {
                    if ($resolved[$op->expr]->type === Type::TYPE_STRING) {
                        return [Type::string()];
                    }
                    return [Type::int()];
                }
                break;
            case 'Expr_BinaryOp_Div':
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
            case 'Expr_BinaryOp_Mul':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_LONG, Type::TYPE_LONG]:
                            return [Type::int()];
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
                        case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
                            return [Type::float()];
                        case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
                            $sub = $this->computeMergedType(array_merge($resolved[$op->left]->subTypes, $resolved[$op->right]->subTypes));
                            if ($sub) {
                                return [new Type(Type::TYPE_ARRAY, [$sub])];
                            }
                            return [new Type(Type::TYPE_ARRAY)];
                        default:
                            throw new \RuntimeException("Math op on unknown types {$resolved[$op->left]} + {$resolved[$op->right]}");
                    }
                }

                break;
            case 'Expr_BinaryOp_Concat':
            case 'Expr_Cast_String':
            case 'Expr_ConcatList':
                return [Type::string()];
            case 'Expr_BinaryOp_Mod':
            case 'Expr_BinaryOp_ShiftLeft':
            case 'Expr_BinaryOp_ShiftRight':
            case 'Expr_Cast_Int':
            case 'Expr_Print':
                return [Type::int()];
            case 'Expr_Cast_Double':
                return [Type::float()];
            case 'Expr_Cast_Object':
                if ($resolved->contains($op->expr)) {
                    if ($resolved[$op->expr]->type->resolves(Type::object())) {
                        return [$resolved[$op->expr]];
                    }
                    return [new Type(Type::TYPE_OBJECT, [], 'stdClass')];
                }
                break;
            case 'Expr_Clone':
                if ($resolved->contains($op->expr)) {
                    return [$resolved[$op->expr]];
                }
                break;
            case 'Expr_Closure':
                return [new Type(Type::TYPE_OBJECT, [], "Closure")];
            case 'Expr_FuncCall':
                if ($op->name instanceof Operand\Literal) {
                    $name = strtolower($op->name->value);
                    if (isset($this->components['functionLookup'][$name])) {
                        $result = [];
                        foreach ($this->components['functionLookup'][$name] as $func) {
                            if ($func->returnType) {
                                $result[] = Type::fromDecl($func->returnType->value);
                            } else {
                                // Check doc comment
                                $result[] = Type::extractTypeFromComment("return", $func->getAttribute('doccomment'));
                            }
                        }
                        return $result;
                    } else {
                        if (isset($this->components['internalTypeInfo']->functions[$name])) {
                            $type = $this->components['internalTypeInfo']->functions[$name];
                            if (empty($type['return'])) {
                                return false;
                            }
                            return [Type::fromDecl($type['return'])];
                        }
                    }
                }
                // we can't resolve the function
                return false;
            case 'Expr_List':
                if ($op->result === $var) {
                    return [new Type(Type::TYPE_ARRAY)];
                }
                // TODO: infer this
                return false;
            case 'Expr_New':
                $type = $this->getClassType($op->class, $resolved);
                if ($type) {
                    return [$type];
                }
                return [Type::object()];
            case 'Expr_Param':
                $docType = Type::extractTypeFromComment("param", $op->function->getAttribute('doccomment'), $op->name->value);
                if ($op->type) {
                    $type = Type::fromDecl($op->type->value);
                    if ($op->defaultVar) {
                        if ($op->defaultBlock->children[0]->getType() === "Expr_ConstFetch" && strtolower($op->defaultBlock->children[0]->name->value) === "null") {
                            $type = (new Type(Type::TYPE_UNION, [$type, Type::null()]))->simplify();
                        }
                    }
                    if ($docType !== Type::mixed() && $this->components['typeResolver']->resolves($docType, $type)) {
                        // return the more specific
                        return [$docType];
                    }
                    return [$type];
                }
                return [$docType];
            case 'Expr_PropertyFetch':
            case 'Expr_StaticPropertyFetch':
                if (!$op->name instanceof Operand\Literal) {
                    // variable property fetch
                    return [Type::mixed()];
                }
                $propName = $op->name->value;
                if ($op instanceof Op\Expr\StaticPropertyFetch) {
                    $objType = $this->getClassType($op->class, $resolved);
                } else {
                    $objType = $this->getClassType($op->var, $resolved);
                }
                if ($objType) {
                    return $this->resolveProperty($objType, $propName);
                }
                return false;
            case 'Expr_Yield':
            case 'Expr_Include':
            
                // TODO: we may be able to determine these...
                return false;
            case 'Expr_Assertion':
                $tmp = $this->processAssertion($op->assertion, $op->expr, $resolved);
                if ($tmp) {
                    return [$tmp];
                }
                return false;
            case 'Expr_TypeUnAssert':
                throw new \RuntimeException("Unassertions should not occur anymore");
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                if ($resolved->contains($op->expr)) {
                    switch ($resolved[$op->expr]->type) {
                        case Type::TYPE_LONG:
                        case Type::TYPE_DOUBLE:
                            return [$resolved[$op->expr]];
                    }
                    return [Type::numeric()];
                }
                break;

            case 'Expr_Eval':
                return false;
            case 'Iterator_Key':
                if ($resolved->contains($op->var)) {
                    // TODO: implement this as well
                    return false;
                }
                break;
            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Iterator_Valid':
                return [Type::bool()];
            case 'Iterator_Value':
                if ($resolved->contains($op->var)) {
                    if ($resolved[$op->var]->subTypes) {
                        return $resolved[$op->var]->subTypes;
                    }
                    return false;
                }
                break;
            case 'Expr_StaticCall':
                return $this->resolveMethodCall($op->class, $op->name, $op, $resolved);
            case 'Expr_MethodCall':
                return $this->resolveMethodCall($op->var, $op->name, $op, $resolved);
            case 'Expr_ConstFetch':
                if ($op->name instanceof Operand\Literal) {
                    $constant = strtolower($op->name->value);
                    switch ($constant) {
                        case 'true':
                        case 'false':
                            return [Type::bool()];
                        case 'null':
                            return [Type::null()];
                        default:
                            if (isset($this->components['constants'][$op->name->value])) {
                                $return = [];
                                foreach ($this->components['constants'][$op->name->value] as $value) {
                                    if (!$resolved->contains($value->value)) {
                                        return false;
                                    }
                                    $return[] = $resolved[$value->value];
                                }
                                return $return;
                            }
                    }
                }
                return false;
            case 'Expr_ClassConstFetch':
                //TODO
                $classes = [];
                if ($op->class instanceof Operand\Literal) {
                    $class = strtolower($op->class->value);
                    return $this->resolveClassConstant($class, $op, $resolved);
                } elseif ($resolved->contains($op->class)) {
                    $type = $resolved[$op->class];
                    if ($type->type !== Type::TYPE_OBJECT || empty($type->userType)) {
                        // give up
                        return false;
                    }
                    return $this->resolveClassConstant(strtolower($type->userType), $op, $resolved);
                }
                return false;
            case 'Phi':
                $types = [];
                $resolveFully = true;
                foreach ($op->vars as $v) {
                    if ($resolved->contains($v)) {
                        $types[] = $resolved[$v];
                    } else {
                        $resolveFully = false;
                    }
                }
                if (empty($types)) {
                    return false;
                }
                $type = $this->computeMergedType($types);
                if ($type) {
                    if ($resolveFully) {
                        return [$type];
                    }
                    // leave on unresolved list to try again next round
                    $resolved[$var] = $type;
                }
                return false;
            default:
                throw new \RuntimeException("Unknown operand prefix type: " . $op->getType());
        }
        return false;
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

    protected function findProperty($class, $name) {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Stmt\Property) {
                if ($stmt->name->value === $name) {
                    return $stmt;
                }
            }
        }
        return null;
    }

    protected function resolveAllProperties() {
        foreach ($this->components['classes'] as $class) {
            foreach ($class->stmts->children as $stmt) {
                if ($stmt instanceof Op\Stmt\Property) {
                    $stmt->type = Type::extractTypeFromComment("var", $stmt->getAttribute('doccomment'));
                }
            }
        }
    }

    protected function resolveClassConstant($class, $op, $resolved) {
        $try = $class . '::' . $op->name->value;
        if (isset($this->components['constants'][$try])) {
            $types = [];
            foreach ($this->components['constants'][$try] as $const) {
                if ($resolved->contains($const->value)) {
                    $types[] = $resolved[$const->value];
                } else {
                    // Not every
                    return false;
                }
            }
            return $types;
        }
        if (!isset($this->components['resolvedBy'][$class])) {
            // can't find classes
            return false;
        }
        $types = [];
        foreach ($this->components['resolves'][$class] as $name => $_) {
            $try = $name . '::' . $op->name->value;
            if (isset($this->components['constants'][$try])) {
                foreach ($this->components['constants'][$try] as $const) {
                    if ($resolved->contains($const->value)) {
                        $types[] = $resolved[$const->value];
                    } else {
                        // Not every is resolved yet
                        return false;
                    }
                }
            }
        }
        if (empty($types)) {
            return false;
        }
        return $types;
    }

    /**
     * @param Type   $objType
     * @param string $propName
     *
     * @return Type[]|false
     */
    private function resolveProperty(Type $objType, $propName) {
        if ($objType->type === Type::TYPE_OBJECT) {
            $types = [];
            $ut = strtolower($objType->userType);
            if (!isset($this->components['resolves'][$ut])) {
                // unknown type
                return false;
            }
            foreach ($this->components['resolves'][$ut] as $class) {
                // Lookup property on class
                $property = $this->findProperty($class, $propName);
                if ($property) {
                    if ($property->type) {
                        $types[] = $property->type;
                    } else {
                        echo "Property found to be untyped: $propName\n";
                        // untyped property
                        return false;
                    }
                }
            }
            if ($types) {
                return $types;
            }
        }
        return false;
    }

    private function resolveMethodCall($class, $name, Op $op, \SplObjectStorage $resolved) {
        if (!$name instanceof Operand\Literal) {
            // Variable Method Call
            return false;
        }
        $name = strtolower($name->value);
        if ($resolved->contains($class)) {
            $userType = '';
            if ($resolved[$class]->type === Type::TYPE_STRING) {
                if (!$class instanceof Operand\Literal) {
                    // variable class name, for now just return object
                    return [Type::mixed()];
                }
                $userType = $class->value;
            } elseif ($resolved[$class]->type !== Type::TYPE_OBJECT) {
                return false;
            } else {
                $userType = $resolved[$class]->userType;
            }
            $types = [];
            $className = strtolower($userType);
            if (!isset($this->components['resolves'][$className])) {
                if (isset($this->components['internalTypeInfo']->methods[$className])) {
                    $types = [];
                    foreach ($this->components['internalTypeInfo']->methods[$className]['extends'] as $child) {
                        if (isset($this->components['internalTypeInfo']->methods[$child]['methods'][$name])) {
                            $method = $this->components['internalTypeInfo']->methods[$child]['methods'][$name];
                            if ($method['return']) {
                                $types[] = Type::fromDecl($method['return']);
                            }
                        }
                    }
                    if (!empty($types)) {
                        return $types;
                    }
                }
                return false;
            }
            foreach ($this->components['resolves'][$className] as $class) {
                $method = $this->findMethod($class, $name);
                if (!$method) {
                    continue;
                }

                if (!$method->returnType) {
                    $types[] = Type::extractTypeFromComment("return", $method->getAttribute('doccomment'));
                } else {
                    $types[] = Type::fromDecl($method->returnType->value);
                }
            }
            if (!empty($types)) {
                return $types;
            }
            return false;
        }
        return false;
    }

    protected function getClassType(Operand $var, \SplObjectStorage $resolved) {
        if ($var instanceof Operand\Literal) {
            return new Type(Type::TYPE_OBJECT, [], $var->value);
        } elseif ($var instanceof Operand\BoundVariable && $var->scope === Operand\BoundVariable::SCOPE_OBJECT) {
            assert($var->extra instanceof Operand\Literal);
            return Type::fromDecl($var->extra->value);
        } elseif ($resolved->contains($var)) {
            $type = $resolved[$var];
            if ($type->type === Type::TYPE_OBJECT) {
                return $type;
            }
        }
        // We don't know the type
        return false;
    }

    protected function processAssertion(Assertion $assertion, Operand $source, \SplObjectStorage $resolved) {
        if ($assertion instanceof Assertion\TypeAssertion) {
            $tmp = $this->processTypeAssertion($assertion, $source, $resolved);
            if ($tmp) {
                return $tmp;
            }
        } elseif ($assertion instanceof Assertion\NegatedAssertion) {
            $op = $this->processAssertion($assertion->value[0], $source, $resolved);
            if ($op instanceof Type) {
                // negated type assertion
                if (isset($resolved[$source])) {
                    return $resolved[$source]->removeType($op);
                }
                // Todo, figure out how to wait for resolving
                return Type::mixed()->removeType($op);
            }
        }
        return false;
    }

    protected function processTypeAssertion(Assertion\TypeAssertion $assertion, Operand $source, \SplObjectStorage $resolved) {
        if ($assertion->value instanceof Operand) {
            if ($assertion->value instanceof Operand\Literal) {
                return Type::fromDecl($assertion->value->value);
            }
            if (isset($resolved[$assertion->value])) {
                return $resolved[$assertion->value];
            }
            return false;
        }
        $subTypes = [];
        foreach ($assertion->value as $sub) {
            $subTypes[] = $subType = $this->processTypeAssertion($sub, $source, $resolved);
            if (!$subType) {
                // Not fully resolved yet
                return false;
            }
        }
        $type = $assertion->mode === Assertion::MODE_UNION ? Type::TYPE_UNION : Type::TYPE_INTERSECTION;
        return new Type($type, $subTypes);
    }
}