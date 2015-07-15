<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

class TypeResolver {
    
    protected $components;

    protected $callableUnion;

    public function __construct(array $components) {
        $this->components = $components;
        $this->callableUnion = Type::fromDecl("string|array|object");
    }

    public function resolves(Type $a, Type $b) {
        if ($a->equals($b)) {
            return true;
        }
        if ($b->type === Type::TYPE_CALLABLE) {
            return $this->resolves($a, $this->callableUnion);
        }
        if ($a->type === Type::TYPE_OBJECT && $b->type === Type::TYPE_OBJECT) {
            return $this->checkUserTypes($a->userType, $b->userType);
        }
        if ($a->type === Type::TYPE_LONG && $b->type === Type::TYPE_DOUBLE) {
            return true;
        }
        if ($a->type === Type::TYPE_ARRAY && $b->type === Type::TYPE_ARRAY) {
            if (!$b->subTypes) {
                return true;
            }
            if (!$a->subTypes) {
                // We need a specific array
                return false;
            }
            return ($this->resolves($a->subTypes[0], $b->subTypes[0]));
        }
        if ($a->type === Type::TYPE_UNION) {
            foreach ($a->subTypes as $st) {
                if ($this->resolves($st, $b)) {
                    // All must resolve
                    return false;
                }
            }
            return true;
        }
        if ($a->type === Type::TYPE_INTERSECTION) {
            foreach ($a->subTypes as $st) {
                if ($this->resolves($st, $b)) {
                    // At least one resolves it
                    return true;
                }
            }
            return false;
        }
        if ($b->type === Type::TYPE_UNION) {
            foreach ($b->subTypes as $st) {
                if ($this->resolves($a, $st)) {
                    // At least one resolves it
                    return true;
                }
            }
            return false;
        }
        if ($b->type === Type::TYPE_INTERSECTION) {
            foreach ($b->subTypes as $st) {
                if (!$this->resolves($a, $st)) {
                    // At least one resolves it
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private function checkUserTypes($a, $b) {
        $a = strtolower($a);
        $b = strtolower($b);
        if (isset($this->components['resolves'][$b][$a])) {
            return true;
        }
        // TODO: take care of internal types
        return false;
    }

}