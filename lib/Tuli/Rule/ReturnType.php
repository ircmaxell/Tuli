<?php

namespace Tuli\Rule;

use Tuli\Rule;
use Tuli\Type;
use PHPCfg\Op;
use PHPCfg\Block;

class ReturnType implements Rule {
    
    public function getName() {
        return "Function and Method Return Types";
    }

    public function execute(array $components) {
        $errors = [];
        foreach ($components['functions'] as $function) {
            $errors = array_merge($errors, $this->verifyReturn($function, $components));
        }
        foreach ($components['methods'] as $method) {
            $errors = array_merge($errors, $this->verifyReturn($method, $components));
        }
        return $errors;
    }

    protected function verifyReturn($function, array $components) {
        if (!$function->stmts) {
            // interface
            return [];
        }
        $errors = [];
        if ($function->returnType) {
            $type = Type::fromDecl($function->returnType->value);
        } else {
            $type = Type::extractTypeFromComment("return", $function->getAttribute('doccomment'));
            if ($type->type === Type::TYPE_MIXED) {
                // only verify actual types
                return $errors;
            }
        }
        $returns = array_unique($this->findReturnBlocks($function->stmts), SORT_REGULAR);
        foreach ($returns as $return) {
            if (!$return || !$return->expr) {
                // Default return, no
                if ($components['typeResolver']->allowsNull($type)) {
                    continue;
                }
                if (!$return) {
                    $errors[] = ["Default return found for non-null type $type", $function];
                } else {
                    $errors[] = ["Explicit null return found for non-null type $type", $return];
                }
            } else {
                if (!$components['typeResolver']->resolves($return->expr->type, $type)) {
                    $errors[] = ["Type mismatch on return value, found {$return->expr->type} expecting {$type}", $return];
                }
            }
        }
        return $errors;
    }

    protected function findReturnBlocks(Block $block, $result = []) {
        $toProcess = new \SplObjectStorage;
        $processed = new \SplObjectStorage;
        $toProcess->attach($block);
        while ($toProcess->count() > 0) {
            foreach ($toProcess as $block) {
                $toProcess->detach($block);
                $processed->attach($block);
                foreach ($block->children as $op) {
                    if ($op instanceof Op\Terminal\Return_) {
                        $result[] = $op;
                        continue 2;
                        // Prevent dead code from executing
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
                            if (!$processed->contains($target)) {
                                $toProcess->attach($target);
                            }
                        }
                        continue 2;
                    }
                }
                // If we reach here, we have an empty return default block, add it to the result
                $result[] = null;
            }
        }
        return $result;
    }
}