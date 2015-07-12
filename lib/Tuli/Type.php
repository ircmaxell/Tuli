<?php

namespace Tuli;

class Type {
	const TYPE_UNKNOWN  = -1;
	const TYPE_VOID     = 1;
	const TYPE_LONG     = 2;
	const TYPE_DOUBLE   = 4;
    const TYPE_NUMERIC  = 6; // 2 | 4
	const TYPE_STRING   = 8;
	const TYPE_BOOLEAN  = 16;
	const TYPE_NULL     = 32;
	const TYPE_USER     = 64;
	const TYPE_ARRAY    = 128;
	const TYPE_CALLABLE = 256;
	const TYPE_OBJECT   = 512; // unknown object type
    const TYPE_MIXED    = 1023; // all others combined
    
	public $type = 0;
	public $subType = 0;
	public $userType = '';

    public function __construct($type, Type $subType = null, $userType = '') {
        $this->type = $type;
        if ($subType) {
            $this->subType = $subType;
        }
        $this->userType = $userType;
    }

    public static function getPrimitives() {
        return [
            Type::TYPE_VOID => 'void',
            Type::TYPE_LONG => 'int',
            Type::TYPE_DOUBLE => 'float',
            Type::TYPE_STRING => 'string',
            Type::TYPE_BOOLEAN => 'bool',
            Type::TYPE_NULL => 'null',
            Type::TYPE_USER => '',
            Type::TYPE_ARRAY => 'array',
            Type::TYPE_CALLABLE => 'callable',
            Type::TYPE_OBJECT => 'object',
        ];
    }

    public function __toString() {
        $nullable = 0 !== ($this->type & Type::TYPE_NULL) ? '?' : '';
    	switch ($this->type & ~Type::TYPE_NULL) {
            case Type::TYPE_STRING:
                return $nullable . 'string';
    		case Type::TYPE_BOOLEAN:
    			return $nullable . 'bool';
    		case Type::TYPE_LONG:
    			return $nullable . 'int';
    		case Type::TYPE_DOUBLE:
    			return $nullable . 'float';
    		case Type::TYPE_UNKNOWN:
    			return $nullable . 'unknown';
    		case Type::TYPE_VOID:
    			return $nullable . 'void';
    		case Type::TYPE_ARRAY:
                if ($this->subType) {
                    return $nullable . $this->subType . '[]';
                }
    			return $nullable . 'array';
    		case Type::TYPE_OBJECT:
    			return $nullable . 'object';
    		case Type::TYPE_USER:
    			return $nullable . $this->userType;
    		case Type::TYPE_MIXED:
    			return $nullable . 'mixed';
    		case Type::TYPE_CALLABLE:
    			return $nullable . 'callable';
    	}
        if ($this->type === Type::TYPE_NULL) {
            return 'null';
        }
        $found = 0;
        $foundStrings = [];
        foreach (self::getPrimitives() as $primitive => $foundString) {
            if (0 !== ($primitive & $this->type)) {
                $found |= $primitive;
                if ($foundString === 'array' && $this->subType) {
                    $foundStrings[] = (string) $this->subType . '[]';
                } elseif ($foundString) {
                    $foundStrings[] = $foundString;
                } else {
                    die("handle classes");
                }
            }
        }
        if ($found === $this->type) {
            return implode('|', $foundStrings);
        }
        var_dump($this->type);
    	throw new \RuntimeException("Unknown type thrown");
    }

    public static function extractTypeFromComment($kind, $comment, $name = '') {
        switch ($kind) {
            case 'return':
                if (preg_match('(@return\s+(\S+))', $comment, $match)) {
                    $return = Type::fromDecl($match[1]);
                    return $return;
                }
                break;
            case 'param':
                if (preg_match("(@param\\s+(\\S+)\\s+\\\${$name})i", $comment, $match)) {
                    $param = Type::fromDecl($match[1]);
                    return $param;
                }
                break;
        }
        return new Type(Type::TYPE_MIXED);
    }

    public static function fromDecl($decl) {
    	if ($decl instanceof Type) {
    		return $decl;
    	} elseif (!is_string($decl)) {
            throw new \LogicException("Should never happen");
        }
        if ($decl[0] === '\\') {
            $decl = substr($decl, 1);
        }
    	switch (strtolower($decl)) {
            case 'boolean':
    		case 'bool':
    			return new Type(Type::TYPE_BOOLEAN);
            case 'integer':
    		case 'int':
    			return new Type(Type::TYPE_LONG);
            case 'double':
            case 'real':
    		case 'float':
    			return new Type(Type::TYPE_DOUBLE);
    		case 'string':
    			return new Type(Type::TYPE_STRING);
    		case 'array':
    			return new Type(Type::TYPE_ARRAY, new Type(Type::TYPE_UNKNOWN));
    		case 'callable':
    			return new Type(Type::TYPE_CALLABLE);
    	}
        if (strpos($decl, '|') !== false) {
            $parts = explode('|', $decl);
            $allowedTypes = 0;
            foreach ($parts as $part) {
                $type = Type::fromDecl($part);
                $allowedTypes |= $type->type;
            }
            return new Type($allowedTypes);
        }
        if (substr($decl, -2) === '[]') {
            $type = Type::fromDecl(substr($decl, 0, -2));
            return new Type(Type::TYPE_ARRAY, $type);
        }
        $regex = '(^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$)';
        if (!preg_match($regex, $decl)) {
            throw new \RuntimeException("Unknown type declaration found: $decl");
        }
        return new Type(Type::TYPE_USER, null, $decl);
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

    public function equals(Type $type) {
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

}