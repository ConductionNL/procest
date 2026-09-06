<?php

/**
 * Guard dialect tests: the spellings the engine tolerates, and the one it does not.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\MandaatValidationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\MandaatGuard;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The visual workflow editor stores guards flat, in its own spelling.
 *
 * `TransitionConfigPanel.vue` writes `{type, fieldName}`, `{type,
 * documentTypeName}` and `{type, roleTypeId}` onto the transition, and those
 * guards are stored data on installs that already have hand-authored
 * workflows. The engine reads `field`, `documentType` and `allowedRoles`, so
 * every one of those guards resolved nothing. Translating them is what keeps
 * an administrator's workflow working; nesting under `config` is NOT
 * translated, because two positions for one parameter is a coin toss for the
 * next author.
 *
 * @covers \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 * @covers \OCA\Dossiq\Service\Transitions\GuardRegistry
 *
 * @uses \OCA\Dossiq\Service\Transitions\ChecklistGuard
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 * @uses \OCA\Dossiq\Service\Transitions\MandaatGuard
 * @uses \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard
 * @uses \OCA\Dossiq\Service\Transitions\RequiredFieldGuard
 * @uses \OCA\Dossiq\Service\Transitions\RoleGuard
 */
class GuardDialectTest extends TestCase {

	/**
	 * The editor's field spelling reaches the required-field evaluator.
	 *
	 * @return void
	 */
	public function testTheEditorsFieldSpellingIsTranslated(): void {
		$guards = (new TransitionSpecReader())->extractGuards(
			transition: ['guards' => [['type' => 'requiredField', 'fieldName' => 'stemuitslag']]]
		);

		self::assertSame('stemuitslag', $guards[0]['field']);
	}//end testTheEditorsFieldSpellingIsTranslated()

	/**
	 * The editor's document spelling reaches the required-document evaluator.
	 *
	 * @return void
	 */
	public function testTheEditorsDocumentSpellingIsTranslated(): void {
		$guards = (new TransitionSpecReader())->extractGuards(
			transition: ['guards' => [['type' => 'requiredDocument', 'documentTypeName' => 'Besluitdocument']]]
		);

		self::assertSame('Besluitdocument', $guards[0]['documentType']);
	}//end testTheEditorsDocumentSpellingIsTranslated()

	/**
	 * A single role, however spelled, becomes a one-entry allow-list.
	 *
	 * @return void
	 */
	public function testASingleRoleBecomesAnAllowList(): void {
		$reader = new TransitionSpecReader();

		foreach (['roleTypeId', 'roleName', 'role', 'requiredRole'] as $spelling) {
			$guards = $reader->extractGuards(
				transition: ['guards' => [['type' => 'roleGuard', $spelling => 'Behandelaar']]]
			);

			self::assertSame(['Behandelaar'], $guards[0]['allowedRoles'], $spelling . ' must be translated');
		}
	}//end testASingleRoleBecomesAnAllowList()

	/**
	 * The canonical spelling wins when a guard carries both.
	 *
	 * @return void
	 */
	public function testTheCanonicalSpellingWins(): void {
		$guards = (new TransitionSpecReader())->extractGuards(
			transition: [
				'guards' => [
					['type' => 'requiredField', 'field' => 'stemuitslag', 'fieldName' => 'iets anders'],
					['type' => 'roleGuard', 'allowedRoles' => ['Beslisser'], 'roleName' => 'Behandelaar'],
				],
			]
		);

		self::assertSame('stemuitslag', $guards[0]['field']);
		self::assertSame(['Beslisser'], $guards[1]['allowedRoles']);
	}//end testTheCanonicalSpellingWins()

	/**
	 * A parameter block nested under `config` is reported, not quietly obeyed.
	 *
	 * This is the shape three shipped bundles carried. The engine reads the top
	 * level, so the guard evaluated as unconfigured and the failure read as
	 * "the case is not ready" instead of "this guard was never wired up".
	 *
	 * @return void
	 */
	public function testANestedConfigBlockIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				self::stringContains('a position the transition engine never reads'),
				self::callback(static fn (array $context): bool => $context['type'] === 'requiredField')
			);

		$registry = $this->buildRegistry(logger: $logger);
		$results = $registry->evaluateAll(
			guards: [['type' => 'requiredField', 'config' => ['field' => 'stemuitslag']]],
			case: ['id' => 'c', 'stemuitslag' => 'Unaniem'],
			userId: 'u',
		);

		self::assertFalse($results[0]['passed'], 'A guard the engine cannot read must not silently pass');
	}//end testANestedConfigBlockIsReported()

	/**
	 * A guard carrying no `config` block says nothing.
	 *
	 * @return void
	 */
	public function testACanonicalGuardIsNotReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$registry = $this->buildRegistry(logger: $logger);
		$results = $registry->evaluateAll(
			guards: [['type' => 'requiredField', 'field' => 'stemuitslag']],
			case: ['id' => 'c', 'stemuitslag' => 'Unaniem'],
			userId: 'u',
		);

		self::assertTrue($results[0]['passed']);
	}//end testACanonicalGuardIsNotReported()

	/**
	 * A registry wired with the real evaluators and a given logger.
	 *
	 * @param LoggerInterface $logger The logger to observe.
	 *
	 * @return GuardRegistry
	 */
	private function buildRegistry(LoggerInterface $logger): GuardRegistry {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		return new GuardRegistry(
			new ChecklistGuard($settings, new NullLogger()),
			new RequiredFieldGuard(),
			new RequiredDocumentGuard(),
			new RoleGuard($this->createMock(IGroupManager::class), $userManager, new NullLogger()),
			new MandaatGuard($this->createMock(MandaatValidationService::class)),
			$logger,
		);
	}//end buildRegistry()
}//end class
