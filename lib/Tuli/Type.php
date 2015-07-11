<?php

namespace Tuli;

class Type {
	const TYPE_UNKNOWN = -1;
	const TYPE_VOID = 1;
	const TYPE_LONG = 2;
	const TYPE_DOUBLE = 3;
	const TYPE_STRING = 4;
	const TYPE_BOOLEAN = 5;
	const TYPE_NULL = 6;
	const TYPE_MIXED = 7;
	const TYPE_NUMERIC = 8;
	const TYPE_USER = 9;
	const TYPE_ARRAY = 10;
	const TYPE_HASH = 11;
	const TYPE_CALLABLE = 12;
	const TYPE_OBJECT = 13; // unknown object type

	public $type = 0;
	public $subType = 0;
	public $userType = '';

    public function __construct($type, Type $subType = null, $userType = '') {
        $this->type = $type;
        if ($this->type == self::TYPE_ARRAY) {
            if ($subType) {
                $this->subType = $subType;
            } else {
                throw new \InvalidArgumentException("Complex type must have subtype specified");
            }
        } elseif ($subType) {
            throw new \InvalidArgumentException('SubTypes should only be provided to complex types');
        }
        if ($type === self::TYPE_USER) {
            if (empty($userType)) {
                throw new \InvalidArgumentException('User type must have a specifier');
            }
            $this->userType = $userType;
        } elseif ($userType) {
            throw new \InvalidArgumentException('User type information should only be provided to user types');
        }
    }

    public function __toString() {
    	switch ($this->type) {
    		case Type::TYPE_BOOLEAN:
    			return 'bool';
    		case Type::TYPE_LONG:
    			return 'int';
    		case Type::TYPE_DOUBLE:
    			return 'float';
    		case Type::TYPE_UNKNOWN:
    			return 'unknown';
    		case Type::TYPE_VOID:
    			return 'void';
    		case Type::TYPE_ARRAY:
    		case Type::TYPE_HASH:
    			return 'array';
    		case Type::TYPE_OBJECT:
    			return 'object';
    		case Type::TYPE_USER:
    			return $this->userType;
    		case Type::TYPE_MIXED:
    			return 'mixed';
    		case Type::TYPE_CALLABLE:
    			return 'callable';
    		case Type::TYPE_NULL:
    			return 'null';
    	}
    	throw new \RuntimeException("Unknown type thrown");
    }

    public static function fromDecl($decl) {
    	if ($decl instanceof Type) {
    		return $decl;
    	}
    	switch (strtolower($decl)) {
    		case 'bool':
    			return new Type(Type::TYPE_BOOLEAN);
    		case 'int':
    			return new Type(Type::TYPE_LONG);
    		case 'float':
    			return new Type(Type::TYPE_DOUBLE);
    		case 'string':
    			return new Type(Type::TYPE_STRING);
    		case 'array':
    			return new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_UNKNOWN));
    		case 'callable':
    			return new Type(Type::TYPE_CALLABLE);
    		default:
    			return new Type(Type::TYPE_USER, null, $decl);

    	}
    }

    public static function fromValue($value) {
    	if (is_int($value)) {
    		return new Type(Type::TYPE_LONG);
    	} elseif (is_bool($value)) {
    		return new Type(Type::TYPE_BOOLEAN);
    	} elseif (is_double($value)) {
    		return new Type(Type::TYPE_DOUBLE);
    	} elseif (is_string($value)) {
    		return new Type(Type::TYPE_STRING);
    	}
    	throw new \RuntimeException("Unknown value type found: " . gettype($value));
    }

    public function equals($type) {
    	if (is_string($type)) {
    		$type = Type::fromDecl($type);
    	}
    	if ($type->type !== $this->type) {
    		return false;
    	}
    	if ($type->type === Type::TYPE_ARRAY) {
    		return $this->subType->equals($type->subType);
    	}
    	if ($type->type === Type::TYPE_USER) {
    		return strtolower($type->userType) === strtolower($this->userType);
    	}
    	return true;
    }

    public function resolves($type) {
    	if (is_string($type)) {
    		$type = Type::fromDecl($type);
    	}
    	if ($this->equals($type)) {
    		return true;
    	}
    	if ($this->type === Type::TYPE_LONG && $type->type === Type::TYPE_DOUBLE) {
    		return true;
    	}
    	return false;
    }
}