<?php

/**
 * The public API of one PHP class, read from its source.
 *
 * @category Test support
 * @package  OCA\Dossiq\Tests\Support\StubDrift
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support\StubDrift;

/**
 * The public API of one class, read from SOURCE rather than reflection.
 *
 * Reflection cannot do this job. A stub and the class it doubles share one
 * fully-qualified name, so only one of them can be loaded in a process, and
 * whichever loses is the one there is nothing to compare against. Reading the
 * source sidesteps that entirely: both files are just text, and neither has to
 * be loadable — which also means this works in a bare container where
 * OpenRegister's own dependencies are absent.
 *
 * What it deliberately does NOT model: inheritance. Every entry here is
 * DECLARED on the class in question. For an `OCP\AppFramework\Db\Entity`
 * subclass that omission would be fatal to the comparison, because such a
 * class serves `getFoo()`/`setFoo()` for every declared property through
 * `__call` and declares neither — so properties are collected too, and the
 * caller reconciles the two.
 *
 * @psalm-immutable
 */
final class ClassApi {

	/**
	 * Constructor.
	 *
	 * @param string                                       $name       Fully-qualified class name.
	 * @param array<string, array{total:int, required:int, params:list<string>}> $methods    Public methods by name.
	 * @param array<string, string>                        $constants  Public constants by name, source text of the value.
	 * @param list<string>                                 $properties Declared property names, any visibility.
	 * @param string|null                                  $parent     The `extends` name as written, when there is one.
	 */
	private function __construct(
		public readonly string $name,
		public readonly array $methods,
		public readonly array $constants,
		public readonly array $properties,
		public readonly ?string $parent,
	) {

	}//end __construct()

	/**
	 * Read one class's public API out of a PHP file.
	 *
	 * When the file declares several classes (the stub files that guard a
	 * whole namespace do), $shortName picks the one wanted.
	 *
	 * @param string $file      Path to the PHP file.
	 * @param string $shortName The class's short name.
	 *
	 * @return self|null The API, or null when the file declares no such class.
	 */
	public static function fromFile(string $file, string $shortName): ?self {
		$source = file_get_contents($file);
		if ($source === false) {
			return null;
		}

		$tokens = token_get_all($source);
		$body = self::sliceClassBody($tokens, $shortName);
		if ($body === null) {
			return null;
		}

		return new self(
			$shortName,
			self::readMethods($body['tokens']),
			self::readConstants($body['tokens']),
			self::readProperties($body['tokens']),
			$body['parent'],
		);
	}//end fromFile()

