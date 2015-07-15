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

    protected static $hasSubtypes = [
        self::TYPE_ARRAY,
        self::TYPE_UNION,
        self::TYPE_INTERSECTION,
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
     * @param int     $type
     * @param Type[]  $subTypes
     * @param ?string $userType
     */
    public function __construct($type, array $subTypes = [], $userType = null) {
        $this->type = $type;
        $this->subTypes = $subTypes;
        if ($type === self::TYPE_OBJECT) {
            $this->userType = (string) $userType;
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
        if ($this->type === Type::TYPE_UNION) {
            $value = implode('|', $this->subTypes);
        } elseif ($this->type === Type::TYPE_INTERSECTION) {
            $value = implode('&', $this->subTypes);
        } else {
            var_dump($this);
            die("Assertion failure: unknown type: {$this->type}\n");
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
        return new Type(Type::TYPE_MIXED);
    }

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
            return new Type(Type::TYPE_UNION, [
                $type,
                new Type(Type::TYPE_NULL)
            ]);
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
            case 'null':
                return new Type(Type::TYPE_NULL);
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

    private static function parseCompexDecl($decl) {
        $left = null;
        $right = null;
        $combinator = '';
        if (substr($decl, 0, 1) === '(') {
            $regex = '(^(\(((?>[^()]+)|(?1))*\)))';
            if (preg_match($regex, $decl, $match)) {
                $sub = $match[0];
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
        if ($type->type !== $this->type) {
            return false;
        }
        if ($type->type === Type::TYPE_OBJECT) {
            return strtolower($type->userType) === strtolower($this->userType);
        }
        if (in_array($type->type, self::$hasSubtypes)) {
            // we need to ensure subtypes are correct as well
            return $this->subTypes === $type->subTypes;
        }
        return true;
    }

    /**
     * @param Type $toRemove
     *
     * @return Type the removed type
     */
    public function removeType(Type $type) {
        throw new \LogicException('TODO');
    }

}