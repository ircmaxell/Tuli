<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli;

class Type {
    const TYPE_UNKNOWN  = -1;

    const TYPE_NULL         = 1;
    const TYPE_BOOLEAN      = 2;
    const TYPE_LONG         = 3;
    const TYPE_DOUBLE       = 4;
    const TYPE_STRING       = 5;
    
    const TYPE_OBJECT       = 6;
    const TYPE_ARRAY        = 7;
    const TYPE_CALLABLE     = 8;

    const TYPE_UNION        = 10;
    const TYPE_INTERSECTION = 11;

    /** 
     * @var int[]
     */
    protected static $hasSubtypes = [
        self::TYPE_ARRAY        => self::TYPE_ARRAY,
        self::TYPE_UNION        => self::TYPE_UNION,
        self::TYPE_INTERSECTION => self::TYPE_INTERSECTION,
    ];

    /**
     * @var int
     */
    public $type = 0;

    /**
     * @var Type[]
     */
    public $subTypes = [];

    /**
     * @var string
     */
    public $userType = '';

    /**
     * @var Type[]
     */
    private static $typeCache = [];

    /**
     * @return Type
     */
    public static function unknown() {
        return self::makeCachedType(Type::TYPE_UNKNOWN);
    }
    /**
     * @return Type
     */
    public static function int() {
        return self::makeCachedType(Type::TYPE_LONG);
    }
    /**
     * @return Type
     */
    public static function float() {
        return self::makeCachedType(Type::TYPE_DOUBLE);
    }
    /**
     * @return Type
     */
    public static function string() {
        return self::makeCachedType(Type::TYPE_STRING);
    }
    /**
     * @return Type
     */
    public static function bool() {
        return self::makeCachedType(Type::TYPE_BOOLEAN);
    }
    /**
     * @return Type
     */
    public static function null() {
        return self::makeCachedType(Type::TYPE_NULL);
    }
    /**
     * @return Type
     */
    public static function object() {
        return self::makeCachedType(Type::TYPE_OBJECT);
    }

    /**
     * @param int $key
     *
     * @return Type
     */
    private static function makeCachedType($key) {
        if (!isset(self::$typeCache[$key])) {
            self::$typeCache[$key] = new Type($key);
        }
        return self::$typeCache[$key];
    }
    /**
     * @return Type
     */
    public static function numeric() {
        if (!isset(self::$typeCache["numeric"])) {
            self::$typeCache["numeric"] = new Type(Type::TYPE_UNION, [self::int(), self::float()]);
        }
        return self::$typeCache["numeric"];
    }
    /**
     * @return Type
     */
    public static function mixed() {
        if (!isset(self::$typeCache["mixed"])) {
            $subs = [];
            foreach (self::getPrimitives() as $key => $name) {
                $subs[] = self::makeCachedType($key);
            }
            self::$typeCache["mixed"] = new Type(Type::TYPE_UNION, $subs);
        }
        return self::$typeCache["mixed"];
    }

    /**
     * @param int     $type
     * @param Type[]  $subTypes
     * @param ?string $userType
     */
    public function __construct($type, array $subTypes = [], $userType = null) {
        $this->type = $type;
        if ($type === self::TYPE_OBJECT) {
            $this->userType = (string) $userType;
        } elseif (isset(self::$hasSubtypes[$type])) {
            $this->subTypes = $subTypes;
            foreach ($subTypes as $sub) {
                if (!$sub instanceof Type) {
                    throw new \RuntimeException("Sub types must implement Type");
                }
            }
        }
    }

    /**
     * Get the primitives
     *
     * @return string[]
     */
    public static function getPrimitives() {
        return [
            Type::TYPE_NULL     => 'null',
            Type::TYPE_BOOLEAN  => 'bool',
            Type::TYPE_LONG     => 'int',
            Type::TYPE_DOUBLE   => 'float',
            Type::TYPE_STRING   => 'string',
            Type::TYPE_OBJECT   => 'object',
            Type::TYPE_ARRAY    => 'array',
            Type::TYPE_CALLABLE => 'callable',
        ];
    }

    /**
     * @return string
     */
    public function __toString() {
        static $ctr = 0;
        $ctr++;
        if ($this->type === Type::TYPE_UNKNOWN) {
            $ctr--;
            return "unknown";
        }
        $primitives = self::getPrimitives();
        if (isset($primitives[$this->type])) {
            $ctr--;
            if ($this->type === Type::TYPE_OBJECT && $this->userType) {
                return $this->userType;
            } elseif ($this->type === Type::TYPE_ARRAY && $this->subTypes) {
                return $this->subTypes[0] . '[]';
            }
            return $primitives[$this->type];
        }
        $value = '';
        if ($this->type === Type::TYPE_UNION) {
            $value = implode('|', $this->subTypes);
        } elseif ($this->type === Type::TYPE_INTERSECTION) {
            $value = implode('&', $this->subTypes);
        } else {
            throw new \RuntimeException("Assertion failure: unknown type {$this->type}");
        }
        $ctr--;
        if ($ctr > 0) {
            return '(' . $value . ')';
        }
        return $value;
    }

    public function hasSubtypes() {
        return in_array($this->type, self::$hasSubtypes);
    }

