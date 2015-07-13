## A static analysis engine...

Usage:

    bin/tuli analyze file1 file2 path

## Installation

Install it as a composer dependency!!!

    $ composer require ircmaxell/tuli dev-master

Then simply execute `vendor/bin/tuli` as normal

Or check it out into its own project. Then `composer install` the dependencies:

    $ composer install

Then simply `bin/tuli` to execute.

## Example:

code.php:

    <?php

	$a = 1.0;
	$b = 2;

	$c = foo($a, $b);

	$d = foo($b, $c);

	function foo(int $a, int $b): int {
		if ($a > $b) {
			return $a + $b + 0.5;
		}
	}

Then, in shell:

    $ bin/tuli analyze code.php
    Analyzing code.php
    Determining Variable Types
    Round 1 (15 unresolved variables out of 20)
    .
    Detecting Type Conversion Issues
    Type mismatch on foo() argument 0, found float expecting int code.php:6
    Type mismatch on foo() return value, found float expecting int code.php:12
    Default return found for non-null type int code.php:10
    Done

The three errors it found are:

 * `Type mismatch on foo() argument 0, found float expecting int code.php:6`

 	Meaning that at code.php on line 6, you're passing a float to the first argument when it declared an integer

 * `Type mismatch on foo() return value, found float expecting int code.php:12`

 	The value that's being returned on line 12 is a float, but it was declared as an integer in the function signature.

 * `Default return found for non-null type int code.php:10`

 	There's a default return statement (not supplied) for a typed function

That's it!

## Currently Supported Rules:

 * Function Argument Types

    It will check all typed function arguments and determine if all calls to that function match the type.

 * Function Return Types

    If the function's return value is typed, it will determine if the function actually returns that type.

 * Method Argument Types

    It will check all calls to a method for every valid typehint permutation to determine if there's a possible mismatch.

Todo:

* A lot

## Another example:

    <?php

    class A {
        public function foo(int $a) : int {
            return $a;
        }
    }

    class B extends A {
        public function foo(float $a) : float {
            return $a;
        }
    }

    class C extends B {
        public function foo(int $a) : int {
            return $a;
        }
    }

    function foo(A $a) : int {
        return $a->foo(1.0);
    }

Running:

    $ bin/tuli analyze code.php
    Analyzing code.php

    Determining Variable Types
    Round 1 (5 unresolved variables out of 7)

    Round 2 (3 unresolved variables out of 7)

    Detecting Type Conversion Issues
    Detecting Function Argument Errors
    Detecting Function Return Errors
    Type mismatch on foo() return value, found float expecting int code.php:22
    Detecting Method Argument Errors
    Type mismatch on A->foo() argument 0, found float expecting int code.php:22
    Type mismatch on C->foo() argument 0, found float expecting int code.php:22
    Done

Again, it found 3 errors:

 * `Type mismatch on foo() return value, found float expecting int code.php:22`

    It looked at all possible `A::foo()` method definitions (A::foo, B::foo, C::foo), and it detmermined that the general return type is float (since type widening allows int to be passed to float, but not the other way around). Therefore, returning ->foo() directly can result in a type error.

 * `Type mismatch on A->foo() argument 0, found float expecting int code.php:22`
 * `Type mismatch on C->foo() argument 0, found float expecting int code.php:22`

    We know that if you use type A or C, you're trying to pass a float to something that declares an integer.

