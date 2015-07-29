<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace Tuli\Rule;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPTypes\State;
use PHPTypes\Type;
use Tuli\Rule;

class ReturnType implements Rule {
    
    public function getName() {
        return "Function and Method Return Types";
    }

    /**
     * @param State $state
     *
     * @return array
     */
    public function execute(State $state) {
        $errors = [];
        foreach ($state->functions as $function) {
            $errors = array_merge($errors, $this->verifyReturn($function, $state));
        }
        foreach ($state->methods as $method) {
            $errors = array_merge($errors, $this->verifyReturn($method, $state));
        }
        return $errors;
    }

    protected function verifyReturn($function, State $state) {
        if (!$function->stmts) {
            // interface
            return [];
        }
        $errors = [];
        if ($function->returnType) {
            $type = Type::fromDecl($function->returnType->value);
        } else {
            $type = Type::extractTypeFromComment("return", $function->getAttribute('doccomment'));
            if (Type::mixed()->equals($type)) {
                // only verify actual types
                return $errors;
            }
        }
        $returns = $this->findReturnBlocks($function->stmts);
        foreach ($returns as $return) {
            if (!$return || !$return->expr) {
                // Default return, no
                if ($type->allowsNull()) {
                    continue;
                }
                if (!$return) {
                    $errors[] = ["Default return found for non-null type $type", $function];
                } else {
                    $errors[] = ["Explicit null return found for non-null type $type", $return];
                }
            } elseif (!$return->expr->type) {
                var_dump($return->expr);
                $errors[] = ["Could not resolve type for return", $return];
            } else {
                if (!$state->resolver->resolves($return->expr->type, $type)) {
                    $errors[] = ["Type mismatch on return value, found {$return->expr->type} expecting {$type}", $return];
                }
            }
        }
        return $errors;
    }

    protected function findReturnBlocks(Block $block, $result = []) {
        $toProcess = new \SplObjectStorage;
        $processed = new \SplObjectStorage;
        $results = new \SplObjectStorage;
        $addNull = false;
        $toProcess->attach($block);
        while ($toProcess->count() > 0) {
            foreach ($toProcess as $block) {
                $toProcess->detach($block);
                $processed->attach($block);
                foreach ($block->children as $op) {
                    if ($op instanceof Op\Terminal\Return_) {
                        $results->attach($op);
                        continue 2;
                        // Prevent dead code from executing
                    } elseif ($op instanceof Op\Terminal\Throw_) {
                        // throws are ok
                        continue 2;
                    } elseif ($op instanceof Op\Stmt\Jump) {
                        if (!$processed->contains($op->target)) {
                            $toProcess->attach($op->target);
                        }
                        continue 2;
                    } elseif ($op instanceof Op\Stmt\JumpIf) {
                        if (!$processed->contains($op->if)) {
                            $toProcess->attach($op->if);
                        }
                        if (!$processed->contains($op->else)) {
                            $toProcess->attach($op->else);
                        }
                        continue 2;
                    } elseif ($op instanceof Op\Stmt\Switch_) {
                        foreach ($op->targets as $target) {
                            if (!is_array($target)) {
                                // TODO FIX THIS
                                $target = [$target];
                            }
                            foreach ($target as $t) {
                                if (!$processed->contains($t)) {
                                    $toProcess->attach($t);
                                }
                            }
                        }
                        continue 2;
                    }
                }
                // If we reach here, we have an empty return default block, add it to the result
                $addNull = true;
            }
        }
        $results = iterator_to_array($results);
        if ($addNull) {
            $results[] = null;
        }
        return $results;
    }
}