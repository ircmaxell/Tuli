## A static analysis engine...

Usage:

    bin/tuli analyze file1 file2 path

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
