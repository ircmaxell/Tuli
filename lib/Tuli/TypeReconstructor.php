<?php

namespace Tuli;

use PHPCfg\Block;
use PHPCfg\Operand;
use PHPCfg\Op;
use Gliph\Graph\DirectedAdjacencyList;

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
                $unresolved[$op] = new Type(Type::TYPE_UNKNOWN);
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

    protected function computeMergedType(array $types) {
        if (count($types) === 1) {
            return $types[0];
        }
        $same = null;
        foreach ($types as $key => $type) {
            if (is_string($type)) {
                $type = $types[$key] = Type::fromDecl($type);
            } elseif (!$type instanceof Type) {
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
        $value = 0;
        $userTypes = [];
        $subTypes = [];
        foreach ($types as $type) {
            $value |= $type->type;
            $userTypes = array_merge($userTypes, $type->userTypes);
            $subTypes = array_merge($subTypes, $type->subTypes);
        }
        return new Type($value, $subTypes, $userTypes);
    }

    protected function resolveVar(Operand $var, \SplObjectStorage $resolved) {
        $types = [];
        foreach ($var->ops as $prev) {
            $type = $this->resolveVarOp($var, $prev, $resolved);
            if ($type) {
                foreach ($type as $t) {
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
                    return [new Type(Type::TYPE_MIXED)];
                }
                break;
            case 'Expr_Assign':
            case 'Expr_AssignRef':
                if ($resolved->contains($op->expr)) {
                    return [$resolved[$op->expr]];
                }
                break;
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
            case 'Expr_InstanceOf':
            case 'Expr_Isset':
                return [new Type(Type::TYPE_BOOLEAN)];
            case 'Expr_BinaryOp_BitwiseAnd':
            case 'Expr_BinaryOp_BitwiseOr':
            case 'Expr_BinaryOp_BitwiseXor':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_STRING, Type::TYPE_STRING]:
                            return [new Type(Type::TYPE_STRING)];
                        default:
                            return [new Type(Type::TYPE_LONG)];
                    }
                }
                break;
            case 'Expr_BitwiseNot':
                if ($resolved->contains($op->expr)) {
                    if ($resolved[$op->expr]->type === Type::TYPE_STRING) {
                        return [new Type(Type::TYPE_STRING)];
                    }
                    return [new Type(Type::TYPE_LONG)];
                }
                break;
            case 'Expr_BinaryOp_Div':
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
            case 'Expr_BinaryOp_Mul':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_LONG, Type::TYPE_LONG]:
                            return [new Type(Type::TYPE_LONG)];
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
                        case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_MIXED, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_MIXED]:
                        case [Type::TYPE_NUMERIC, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_NUMERIC]:
                            return [new Type(Type::TYPE_DOUBLE)];
                        case [Type::TYPE_MIXED, Type::TYPE_MIXED]:
                        case [Type::TYPE_MIXED, Type::TYPE_LONG]:
                        case [Type::TYPE_LONG, Type::TYPE_MIXED]:
                        case [Type::TYPE_NUMERIC, Type::TYPE_LONG]:
                        case [Type::TYPE_LONG, Type::TYPE_NUMERIC]:
                        case [Type::TYPE_NUMERIC, Type::TYPE_MIXED]:
                        case [Type::TYPE_MIXED, Type::TYPE_NUMERIC]:
                        case [Type::TYPE_NUMERIC, Type::TYPE_NUMERIC]:
                            return [new Type(Type::TYPE_NUMERIC)];
                        case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
                            $sub = $this->computeMergedType(array_merge($resolved[$op->left]->subTypes), $resolved[$op->right]->subTypes);
                            if ($sub) {
                                return [new Type(Type::TYPE_ARRAY, [$sub])];
                            }
                            return [new Type(Type::TYPE_ARRAY)];
                        default:
                            return [new Type(Type::TYPE_MIXED)];
                    }
                }

                break;
            case 'Expr_BinaryOp_Concat':
            case 'Expr_Cast_String':
            case 'Expr_ConcatList':
                return [new Type(Type::TYPE_STRING)];
            case 'Expr_BinaryOp_Mod':
            case 'Expr_BinaryOp_ShiftLeft':
            case 'Expr_BinaryOp_ShiftRight':
            case 'Expr_Cast_Int':
            case 'Expr_Print':
                return [new Type(Type::TYPE_LONG)];
            case 'Expr_Cast_Double':
                return [new Type(Type::TYPE_DOUBLE)];
            case 'Expr_Cast_Object':
                if ($resolved->contains($op->expr)) {
                    if ($resolved[$op->expr]->type === Type::TYPE_USER) {
                        return [$resolved[$op->expr]];
                    }
                    return [new Type(Type::TYPE_USER, [], ['stdClass'])];
                }
                break;
            case 'Expr_Clone':
                if ($resolved->contains($op->expr)) {
                    return [$resolved[$op->expr]];
                }
                break;
            case 'Expr_Closure':
                return [new Type(Type::TYPE_USER, [], ["Closure"])];
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
                                return [new Type(Type::TYPE_MIXED)];
                            }
                            return [Type::fromDecl($type['return'])];
                        }
                    }
                }
                // we can't resolve the function
                return [new Type(Type::TYPE_MIXED)];
                break;
            case 'Expr_List':
                if ($op->result === $var) {
                    return [new Type(Type::TYPE_ARRAY)];
                }
                // TODO: infer this
                return [new Type(Type::TYPE_MIXED)];
            case 'Expr_New':
                if ($op->class instanceof Operand\Literal) {
                    return [new Type(Type::TYPE_USER, [], [$op->class->value])];
                } elseif ($resolved->contains($op->class)) {
                    $type = $resolved[$op->class];
                    if ($type->type === Type::TYPE_USER) {
                        return [new Type(Type::TYPE_USER, $type->userTypes)];
                    }
                    return [new Type(Type::TYPE_OBJECT)];
                }
                return [new Type(Type::TYPE_OBJECT)];
            case 'Expr_Param':
                if ($op->type) {
                    $type = Type::fromDecl($op->type->value);
                    if ($op->defaultVar) {
                        if ($op->defaultBlock->children[0]->getType() === "Expr_ConstFetch" && strtolower($op->defaultBlock->children[0]->name->value) === "null") {
                            $type->type |= Type::TYPE_NULL;
                        }
                    }
                    return [$type];
                }
                return [Type::extractTypeFromComment("param", $op->function->getAttribute('doccomment'), $op->name->value)];
            case 'Expr_PropertyFetch':
                if (!$op->name instanceof Operand\Literal) {
                    // variable property fetch
                    return [new Type(Type::TYPE_MIXED)];
                }
                $lowerName = strtolower($op->name->value);
                $objType = false;
                if ($op->var instanceof Operand\BoundVariable && $op->var->scope === Operand\BoundVariable::SCOPE_OBJECT) {
                    // $this reference
                    assert($op->var->extra instanceof Operand\Literal);
                    $objType = Type::fromDecl($op->var->extra->value);
                } elseif ($resolved->contains($op->var)) {
                    $objType = $resolved[$op->var];
                } else {
                    return false;
                }
                if ($objType && $objType->type === Type::TYPE_USER) {
                    $types = [];
                    foreach ($objType->userTypes as $ut) {
                        $ut = strtolower($ut);
                        if (!isset($this->components['resolves'][$ut])) {
                            // unknown type
                            return [new Type(Type::TYPE_MIXED)];
                        }
                        foreach ($this->components['resolves'][$ut] as $name => $class) {
                            // Lookup property on class
                            $property = $this->findProperty($class, $lowerName);
                            if ($property) {
                                if ($property->type) {
                                    $types[] = $property->type;
                                } else {
                                    echo "Untyped\n";
                                    // untyped property
                                    return [new Type(Type::TYPE_MIXED)];
                                }
                            }
                        }
                    }
                    if ($types) {
                        return $types;
                    }
                }
                return [new Type(Type::TYPE_MIXED)];
            case 'Expr_Yield':
            case 'Expr_Include':
            
            case 'Expr_StaticPropertyFetch':
            case 'Stmt_Property':
                // TODO: we may be able to determine these...
                return [new Type(Type::TYPE_MIXED)];
            case 'Expr_TypeAssert':
                return [Type::fromDecl($op->assertedType)];
            case 'Expr_TypeUnAssert':
                if ($resolved->contains($op->assert->expr)) {
                    return [$resolved[$op->assert->expr]];
                }
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                if ($resolved->contains($op->expr)) {
                    switch ($resolved[$op->expr]->type) {
                        case Type::TYPE_LONG:
                        case Type::TYPE_DOUBLE:
                            return [$resolved[$op->expr]];
                    }
                    return [new Type(Type::TYPE_NUMERIC)];
                }
                break;

            case 'Expr_Eval':
                return [new Type(Type::TYPE_MIXED)];
            case 'Iterator_Key':
                if ($resolved->contains($op->var)) {
                    // TODO: implement this as well
                    return [new Type(Type::TYPE_MIXED)];
                }
                break;
            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [new Type(Type::TYPE_VOID)];
            case 'Iterator_Valid':
                return [new Type(Type::TYPE_BOOLEAN)];
            case 'Iterator_Value':
                if ($resolved->contains($op->var)) {
                    if ($resolved[$op->var]->subTypes) {
                        return $resolved[$op->var]->subTypes;
                    }
                    return [new Type(Type::TYPE_MIXED)];
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
                            return [new Type(Type::TYPE_BOOLEAN)];
                        case 'null':
                            return [new Type(Type::TYPE_NULL)];
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
                return [new Type(Type::TYPE_MIXED)];
            case 'Expr_ClassConstFetch':
                //TODO
                $classes = [];
                if ($op->class instanceof Operand\Literal) {
                    $class = strtolower($op->class->value);
                    return $this->resolveClassConstant($class, $op, $resolved);
                } elseif ($resolved->contains($op->class)) {
                    $type = $resolved[$op->class];
                    if ($type->type !== Type::TYPE_USER) {
                        // give up
                        return [new Type(Type::TYPE_MIXED)];
                    }
                    $types = [];
                    foreach ($type->userTypes as $type) {
                        $try = $this->resolveClassConstant(strtolower($type), $op, $resolved);
                        if ($try) {
                            $types = array_merge($types, $try);
                        } else {
                            return false;
                        }
                    }
                    if ($types) {
                        return $types;
                    }
                    return [new Type(Type::TYPE_MIXED)];
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
                if (strtolower($stmt->name->value) === $name) {
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
            return [new Type(Type::TYPE_MIXED)];
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
            return [new Type(Type::TYPE_MIXED)];
        }
        return $types;
    }

    private function resolveMethodCall($class, $name, Op $op, \SplObjectStorage $resolved) {
        $name = strtolower($name->value);
        if ($resolved->contains($class)) {
            $userTypes = [];
            if ($resolved[$class]->type === Type::TYPE_STRING) {
                if (!$class instanceof Operand\Literal) {
                    // variable class name, for now just return object
                    return [new Type(Type::TYPE_OBJECT)];
                }
                $userTypes = [$class->value];
            } elseif ($resolved[$class]->type !== Type::TYPE_USER) {
                return [new Type(Type::TYPE_MIXED)];
            } else {
                $userTypes = $resolved[$class]->userTypes;
            }
            $types = [];
            foreach ($userTypes as $ut) {
                $className = strtolower($ut);
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
                    return [new Type(Type::TYPE_MIXED)];
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
            }
            if (!empty($types)) {
                return $types;
            }
            return [new Type(Type::TYPE_MIXED)];
        }
    }

}