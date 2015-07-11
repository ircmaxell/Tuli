<?php

namespace Tuli;

use PHPCfg\Operand;
use PHPCfg\Op;
use Gliph\Graph\DirectedAdjacencyList;

class TypeInferer {
    public function infer(array $components) {
        $this->components = $components;
        $resolved = new \SplObjectStorage;
        $unresolved = new \SplObjectStorage;
        foreach ($components['variables'] as $op) {
            if (!empty($op->type) && $op->type->type !== Type::TYPE_UNKNOWN && $op->type->type !== Type::TYPE_MIXED) {
                $resolved[$op] = $op->type;
            } elseif ($op instanceof Operand\Literal) {
                $resolved[$op] = Type::fromValue($op->value);
            } else {
                $unresolved[$op] = Type::getAllPosibilities();
            }
        }
        if (count($unresolved) === 0) {
            // short-circuit
            return;
        }

        do {
            echo "Round " . $round++ . " (" . count($unresolved) . " unresolved variables out of " . count($components['variables']) . ")\n";
            $start = round(count($resolved) / count($unresolved), 6);
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
        } while (count($unresolved) > 0 && $start < round(count($resolved) / count($unresolved), 6));
        foreach ($resolved as $var) {
            $var->type = $resolved[$var];
        }
        foreach ($unresolved as $var) {
            $var->type = new Type(Type::TYPE_UNKNOWN);
        }
    }

    protected function resloveVar(Op $var, \SplObjectStorage $resolved) {
        var_dump($var);
    }
}