<?php

namespace Tuli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use PhpParser\Parser;
use PhpParser\Lexer;
use PHPCfg\Parser as CFGParser;
use PHPCfg\Block;
use PHPCfg\Visitor;
use PHPCfg\Traverser;
use PHPCfg\Operand;
use PHPCfg\Op;

class AnalyzeCommand extends Command {

	protected function configure() {
		$this->setName('analyze')
			->setDescription('Analyze the provided files')
			->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Extensions To Exclude?", ["md", "xml", "yml", "json"])
			->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The files to analyze');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$parser = new CFGParser(new Parser(new Lexer));
		$graphs = $this->getGraphsFromFiles($input->getArgument('files'), $input->getOption("exclude"), $parser);
		$components = $this->preProcess($graphs, $output);
		echo "Determining Variable Types\n";
		$typeResolver = new TypeResolver;
		$typeResolver->resolve($components);
		echo "Detecting Type Conversion Issues\n";
		echo "Detecting Function Argument Errors\n";
		$this->detectFunctionCallClashes($components);

		echo "Detecting Function Return Errors\n";
		$this->detectFunctionReturnClashes($components);

		echo "Detecting Method Argument Errors\n";
		$this->detectMethodCallClashes($components);
		echo "Done\n";
	}

	protected function getGraphsFromFiles(array $files, array $exclude, CFGParser $parser) {
		$excludeParts = [];
		foreach ($exclude as $part) {
			$excludeParts[] = preg_quote($part);
		}
		$part = implode('|', $excludeParts);
		$excludeRegex = "(((\\.($part)($|/))|((^|/)($part)($|/))))";
			var_dump($excludeRegex);
		$graphs = [];
		foreach ($files as $file) {
			if (is_file($file)) {
				$files = [$file];
				$graphs[$file] = $parser->parse(file_get_contents($file), $file);
			} elseif (is_dir($file)) {
				$it = new \CallbackFilterIterator(
					new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator($file)
					), 
					function($file) use ($excludeRegex) {
						if (preg_match($excludeRegex, $file->getPathName())) {
							return false;
						}
						return $file->isFile();
					}
				);
				$files = [];
				foreach ($it as $file) {
					$files[] = $file->getPathName(); // since __toString would be too difficult...
				}
			} else {
				throw new \RuntimeException("Error: $file is not a file or directory");
			}
			foreach ($files as $file) {
				echo "Analyzing $file\n";
				$graphs[$file] = $parser->parse(file_get_contents($file), $file);
			}
		}
		return $graphs;
	}

	protected function preProcess(array $blocks, OutputInterface $output) {
		$traverser = new Traverser;
		$declarations = new Visitor\DeclarationFinder;
		$calls = new Visitor\CallFinder;
		$variables = new Visitor\VariableFinder;
		$dagComputer = new Visitor\VariableDagComputer;
		$traverser->addVisitor(new Visitor\Simplifier);
		$traverser->addVisitor($dagComputer);
		$traverser->addVisitor($declarations);
		$traverser->addVisitor($calls);
		$traverser->addVisitor($variables);
		foreach ($blocks as $block) {
			$traverser->traverse($block);
		}
		$vars = $variables->getVariables();
		
		return [
			"cfg" => $blocks,
			"traits" => $declarations->getTraits(),
			"classes" => $declarations->getClasses(),
			"methods" => $declarations->getMethods(),
			"functions" => $declarations->getFunctions(),
			"functionLookup" => $this->buildFunctionLookup($declarations->getFunctions()),
			"interfaces" => $declarations->getInterfaces(),
			"variables" => $variables->getVariables(),
			"callResolver" => $calls,
			"typeMatrix" => $this->computeTypeMatrix($declarations),
		];
	}

	protected function computeTypeMatrix($declarations) {
		$classes = $declarations->getClasses();
		$interfaces = $declarations->getInterfaces();

		$parents = [];
		$toProcess = [];
		$parents = [];
		$matrix = [];
		foreach ($classes as $class) {
			// Trivial case
			$name = strtolower($class->name->value);
			$matrix[$name] = [$class];
			if (isset($toProcess[$name])) {
				foreach ($toProcess[$name] as $v) {
					$matrix[$name][] = $v;
				}
			} else {
				$toProcess[$name] = [];
			}
			if ($class->extends) {
				$extends = strtolower($class->extends->value);
				$parents[$name][] = $extends;
				if (isset($matrix[$extends])) {
					$matrix[$extends][] = $class;
					foreach ($toProcess[$name] as $v) {
						$matrix[$extends][] = $v;
					}
					foreach ($parents[$extends] as $parent) {
						$matrix[$parent][] = $class;
						foreach ($toProcess[$name] as $v) {
							$matrix[$parent][] = $v;
						}
					}
				} else {
					$toProcess[$extends][$name] = $class;
				}
			}
			foreach ($class->implements as $implement) {
				$interface = strtolower($implement->value);
				$parents[$name][] = $interface;
				if (isset($matrix[$interface])) {
					$matrix[$interface][] = $class;
					foreach ($toProcess[$name] as $v) {
						$matrix[$interface][] = $v;
					}
					foreach ($parents[$interface] as $parent) {
						$matrix[$parent][] = $class;
						foreach ($toProcess[$name] as $v) {
							$matrix[$parent][] = $v;
						}
					}
				} else {
					$toProcess[$interface][$name] = $class;
				}
			}
		}

		foreach ($interfaces as $interface) {
			// Trivial case
			$name = strtolower($interface->name->value);
			if (isset($toProcess[$name])) {
				foreach ($toProcess[$name] as $v) {
					$matrix[$name][] = $v;
				}
			} else {
				$toProcess[$name] = [];
			}
			if ($interface->extends) {
				// TODO
			}
		}
		return $matrix;
	}

	protected function buildFunctionLookup(array $functions) {
		$lookup = [];
		foreach ($functions as $function) {
			assert($function->name instanceof Operand\Literal);
			$name = strtolower($function->name->value);
			if (!isset($lookup[$name])) {
				$lookup[$name] = [];
			}
			$lookup[$name][] = $function;
		}
		return $lookup;
	}

	protected function detectFunctionCallClashes(array $components) {
		foreach ($components['functionLookup'] as $name => $functions) {
			$calls = $components['callResolver']->getCallsForFunction($name);
			foreach ($functions as $func) {
				foreach ($func->params as $idx => $param) {
					foreach ($calls as $call) {
						if (!isset($call[0]->args[$idx]) && !$param->defaultVar) {
							$this->emitError(
								"Missing required argument $idx for function call $name",
								$call[0]
							);
						} else {
							if ($param->type && !$call[0]->args[$idx]->type->resolves(Type::fromDecl($param->type->value))) {
								$this->emitError(
									"Type mismatch on $name() argument $idx, found {$call[0]->args[$idx]->type} expecting {$param->type->value}",
									$call[0]
								);
							}
						}
					}
				}
			}

		}
	}

	protected function detectFunctionReturnClashes(array $components) {
		foreach ($components['functionLookup'] as $name => $functions) {
			foreach ($functions as $func) {
				if (!$func->returnType) {
					continue;
				}
				$type = Type::fromDecl($func->returnType->value);
				$returns = array_unique($this->findReturnBlocks($func->stmts), SORT_REGULAR);
				foreach ($returns as $return) {
					if (!$return || !$return->expr) {
						// Default return, no
						if ($this->allowsNull($type)) {
							continue;
						}
						if (!$return) {
							$this->emitError(
								"Default return found for non-null type $type",
								$func
							);
						} else {
							$this->emitError(
								"Explicit null return found for non-null type $type",
								$return
							);
						}
					} else {
						if (!$return->expr->type->resolves($type)) {
							$this->emitError(
								"Type mismatch on $name() return value, found {$return->expr->type} expecting {$type}",
								$return
							);
						}
					}
				}
			}
		}
	}

	protected function detectMethodCallClashes(array $components) {
		$methodCalls = [];
		foreach ($components['cfg'] as $block) {
			$methodCalls = $this->findTypedBlock("Expr_MethodCall", $block, $methodCalls);
		}
		foreach ($methodCalls as $call) {
			assert(isset($call->var->type));
			if (!$call->name instanceof Operand\Literal) {
				var_dump($call->name);
				die();
			}
			if ($call->var->type->type !== Type::TYPE_USER) {
				continue;
			}
			$name = strtolower($call->var->type->userType);
			// try looking it up
			if (!isset($components['typeMatrix'][$name])) {
				echo "Could not find $name\n";
				continue;
			}
			foreach ($components['typeMatrix'][$name] as $class) {
				$cn = $class->name->value;
				$method = $this->findMethod($class, strtolower($call->name->value));
				if (!$method) {
					die("No method found");
				}
				foreach ($method->params as $idx => $param) {
					if (!isset($call->args[$idx]) && !$param->defaultVar) {
						$this->emitError(
							"Missing required argument $idx for method call $cn->{$call->name->value}",
							$call
						);
					} else {
						if ($param->type && !$call->args[$idx]->type->resolves(Type::fromDecl($param->type->value))) {
							$this->emitError(
								"Type mismatch on $cn->{$call->name->value}() argument $idx, found {$call->args[$idx]->type} expecting {$param->type->value}",
								$call
							);
						}
					}
				}
			}
		}
	}

	protected function findMethod($class, $name) {
		foreach ($class->stmts->children as $stmt) {
			if ($stmt instanceof Op\Stmt\ClassMethod) {
				if (strtolower($stmt->name->value) === $name) {
					return $stmt;
				}
			}
		}
		if ($name !== '__call') {
			return $this->findMethod($class, '__call');
		}
		return null;
	}

	protected function allowsNull(Type $type) {
		if ($type->type === Type::TYPE_MIXED) {
			return true;
		}
		// TODO allow more
		return false;
	}

	protected function findReturnBlocks(Block $block, $result = []) {
		foreach ($block->children as $op) {
			if ($op instanceof Op\Terminal\Return_) {
				$result[] = $op;
				return $result;
				// Prevent dead code from executing
			} elseif ($op instanceof Op\Stmt\Jump) {
				return $this->findReturnBlocks($op->target, $result);
			} elseif ($op instanceof Op\Stmt\JumpIf) {
				$result = $this->findReturnBlocks($op->if, $result);
				return $this->findReturnBlocks($op->else, $result);
			} elseif ($op instanceof Op\Stmt\Switch_) {
				foreach ($op->targets as $target) {
					$result = $this->findReturnBlocks($target, $result);
				}
				return $result;
			}
		}
		// If we reach here, we have an empty return default block, add it to the result
		$result[] = null;
		return $result;
	}

	protected function findTypedBlock($type, Block $block, $result = []) {
		static $stack;
		if (!$stack) {
			$stack = new \SplObjectStorage;
		}
		if ($stack->contains($block)) {
			return;
		}
		$stack->attach($block);
		foreach ($block->children as $op) {
			if ($op->getType() === $type) {
				$result[] = $op;
			}
			foreach ($op->getSubBlocks() as $name) {
				$sub = $op->$name;
				if (is_null($sub)) {
					continue;
				}
				if (!is_array($sub)) {
					$sub = [$sub];
				}
				foreach ($sub as $subb) {
					$result = $this->findTypedBlock($type, $subb, $result);
				}
			}
		}
		$stack->detach($block);
		return $result;
	}

	protected function emitError($msg, Op $op) {
		echo $msg;
		echo " ";
		echo $op->getFile() . ":" . $op->getLine();
		echo "\n";
	}

}