    public function allowsNull() {
        if ($this->type === Type::TYPE_NULL) {
            return true;
        }
        if ($this->type === Type::TYPE_UNION) {
            foreach ($this->subTypes as $subType) {
                if ($subType->allowsNull()) {
                    return true;
                }
            }
        }
        if ($this->type === Type::TYPE_INTERSECTION) {
            foreach ($this->subTypes as $subType) {
                if (!$subType->allowsNull()) {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * @param string $kind
     * @param string $comment
     * @param string $name    The name of the parameter
     *
     * @return Type The type
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
        return self::mixed();
    }

    /**
     * @return Type
     */
    public function simplify() {
        if ($this->type !== Type::TYPE_UNION && $this->type !== Type::TYPE_INTERSECTION) {
            return $this;
        }
        $new = [];
        foreach ($this->subTypes as $subType) {
            $subType = $subType->simplify();
            if ($this->type === $subType->type) {
                $new = array_merge($new, $subType->subTypes);
            } else {
                $new[] = $subType->simplify();
            }
        }
        // TODO: compute redundant unions
        return (new Type($this->type, $new));
    }

    /**
     * @param string $decl
     *
     * @return Type The type
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
        } elseif ($decl[0] === '?') {
            $decl = substr($decl, 1);
            $type = Type::fromDecl($decl);
            return (new Type(Type::TYPE_UNION, [
                $type,
                new Type(Type::TYPE_NULL)
            ]))->simplify();
        }
        switch (strtolower($decl)) {
            case 'boolean':
            case 'bool':
            case 'false':
            case 'true':
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
            case 'null':
            case 'void':
                return new Type(Type::TYPE_NULL);
            case 'numeric':
                return Type::fromDecl('int|float');
        }
        // TODO: parse | and & and ()
        if (strpos($decl, '|') !== false || strpos($decl, '&') !== false || strpos($decl, '(') !== false) {
            return self::parseCompexDecl($decl)->simplify();
        }
        if (substr($decl, -2) === '[]') {
            $type = Type::fromDecl(substr($decl, 0, -2));
            return new Type(Type::TYPE_ARRAY, [$type]);
        }
        $regex = '(^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$)';
        if (!preg_match($regex, $decl)) {
            throw new \RuntimeException("Unknown type declaration found: $decl");
        }
        return new Type(Type::TYPE_OBJECT, [], $decl);
    }

    /**
     * @param string $decl
     *
     * @return Type
     */
    private static function parseCompexDecl($decl) {
        $left = null;
        $right = null;
        $combinator = '';
        if (substr($decl, 0, 1) === '(') {
            $regex = '(^(\(((?>[^()]+)|(?1))*\)))';
            $match = [];
            if (preg_match($regex, $decl, $match)) {
                $sub = (string) $match[0];
                $left = self::fromDecl(substr($sub, 1, -1));
                if ($sub === $decl) {
                    return $left;
                }
                $decl = substr($decl, strlen($sub));
            } else {
                throw new \RuntimeException("Unmatched braces?");
            }
            if (!in_array(substr($decl, 0, 1), ['|', '&'])) {
                throw new \RuntimeException("Unknown position of combinator: $decl");
            }
            $right = self::fromDecl(substr($decl, 1));
            $combinator = substr($decl, 0, 1);
        } else {
            $orPos = strpos($decl, '|');
            $andPos = strpos($decl, '&');
            $pos = 0;
            if ($orPos === false && $andPos !== false) {
                $pos = $andPos;
            } elseif ($orPos !== false && $andPos === false) {
                $pos = $orPos;
            } elseif ($orPos !== false && $andPos !== false) {
                $pos = min($orPos, $andPos);
            } else {
                throw new \RuntimeException("No combinator found: $decl");
            }
            if ($pos === 0) {
                throw new \RuntimeException("Unknown position of combinator: $decl");
            }
            $left = self::fromDecl(substr($decl, 0, $pos));
            $right = self::fromDecl(substr($decl, $pos + 1));
            $combinator = substr($decl, $pos, 1);
        }
        if ($combinator === '|') {
            return new Type(Type::TYPE_UNION, [$left, $right]);
        } elseif ($combinator === '&') {
            return new Type(Type::TYPE_INTERSECTION, [$left, $right]);
        }
        throw new \RuntimeException("Unknown combinator $combinator");
    }

    /**
     * @param mixed $value
     *
     * @return Type The type
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
     *
     * @return bool The status
     */
    public function equals(Type $type) {
        if ($type === $this) {
            return true;
        }
        if ($type->type !== $this->type) {
            return false;
        }
        if ($type->type === Type::TYPE_OBJECT) {
            return strtolower($type->userType) === strtolower($this->userType);
        }
        // TODO: handle sub types
        if (isset(self::$hasSubtypes[$this->type]) && isset(self::$hasSubtypes[$type->type])) {
            if (count($this->subTypes) !== count($type->subTypes)) {
                return false;
            }
            // compare 
            $other = $type->subTypes;
            foreach ($this->subTypes as $st1) {
                foreach ($other as $key => $st2) {
                    if ($st1->equals($st2)) {
                        unset($other[$key]);
                        continue 2;
                    }
                    // We have a subtype that's not equal
                    return false;
                }
            }
            return empty($other);
        }
        return true;
    }

    /**
     * @param Type $toRemove
     *
     * @return Type the removed type
     */
    public function removeType(Type $type) {
        if (!isset(self::$hasSubtypes[$this->type])) {
            if ($this->equals($type)) {
                // left with an unknown type
                return Type::null();
            }
            return $this;
        }
        $new = [];
        foreach ($this->subTypes as $key => $st) {
            if (!$st->equals($type)) {
                $new[] = $st;
            }
        }
        if (empty($new)) {
            throw new \LogicException('Unknown type encountered');
        } elseif (count($new) === 1) {
            return $new[0];
        }
        return new Type($this->type, $new);
    }

}