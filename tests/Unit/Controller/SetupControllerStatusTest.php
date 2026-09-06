<?php

/**
 * SetupController::status() Unit Tests
 *
 * The wizard's whole behaviour is decided by this payload, and until now
 * nothing tested it. Two things it has to get right:
 *
 * 1. Every declared step must appear in `steps`. CnAppRoot distinguishes "the
 *    server reports this step as not done" from "the server never mentioned
 *    this step"; only the first prompts. A step omitted here is invisible to
 *    the wizard however unfinished it is.
 * 2. `completed` describes REQUIRED steps only. It was read as "nothing left
 *    to do at all", which suppressed the optional `seed` step and left dossiq
 *    with no reachable way to load demo data.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SetupController;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\SeedDataService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetupController::status().
 *
 * @covers \OCA\Dossiq\Controller\SetupController
 */
class SetupControllerStatusTest extends TestCase {

	/**
	 * Build a controller whose app config answers from a fixed map.
	 *
	 * @param array<string,string> $config             App-config values.
	 * @param bool                 $openRegisterOnline Whether OpenRegister is available.
	 *
	 * @return SetupController The controller under test.
	 */
	private function controller(array $config, bool $openRegisterOnline = true): SetupController {
		return $this->build(config: $config, openRegisterOnline: $openRegisterOnline)['controller'];

	}//end controller()

