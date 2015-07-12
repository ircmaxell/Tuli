<?php

namespace Tuli;

class TypeResolver {
    
    protected $components;

    public function __construct(array $components) {
        $this->components = $components;
    }

    public function allowsNull(Type $type) {
        if (($type->type & Type::NULL) !== 0) {
            return true;
        }
        return false;
    }

    public function resolves(Type $a, Type $b) {
        if ($a->equals($b)) {
            return true;
        }
        if ($a->type === Type::TYPE_LONG && $b->type === Type::TYPE_DOUBLE) {
            return true;
        }
        if (($b->type & $a->type) === $a->type) {
            return true;
        }
        if ($a->type === Type::TYPE_USER && $b->type === Type::TYPE_USER) {
            $aname = strtolower($a->userType);
            $bname = strtolower($b->userType);

            if (isset($this->components['resolves'][$bname][$aname])) {
                return true;
            }
            // Lookup class tree to see if A is a subtype of B
        }
        return false;
    }

}