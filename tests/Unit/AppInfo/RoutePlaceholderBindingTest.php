<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\AppInfo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Every URL placeholder must be named the same as the argument it binds to.
 *
 * Nextcloud's Dispatcher resolves a controller argument BY NAME —
 * `$this->request->getParam($param, $default)` — never by position. So a route
 * declaring `{zaakId}` against a method signing `string $caseId` binds
 * null, the typehint throws a TypeError, and the request is answered HTTP 400.
 * For any input. On every call.
 *
 * Five routes were in exactly that state, left behind by the Dutch→English
 * vocabulary sweep, which renamed the method parameters and not the URLs. NO
 * existing check saw it: the route exists, the controller exists, the method
 * exists, and gate-6 (route-reachability) verifies precisely those three things.
 * Only the NAMES disagreed, and a name is not something any of them compared.
 *
 * This is the check that compares them.
 */
class RoutePlaceholderBindingTest extends TestCase {
	/**
	 * Controller-name suffixes that never take a bound URL argument.
	 *
	 * @var array<int, string>
	 */
	private const CONTROLLER_NS = 'OCA\\Dossiq\\Controller\\';

	/**
	 * Load the declared routes.
	 *
	 * @return array<int, array<string, mixed>> The `routes` list.
	 */
	private function routes(): array {
		$declared = require __DIR__ . '/../../../appinfo/routes.php';
		$this->assertIsArray($declared, 'appinfo/routes.php did not return an array.');
		$this->assertArrayHasKey('routes', $declared);

		return $declared['routes'];
	}

	/**
	 * Turn `subsidie#transition` into its controller class and method.
	 *
	 * Nextcloud's own convention: the part before `#` is the controller in
	 * lowerCamelCase, optionally namespaced with `\`, and gains a `Controller`
	 * suffix.
	 *
	 * @param string $name The route's `name` value.
	 *
	 * @return array{0: string, 1: string}|null Class and method, or null when unresolvable.
	 */
	private function target(string $name): ?array {
		if (str_contains($name, '#') === false) {
			return null;
		}

		[$controller, $method] = explode('#', $name, 2);
		$class = self::CONTROLLER_NS . str_replace('\\', '\\', ucfirst($controller)) . 'Controller';
		if (class_exists($class) === false) {
			return null;
		}

		return [$class, $method];
	}

	/**
	 * Extract `{placeholder}` names from a route URL.
	 *
	 * @param string $url The route URL.
	 *
	 * @return array<int, string> The placeholder names, in order.
	 */
	private function placeholders(string $url): array {
		$matches = [];
		preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $url, $matches);

		return $matches[1];
	}

	/**
	 * Every placeholder names a parameter the target method actually declares.
	 *
	 * A placeholder that no parameter answers to is only harmless when the
	 * method takes it off the request itself; a placeholder bound to a
	 * NON-NULLABLE scalar parameter that does not exist is the 400.
	 *
	 * @return void
	 */
	public function testEveryPlaceholderBindsToADeclaredParameter(): void {
		$offenders = [];

		foreach ($this->routes() as $route) {
			$name = (string)($route['name'] ?? '');
			$url = (string)($route['url'] ?? '');
			$placeholders = $this->placeholders(url: $url);
			if ($placeholders === []) {
				continue;
			}

			$target = $this->target(name: $name);
			if ($target === null) {
				continue;
			}

			[$class, $method] = $target;
			$reflection = new ReflectionClass($class);
			if ($reflection->hasMethod($method) === false) {
				continue;
			}

			$declared = [];
			$required = [];
			foreach ($reflection->getMethod($method)->getParameters() as $parameter) {
				$declared[] = $parameter->getName();
				$type = $parameter->getType();
				$nullable = ($type instanceof ReflectionNamedType) ? $type->allowsNull() : true;
				if ($parameter->isDefaultValueAvailable() === false && $nullable === false) {
					$required[] = $parameter->getName();
				}
			}

			// A method with a required, non-nullable parameter that NO placeholder
			// names cannot be satisfied from the URL. That is the 400.
			foreach ($required as $parameterName) {
				if (in_array($parameterName, $placeholders, true) === false) {
					$offenders[] = sprintf(
						'%s (%s) requires $%s, but the URL offers {%s}',
						$name,
						$url,
						$parameterName,
						implode('}, {', $placeholders)
					);
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"These routes answer HTTP 400 for every request — the Dispatcher binds by NAME:\n"
			. implode("\n", $offenders)
		);
	}
}