	/**
	 * Build a controller and hand back its collaborators.
	 *
	 * @param array<string,string>     $config             App-config values.
	 * @param bool                     $openRegisterOnline Whether OpenRegister is available.
	 * @param array<string,mixed>|null $seedResult         What the seeder returns, when it is asked.
	 * @param array<string,mixed>|\Throwable|null $demoResult What the demo import returns, or throws.
	 * @param array<string,mixed>|null $requestParams      Request params, for the config-fields step.
	 *
	 * @return array{controller: SetupController, written: array<string,string>, settings: SettingsService} The
	 *   controller, a live view of every app-config value it wrote, and its settings collaborator.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — one harness, one controller, five collaborators
	 */
	private function build(
		array $config,
		bool $openRegisterOnline = true,
		?array $seedResult = null,
		array|\Throwable|null $demoResult = null,
		?array $requestParams = null,
	): array {
		$written = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($config): string {
					return $config[$key] ?? $default;
				}
			);
		$appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$written): bool {
					$written[$key] = $value;
					return true;
				}
			);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegisterOnline);

		$seeder = $this->createMock(SeedDataService::class);
		// ADR-111 added the generated demo dataset as its own service and its
		// own setup step. Since the bezwaar/beroep `seed` step was retired,
		// the demo import IS the app's demo-data affordance, so it is driven
		// here rather than left as a bare constructor argument.
		$demo = $this->createMock(DemoDataService::class);
		// The offered list, so a test about VALIDATING a pick is not silently
		// asserting that an empty list rejects everything.
		$demo->method('listChoices')->willReturn(
			[
				['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
				['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 6, 'icon' => 'DatabaseOutline'],
			]
		);
		if ($seedResult !== null) {
			$seeder->method('seedBezwaarBeroepData')->willReturn($seedResult);
		}

		if ($demoResult instanceof \Throwable) {
			$demo->method('install')->willThrowException($demoResult);
		} elseif ($demoResult !== null) {
			$demo->method('install')->willReturn($demoResult);
		}

		$request = $this->createMock(IRequest::class);
		if ($requestParams !== null) {
			$request->method('getParams')->willReturn($requestParams);
			// 🔴 BOTH READERS, OR THE VALIDATION IS INVISIBLE TO THE TEST.
			// saveConfig() reads the dataset with getParam() and the rest of
			// the body with getParams(); a fake that answers only the second
			// makes every assertion about the first pass for the wrong reason.
			$request->method('getParam')
				->willReturnCallback(
					static function (string $key, $default = null) use ($requestParams) {
						return ($requestParams[$key] ?? $default);
					}
				);
		}

		$controller = new SetupController(
			appName: 'dossiq',
			request: $request,
			appConfig: $appConfig,
			demoDataService: $demo,
			settingsService: $settings,
			seedDataService: $seeder,
		);

		return ['controller' => $controller, 'written' => &$written, 'settings' => $settings];

	}//end build()

	/**
	 * A fully-provisioned register with the sample data not yet loaded.
	 *
	 * @return array<string,string> The app-config map.
	 */
	private function provisioned(): array {
		return [
			'register'         => 'dossiq',
			'case_type_schema' => 'caseType',
		];

	}//end provisioned()

	/**
	 * The retired seed step is not reported at all.
	 *
	 * It used to be reported as "not done", which was the honest answer to the
	 * wrong question. The step ran the `seed` action over
	 * `lib/Settings/bezwaar_seed_data.json`, whose case types are parked under
	 * `_caseTypes_disabled`, so every click answered 422 and the step could
	 * never become done. The manifest no longer declares it, and this payload
	 * is the wizard's step contract: a step reported here but declared nowhere
	 * is one CnSetupWizard can never prompt for.
	 *
	 * The demo-data offer, which is what actually shows a new reader the app
	 * working, is asserted alongside so this cannot pass on a payload that
	 * simply lost its steps.
	 *
	 * @return void
	 */
	public function testTheRetiredSeedStepIsNotReported(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['completed'], 'completed describes REQUIRED steps, and the required one is done');
		$this->assertArrayNotHasKey('seed', $data['steps'], 'the wizard declares no seed step to report on');
		$this->assertArrayHasKey('demo-data', $data['steps'], 'an omitted step is invisible to the wizard');
		$this->assertTrue($data['steps']['register-check']['done']);

	}//end testTheRetiredSeedStepIsNotReported()

	/**
	 * Every step the manifest declares must appear in the payload.
	 *
	 * Read straight from the shipped manifest rather than from a literal list,
	 * so adding a setup step without reporting it fails here instead of
	 * silently producing a step no wizard can ever prompt for.
	 *
	 * @return void
	 */
	public function testEveryActionableManifestStepIsReported(): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = [];
		foreach (($manifest['setup']['steps'] ?? []) as $step) {
			// `info` and `summary` carry no work, so the server has nothing to
			// report for them by design.
			if (in_array($step['type'], ['info', 'summary'], true) === true) {
				continue;
			}

			$declared[] = $step['id'];
		}

		$this->assertNotEmpty($declared, 'the manifest must declare actionable setup steps');

		$reported = array_keys($this->controller($this->provisioned())->status()->getData()['steps']);
		$this->assertEqualsCanonicalizing($declared, $reported);

	}//end testEveryActionableManifestStepIsReported()

	/**
	 * A recorded seed does not resurrect the step in the payload.
	 *
	 * `setup_seed_done` is still written by the `seed` action, which stays
	 * reachable over the API for an operator who un-parks the Dutch profile.
	 * That record must not put a step back into the wizard's contract: the
	 * manifest decides which steps exist, and app-config decides nothing.
	 *
	 * @return void
	 */
	public function testARecordedSeedDoesNotResurrectTheStep(): void {
		$data = $this->controller($this->provisioned() + ['setup_seed_done' => '1'])->status()->getData();

		$this->assertArrayNotHasKey('seed', $data['steps']);

	}//end testARecordedSeedDoesNotResurrectTheStep()

	/**
	 * An unprovisioned register blocks the app.
	 *
	 * @return void
	 */
	public function testRegisterCheckIsUnmetWithoutARegister(): void {
		$data = $this->controller([])->status()->getData();

		$this->assertFalse($data['completed']);
		$this->assertFalse($data['steps']['register-check']['done']);

	}//end testRegisterCheckIsUnmetWithoutARegister()

	/**
	 * OpenRegister being unreachable is itself an unmet required step.
	 *
	 * @return void
	 */
	public function testRegisterCheckIsUnmetWhenOpenRegisterIsUnavailable(): void {
		$data = $this->controller($this->provisioned(), openRegisterOnline: false)->status()->getData();

		$this->assertFalse($data['steps']['register-check']['done']);

	}//end testRegisterCheckIsUnmetWhenOpenRegisterIsUnavailable()

	/**
	 * With the payout integration configured and no signing secret, the step
	 * is outstanding.
	 *
	 * DwangsomPaymentCallbackController cannot verify a callback's origin
	 * without it, so this is the state the warning exists to catch.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsOutstandingWhenThePayoutIntegrationIsConfigured(): void {
		$config = $this->provisioned() + ['dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling'];
		$data   = $this->controller($config)->status()->getData();

		$this->assertFalse($data['steps']['dwangsom-secret']['done']);
		$this->assertFalse($data['dwangsom_callback_secret_configured']);

	}//end testDwangsomSecretIsOutstandingWhenThePayoutIntegrationIsConfigured()

	/**
	 * A configured secret settles the step.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsDoneOnceSet(): void {
		$config = $this->provisioned() + [
			'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
			'dwangsom_callback_secret'    => 's3cret',
		];
		$data = $this->controller($config)->status()->getData();

		$this->assertTrue($data['steps']['dwangsom-secret']['done']);
		$this->assertTrue($data['dwangsom_callback_secret_configured']);

	}//end testDwangsomSecretIsDoneOnceSet()

	/**
	 * With no payout integration there is no callback to sign, so the step is
	 * settled rather than nagging.
	 *
	 * @return void
	 */
	public function testDwangsomSecretIsSettledWhenThePayoutIntegrationIsNotConfigured(): void {
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertTrue($data['steps']['dwangsom-secret']['done']);
		$this->assertArrayNotHasKey(
			'dwangsom_callback_secret_configured',
			$data,
			'the legacy flag stays absent when the capability is off'
		);

	}//end testDwangsomSecretIsSettledWhenThePayoutIntegrationIsNotConfigured()

	/**
	 * The step and the legacy flag must never disagree.
	 *
	 * They are two representations of one fact, and two representations of one
	 * fact drift. Deriving both from the same value is the fix; this pins it.
	 *
	 * @return void
	 */
	public function testTheStepAndTheLegacyFlagAlwaysAgree(): void {
		foreach ([['secret' => ''], ['secret' => 's3cret']] as $case) {
			$config = $this->provisioned() + [
				'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
				'dwangsom_callback_secret'    => $case['secret'],
			];
			$data = $this->controller($config)->status()->getData();

			$this->assertSame(
				$data['steps']['dwangsom-secret']['done'],
				$data['dwangsom_callback_secret_configured']
			);
		}

	}//end testTheStepAndTheLegacyFlagAlwaysAgree()
	/**
	 * A seeder that created nothing has not completed the step.
	 *
	 * `seedBezwaarBeroepData()` returns `success: true` with every counter at
	 * zero when its payload is absent — which is the state it is in, its case
	 * types having been parked under `_caseTypes_disabled` in favour of a
	 * register.d fragment. Recording that as done made the affordance one-shot
	 * and silently useless: one click reported "Seeded 0 case types, 0 status
	 * types, 0 role types (0 skipped)" as a success, marked the step complete,
	 * and the wizard never offered it again.
	 *
	 * @return void
	 */
	public function testASeedThatCreatedNothingDoesNotRecordTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 0, 'statusTypes' => 0, 'roleTypes' => 0, 'workflows' => 0, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertSame(422, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertArrayNotHasKey('setup_seed_done', $built['written']);

	}//end testASeedThatCreatedNothingDoesNotRecordTheStepAsDone()

	/**
	 * A seeder that created something completes the step.
	 *
	 * The positive control: without it the assertion above would pass just as
	 * happily if the step could never be recorded at all.
	 *
	 * @return void
	 */
	public function testASeedThatCreatedSomethingRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 3, 'statusTypes' => 9, 'roleTypes' => 2, 'workflows' => 1, 'skipped' => 0]
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testASeedThatCreatedSomethingRecordsTheStepAsDone()

	/**
	 * A run that only SKIPPED objects still counts as having done the step:
	 * everything it would have created is already there.
	 *
	 * @return void
	 */
	public function testASeedThatOnlySkippedStillRecordsTheStepAsDone(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => true, 'caseTypes' => 0, 'statusTypes' => 0, 'roleTypes' => 0, 'workflows' => 0, 'skipped' => 12]
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('1', $built['written']['setup_seed_done'] ?? null);

	}//end testASeedThatOnlySkippedStillRecordsTheStepAsDone()

	/**
	 * A refused seed is reported with the seeder's own message.
	 *
	 * The `success: false` branch, distinct from the zero-counter one above:
	 * "the register is not configured" and "there was nothing to write" are
	 * different problems and must not arrive as the same sentence.
	 *
	 * @return void
	 */
	public function testARefusedSeedReportsTheSeedersOwnMessage(): void {
		$built = $this->build(
			config: $this->provisioned(),
			seedResult: ['success' => false, 'message' => 'Register or schemas not configured']
		);

		$response = $built['controller']->runAction(actionId: 'seed');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('Register or schemas not configured', $response->getData()['message']);
		$this->assertArrayNotHasKey('setup_seed_done', $built['written']);

	}//end testARefusedSeedReportsTheSeedersOwnMessage()

	/**
	 * An unknown action id answers 404 rather than doing nothing quietly.
	 *
	 * The step that names it is dead either way, but a 404 says so on screen
	 * where a silent 200 would let the wizard record it as finished.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsRefused(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'no-such-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('no-such-action', $response->getData()['message']);
		$this->assertSame([], $built['written']);

	}//end testAnUnknownActionIsRefused()

	/**
	 * Initialising the register forces the import.
	 *
	 * `force: true` is the whole point of the step. OpenRegister's importer
	 * version-gates a non-forced import and skips silently when the version
	 * has not moved, so an operator who clicks the button on an instance whose
	 * configuration is stale would be told it succeeded while nothing changed.
	 *
	 * @return void
	 */
	public function testInitialisingTheRegisterForcesTheImport(): void {
		$built = $this->build(config: $this->provisioned());
		$built['settings']->expects($this->once())
			->method('loadConfiguration')
			->with(true);

		$response = $built['controller']->runAction(actionId: 'init-register');

		$this->assertTrue($response->getData()['success']);

	}//end testInitialisingTheRegisterForcesTheImport()

	/**
	 * Installing the demo data reports the COUNTS, and records the decision.
	 *
	 * "Demo data installed" with no numbers cannot be told apart from an
	 * import that wrote nothing, which is the failure this whole step exists
	 * to make visible. With the bezwaar `seed` step retired, this is the app's
	 * only demo-data affordance, so it is the one that has to be right.
	 *
	 * @return void
	 */
	public function testInstallingTheDemoDataReportsTheCountsAndRecordsTheDecision(): void {
		$built = $this->build(
			config: $this->provisioned(),
			// The full shape DemoDataService::install() returns. A narrower
			// double let the controller read two undefined keys and the
			// message render them as empty, which is exactly the "installed
			// with no numbers" state this test exists to forbid.
			demoResult: [
				'objects' => 412,
				'requested' => 420,
				'refused' => 8,
				'unchanged' => 0,
				'registers' => 0,
				'schemas' => 0,
			]
		);

		$response = $built['controller']->runAction(actionId: 'install-demo-data');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('412', $data['message']);
		$this->assertStringContainsString('420', $data['message']);
		$this->assertStringContainsString('8 refused', $data['message']);
		$this->assertStringContainsString('0 already present', $data['message']);
		$this->assertSame(412, $data['detail']['objects']);
		$this->assertSame('installed', $built['written']['demo_data_decided'] ?? null);

		// 🔴 THE SCHEMA COUNT IS GONE FROM THE SENTENCE ON PURPOSE. It used to
		// read "stored across %d schemas", filled from `schemas` — how many
		// schemas the IMPORT DEFINED. Those two only ever agreed while the
		// demo import was forking a schema set of its own, so the message was
		// reporting the defect as if it were a feature. A demo set defines no
		// schemas now, and "across 0 schemas" would be worse than silence.
		$this->assertStringNotContainsString(
			'schemas',
			$data['message'],
			'the message must not claim a schema count the import does not have'
		);
		$this->assertSame(0, $data['detail']['schemas'], 'a demo set defines no schema');

	}//end testInstallingTheDemoDataReportsTheCountsAndRecordsTheDecision()

	/**
	 * A failed demo import does not record the step as decided.
	 *
	 * Marking the decision first would let a failed install present as a
	 * finished step, and the operator would never be offered it again.
	 *
	 * @return void
	 */
	public function testAFailedDemoImportIsNotRecordedAsDecided(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: new \RuntimeException('No demo dataset ships with this app')
		);

		$response = $built['controller']->runAction(actionId: 'install-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('No demo dataset', $response->getData()['message']);
		$this->assertArrayNotHasKey('demo_data_decided', $built['written']);

	}//end testAFailedDemoImportIsNotRecordedAsDecided()

	/**
	 * Declining the demo data is a decision the wizard can record.
	 *
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them, which is every
	 * production install.
	 *
	 * @return void
	 */
	public function testDecliningTheDemoDataFinishesTheStep(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $built['written']['demo_data_decided'] ?? null);
		// 🔴 IT HAS TO ANSWER BOTH STEPS. The step split into a choice plus a
		// run-action, and CnAppRoot opens the wizard while ANY optional step is
		// outstanding — so writing only the decision flag would leave the
		// choice open and the wizard covering every page.
		$this->assertSame('none', $built['written']['demo_dataset'] ?? null, 'skipping IS choosing none');

		$done = $this->controller(
			$this->provisioned() + ['demo_data_decided' => 'skipped', 'demo_dataset' => 'none']
		)->status()->getData();
		$this->assertTrue($done['steps']['demo-data']['done'], 'a recorded decline finishes the choice');
		$this->assertTrue($done['steps']['load-demo-data']['done'], 'and leaves nothing to run');

	}//end testDecliningTheDemoDataFinishesTheStep()

	/**
	 * The status document carries the datasets the choice step offers.
	 *
	 * 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
	 * `optionsSource: datasets` and carries no options of its own, so a dataset
	 * missing here is a dataset nobody can pick.
	 *
	 * @return void
	 */
	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// What the SERVICE offers is asserted in DemoDataServiceTest, against
		// the real descriptor. What matters here is that the controller passes
		// it through under the key the manifest names: `optionsSource:
		// datasets` reads exactly this, and a step whose source is absent
		// offers nothing at all.
		$data = $this->controller($this->provisioned())->status()->getData();

		$this->assertArrayHasKey('datasets', $data);
		$this->assertIsArray($data['datasets']);

	}//end testStatusCarriesTheOptionListTheChoiceStepReads()

	/**
	 * Running the load step with no dataset picked refuses rather than guessing.
	 *
	 * 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one step
	 * early would plant example objects nobody asked for.
	 *
	 * @return void
	 */
	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		$built = $this->build(config: $this->provisioned());

		$response = $built['controller']->runAction(actionId: 'load-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('Pick a dataset', $response->getData()['message']);

	}//end testLoadingWithoutAChoiceRefusesRatherThanGuessing()

	/**
	 * A dataset nobody ships is refused rather than stored.
	 *
	 * Storing it would leave the load step pointing at nothing, so the failure
	 * would surface one step later with no clue why.
	 *
	 * @return void
	 */
	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		$built = $this->build(config: $this->provisioned(), requestParams: ['demo_dataset' => 'atlantis']);

		$response = $built['controller']->saveConfig();

		$this->assertFalse($response->getData()['success']);
		$this->assertSame([], $built['written']);

	}//end testAnUnknownDatasetIsRefusedRatherThanStored()

	/**
	 * A known dataset is stored, and the rest of the body still goes through.
	 *
	 * @return void
	 */
	public function testAKnownDatasetIsStoredAlongsideTheOtherFields(): void {
		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['demo_dataset' => 'demo', 'some_field' => 'kept']
		);

		$response = $built['controller']->saveConfig();

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('demo', $built['written']['demo_dataset'] ?? null);
		$this->assertSame('kept', $built['written']['some_field'] ?? null);

	}//end testAKnownDatasetIsStoredAlongsideTheOtherFields()

	/**
	 * A list is accepted, because the wizard contract allows one.
	 *
	 * The step is not `multiple`, but the same endpoint serves steps that are,
	 * so an array must not reach `(string)` and become "Array".
	 *
	 * @return void
	 */
	public function testAListIsAcceptedBecauseTheWizardContractAllowsOne(): void {
		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['demo_dataset' => ['demo']]
		);

		$this->assertTrue($built['controller']->saveConfig()->getData()['success']);

	}//end testAListIsAcceptedBecauseTheWizardContractAllowsOne()

	/**
	 * A value that is not a scalar is refused rather than cast.
	 *
	 * The body is whatever the browser posted. A nested array would otherwise
	 * reach `(string)` and raise a fatal.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotAScalarIsRefused(): void {
		$built = $this->build(
			config: $this->provisioned(),
			requestParams: ['demo_dataset' => [['demo']]]
		);

		$this->assertFalse($built['controller']->saveConfig()->getData()['success']);
		$this->assertSame([], $built['written']);

	}//end testAValueThatIsNotAScalarIsRefused()

	/**
	 * Choosing none and then running imports nothing, rather than refusing.
	 *
	 * 🔴 REFUSING WOULD LEAVE THE STEP OPEN. The load step still runs after
	 * "None"; it has to record the decision and import nothing.
	 *
	 * @return void
	 */
	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		$built = $this->build(config: $this->provisioned() + ['demo_dataset' => 'none']);

		$data = $built['controller']->runAction(actionId: 'load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
		$this->assertSame('skipped', $built['written']['demo_data_decided'] ?? null);

	}//end testChoosingNoneAndThenRunningImportsNothing()

	/**
	 * The legacy action still imports the shipped dataset.
	 *
	 * `install-demo-data` was the id before the step asked WHICH dataset. A
	 * runbook or script that still posts it must keep working, and it names the
	 * shipped set by naming itself.
	 *
	 * @return void
	 */
	public function testTheLegacyActionStillImportsTheShippedDataset(): void {
		$built = $this->build(
			config: $this->provisioned(),
			demoResult: ['objects' => 4, 'requested' => 4, 'refused' => 0, 'unchanged' => 0]
		);

		$data = $built['controller']->runAction(actionId: 'install-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('4', $data['message']);

	}//end testTheLegacyActionStillImportsTheShippedDataset()

	/**
	 * A config-fields step stores its values, encoding what is not a scalar.
	 *
	 * `_route` is the router's own parameter rather than a field someone
	 * filled in, so storing it would write a junk app-config key on every save.
	 *
	 * @return void
	 */
	public function testAConfigStepStoresItsFieldsAndSkipsTheRouteParameter(): void {
		$built = $this->build(
			config: [],
			requestParams: [
				'_route' => 'dossiq.setup.saveConfig',
				'dwangsom_callback_secret' => 's3cret',
				'retry_delays' => [1, 5, 15],
			]
		);

		$response = $built['controller']->saveConfig();

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('s3cret', $built['written']['dwangsom_callback_secret'] ?? null);
		$this->assertSame('[1,5,15]', $built['written']['retry_delays'] ?? null);
		$this->assertArrayNotHasKey('_route', $built['written']);

	}//end testAConfigStepStoresItsFieldsAndSkipsTheRouteParameter()

}//end class