	/**
	 * Cut the token stream down to one class's body.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens    The whole file's tokens.
	 * @param string                                         $shortName The class wanted.
	 *
	 * @return array{tokens: list<array{0:int,1:string,2:int}|string>, parent: string|null}|null The body, or null.
	 */
	private static function sliceClassBody(array $tokens, string $shortName): ?array {
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			if (is_array($tokens[$i]) === false) {
				continue;
			}

			if (in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT], true) === false) {
				continue;
			}

			// `Foo::class` is a T_CLASS too; the name token settles it.
			$name = self::nextMeaningful($tokens, $i);
			if ($name === null || $name[0] !== T_STRING || $name[1] !== $shortName) {
				continue;
			}

			$parent = null;
			$depth = 0;
			$open = null;
			for ($j = $i; $j < $count; $j++) {
				if (is_array($tokens[$j]) === true && $tokens[$j][0] === T_EXTENDS) {
					$extends = self::nextMeaningful($tokens, $j);
					if ($extends !== null) {
						$parent = $extends[1];
					}
				}

				$delta = self::depthDelta($tokens[$j]);
				if ($delta === 0) {
					continue;
				}

				if ($delta === 1) {
					$depth++;
					if ($open === null && $tokens[$j] === '{') {
						$open = $j;
					}

					continue;
				}

				$depth--;
				if ($depth === 0) {
					return [
						'tokens' => array_slice($tokens, ($open + 1), ($j - $open - 1)),
						'parent' => $parent,
					];
				}
			}//end for
		}//end for

		return null;
	}//end sliceClassBody()

	/**
	 * The next token that is not whitespace, a comment or an attribute.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens The tokens.
	 * @param integer                                        $from   Index to search after.
	 *
	 * @return array{0:int,1:string,2:int}|null The token, or null at the end.
	 */
	private static function nextMeaningful(array $tokens, int $from): ?array {
		$count = count($tokens);
		for ($i = ($from + 1); $i < $count; $i++) {
			if (is_array($tokens[$i]) === false) {
				return null;
			}

			if (in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			return $tokens[$i];
		}

		return null;
	}//end nextMeaningful()

	/**
	 * How much a token changes the brace depth.
	 *
	 * `{` and `}` are not the only brace tokens. Inside a double-quoted string
	 * or heredoc, `"{$a}"` opens with T_CURLY_OPEN and `"${a}"` with
	 * T_DOLLAR_OPEN_CURLY_BRACES — both ARRAY tokens — while each closes with a
	 * plain `}` string token. Counting only the string form therefore drifts
	 * one negative per interpolation, and everything after it in the file is
	 * read at the wrong depth. That is not hypothetical: it made this very
	 * extractor report `createAuditTrailEntry()` as absent from a real class
	 * that declares it on line 2065.
	 *
	 * @param array{0:int,1:string,2:int}|string $token The token.
	 *
	 * @return integer -1, 0 or +1.
	 */
	private static function depthDelta(array|string $token): int {
		if (is_string($token) === true) {
			if ($token === '{') {
				return 1;
			}

			return ($token === '}' ? -1 : 0);
		}

		if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true) {
			return 1;
		}

		return 0;
	}//end depthDelta()


	/**
	 * Collect the public methods declared directly in a class body.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $body The class body's tokens.
	 *
	 * @return array<string, array{total:int, required:int, params:list<string>}> Methods by name.
	 */
	private static function readMethods(array $body): array {
		$methods = [];
		$count = count($body);
		$modifiers = [];
		$depth = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $body[$i];
			$depth += self::depthDelta($token);
			if (is_string($token) === true || $depth > 0) {
				continue;
			}

			if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true) === true) {
				$modifiers[] = $token[0];
				continue;
			}

			if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			if ($token[0] !== T_FUNCTION) {
				$modifiers = [];
				continue;
			}

			$isPublic = (in_array(T_PROTECTED, $modifiers, true) === false
				&& in_array(T_PRIVATE, $modifiers, true) === false);
			$modifiers = [];

			$name = self::nextMeaningful($body, $i);
			if ($name === null || $name[0] !== T_STRING) {
				continue;
			}

			$signature = self::readParameterList($body, $i);
			if ($isPublic === true) {
				$methods[$name[1]] = $signature;
			}
		}//end for

		return $methods;
	}//end readMethods()

	/**
	 * Read one function's parameter list.
	 *
	 * Counts a parameter as required when it carries no `=` default and is not
	 * variadic. Promoted constructor properties are ordinary parameters here,
	 * which is what a caller sees.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $body The class body's tokens.
	 * @param integer                                  $from Index of the T_FUNCTION token.
	 *
	 * @return array{total:int, required:int, params:list<string>} The signature.
	 */
	private static function readParameterList(array $body, int $from): array {
		$count = count($body);
		$start = null;
		for ($i = $from; $i < $count; $i++) {
			if ($body[$i] === '(') {
				$start = $i;
				break;
			}
		}

		if ($start === null) {
			return [
				'total' => 0,
				'required' => 0,
				'params' => [],
			];
		}

		$depth = 0;
		$params = [];
		$optional = [];
		$current = null;
		$hasDefault = false;
		$isVariadic = false;

		for ($i = $start; $i < $count; $i++) {
			$token = $body[$i];
			if (is_string($token) === true) {
				if ($token === '(' || $token === '[') {
					$depth++;
					continue;
				}

				if ($token === ')' || $token === ']') {
					$depth--;
					if ($depth > 0) {
						continue;
					}

					if ($current !== null) {
						$params[] = $current;
						$optional[] = ($hasDefault === true || $isVariadic === true);
					}

					break;
				}

				if ($depth === 1 && $token === ',') {
					if ($current !== null) {
						$params[] = $current;
						$optional[] = ($hasDefault === true || $isVariadic === true);
					}

					$current = null;
					$hasDefault = false;
					$isVariadic = false;
					continue;
				}

				if ($depth === 1 && $token === '=') {
					$hasDefault = true;
				}

				continue;
			}//end if

			if ($depth !== 1) {
				continue;
			}

			if ($token[0] === T_VARIABLE && $current === null) {
				$current = substr($token[1], 1);
				continue;
			}

			if ($token[0] === T_ELLIPSIS) {
				$isVariadic = true;
			}
		}//end for

		$required = 0;
		foreach ($optional as $index => $isOptional) {
			if ($isOptional === false) {
				$required = ($index + 1);
			}
		}

		return [
			'total' => count($params),
			'required' => $required,
			'params' => $params,
		];
	}//end readParameterList()

	/**
	 * Collect public constants and the source text of their values.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $body The class body's tokens.
	 *
	 * @return array<string, string> Constant name to value source.
	 */
	private static function readConstants(array $body): array {
		$constants = [];
		$count = count($body);
		$modifiers = [];
		$depth = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $body[$i];
			$depth += self::depthDelta($token);
			if (is_string($token) === true) {
				continue;
			}

			if ($depth > 0 || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
				continue;
			}

			if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL], true) === true) {
				$modifiers[] = $token[0];
				continue;
			}

			if ($token[0] !== T_CONST) {
				$modifiers = [];
				continue;
			}

			$isPublic = (in_array(T_PROTECTED, $modifiers, true) === false
				&& in_array(T_PRIVATE, $modifiers, true) === false);
			$modifiers = [];

			$name = self::nextMeaningful($body, $i);
			if ($name === null || $name[0] !== T_STRING) {
				continue;
			}

			$value = '';
			for ($j = ($i + 1); $j < $count; $j++) {
				if ($body[$j] === ';') {
					break;
				}

				if (is_array($body[$j]) === true && $body[$j][0] === T_WHITESPACE) {
					continue;
				}

				$value .= (is_array($body[$j]) === true ? $body[$j][1] : $body[$j]);
			}

			if ($isPublic === true) {
				// Drop the `NAME=` prefix, keeping only the value's source.
				$constants[$name[1]] = (string)preg_replace('/^'.preg_quote($name[1], '/').'=/', '', $value);
			}
		}//end for

		return $constants;
	}//end readConstants()

	/**
	 * Collect declared property names, at any visibility.
	 *
	 * Needed because an `OCP\AppFramework\Db\Entity` subclass serves
	 * `getFoo()` / `setFoo()` / `isFoo()` for each of these through `__call`
	 * without declaring a single one of them.
	 *
	 * @param list<array{0:int,1:string,2:int}|string> $body The class body's tokens.
	 *
	 * @return list<string> The property names.
	 */
	private static function readProperties(array $body): array {
		$properties = [];
		$count = count($body);
		$inDeclaration = false;
		$depth = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $body[$i];
			$depth += self::depthDelta($token);
			if (is_string($token) === true) {
				if ($token === ';' && $depth === 0) {
					$inDeclaration = false;
				}

				continue;
			}

			if ($depth > 0) {
				continue;
			}

			if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true) === true) {
				$inDeclaration = true;
				continue;
			}

			if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
				$inDeclaration = false;
				continue;
			}

			if ($inDeclaration === true && $token[0] === T_VARIABLE) {
				$properties[] = substr($token[1], 1);
				$inDeclaration = false;
			}
		}//end for

		return $properties;
	}//end readProperties()
}//end class
