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

    /**
     * @var int
     */
    public $type = 0;
    /**
     * @var Tuli\Type[]
     */
    public $subTypes = [];
    /**
     * @var string[]
     */
    public $userTypes = [];

    /**
     * @param int      $type
     * @param Tuli\Type[]   $subTypes
     * @param string[] $userTypes
     */
    public function __construct($type, array $subTypes = [], array $userTypes = []) {
        $this->type = $type;
        $this->subTypes = $subTypes;
        $this->userTypes = $userTypes;
        if ($this->userTypes && 0 === ($type & Type::TYPE_USER)) {
            throw new \RuntimeException("Only user types can have user types");
        }
    }

    /**
     * Get the primitives
     * @return string[]
     */
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

    /**
     * @return string
     */
    public function __toString() {
        if ($this->type === Type::TYPE_UNKNOWN) {
            return "unknown";
        }
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
                if ($this->subTypes) {
                    $return = [];
                    foreach ($this->subTypes as $sub) {
                        $return[] = $nullable . $sub . '[]';
                    }
                    return implode('|', $return);
                }
                return $nullable . 'array';
            case Type::TYPE_OBJECT:
                return $nullable . 'object';
            case Type::TYPE_USER:
                $return = [];
                foreach ($this->userTypes as $type) {
                    $return[] = $nullable . $type;
                }
                return implode('|', $return);
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
                if ($primitive === Type::TYPE_ARRAY && $this->subTypes) {
                    foreach ($this->subTypes as $st) {
                        $foundStrings[] = ($st) . '[]';
                    }
                } elseif ($foundString) {
                    $foundStrings[] = $foundString;
                } elseif ($primitive === Type::TYPE_USER) {
                    foreach ($this->userTypes as $ut) {
                        $foundStrings[] = $ut;
                    }
                }
            }
        }
        if ($found === $this->type) {
            return implode('|', $foundStrings);
        }
        var_dump($this->type);
        throw new \RuntimeException("Unknown type thrown");
        return "unknown";
    }

    /**
     * @param string $kind
     * @param string $comment
     * @param string $name The name of the parameter
     * @return Tuli\Type The type
     */
    public static function extractTypeFromComment($kind, $comment, $name = '') {
        $match = [];
        switch ($kind) {
            case 'var':
                if (preg_match('(@var\s+(\S+))', $comment, $match)) {
                    $return = Type::fromDecl($match[1]);
                    return $return;
                }
                break;
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

    /**
     * @param string $decl
     * @return Tuli\Type The type
     */
    public static function fromDecl($decl) {
        if ($decl instanceof Type) {
            return $decl;
        } elseif (!is_string($decl)) {
            throw new \LogicException("Should never happen");
        } elseif (empty($decl)) {
            throw new \RuntimeException("Empty declaration found");
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
                return new Type(Type::TYPE_ARRAY);
            case 'callable':
                return new Type(Type::TYPE_CALLABLE);
        }
        if (strpos($decl, '|') !== false) {
            $parts = explode('|', $decl);
            $allowedTypes = 0;
            $userTypes = [];
            $subTypes = [];
            foreach ($parts as $part) {
                $type = Type::fromDecl($part);
                $allowedTypes |= $type->type;
                $userTypes = array_merge($type->userTypes, $userTypes);
                $subTypes = array_merge($type->subTypes, $subTypes);
            }
            return new Type($allowedTypes, $subTypes, $userTypes);
        }
        if (substr($decl, -2) === '[]') {
            $type = Type::fromDecl(substr($decl, 0, -2));
            return new Type(Type::TYPE_ARRAY, [$type]);
        }
        if (substr($decl, -2) === '()') {
            // because some people use array() as a type declaration. sigh
            return Type::fromDecl(substr($decl, 0, -2));
        }
        $regex = '(^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$)';
        if (!preg_match($regex, $decl)) {
            throw new \RuntimeException("Unknown type declaration found: $decl");
        }
        return new Type(Type::TYPE_USER, [], [$decl]);
    }

    /**
     * @param mixed $value
     * @return Tuli\Type The type
     */
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

    /**
     * @param Type $type
     * @return bool The status
     */
    public function equals(Type $type) {
        if ($type->type !== $this->type) {
            return false;
        }
        if ($type->type === Type::TYPE_ARRAY) {
            return $this->subTypes === $type->subTypes;
        }
        if ($type->type === Type::TYPE_USER) {
            $left = array_map('strtolower', $type->userTypes);
            $right = array_map('strtolower', $this->userTypes);
            return array_diff($left, $right) === [] && array_diff($right, $left) === [];
        }
        return true;
    }

}