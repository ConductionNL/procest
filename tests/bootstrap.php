<?php

/**
 * PHPUnit Bootstrap
 *
 * Bootstrap file for PHPUnit tests in the Dossiq app.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Guard against a dangling vendor/nextcloud/ocp/OCP symlink. The nextcloud/ocp
// composer package normally vendors real OCP\ source files, but when its
// post-install step runs inside a live Nextcloud dev container (bind-mounted
// at /var/www/html), it instead symlinks OCP/ -> /var/www/html/lib/public as
// an optimisation. That symlink is only valid *inside that specific live
// container*. If vendor/ is later copied (e.g. rsync'd) into a bare CI/test
// container that has no /var/www/html, the symlink dangles and every OCP\
// class fails to resolve — surfacing 100+ lines deep as a misleading
// "Class ... not found" error from an unrelated stub include (see the
// 2026-07-14 dev-test-failures investigation, where this dangling symlink
// was mistaken for a code regression across PRs #202-#211 before being
// traced here). Fail fast with an actionable message instead.
$ocpVendorDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_link($ocpVendorDir) === true && file_exists($ocpVendorDir) === false) {
	fwrite(
		STDERR,
		"\nFATAL: vendor/nextcloud/ocp/OCP is a dangling symlink to "
		. (readlink($ocpVendorDir) ?: '(unknown target)') . "\n"
		. 'This happens when vendor/ is copied from a checkout that was '
		. 'composer-installed inside a live Nextcloud dev container (which '
		. "symlinks OCP/ to that container's /var/www/html/lib/public) into "
		. "an environment without that path — e.g. rsync'ing vendor/ into a "
		. "bare php:8.3-cli test container.\n"
		. 'Fix: rm -rf vendor && composer install --no-interaction '
		. '--ignore-platform-reqs (do NOT rsync vendor/ from a live-NC-mounted '
		. "checkout).\n\n"
	);
	exit(1);
}//end if

unset($ocpVendorDir);

// Polyfill easter_date() when the PHP `calendar` extension is not loaded
// (it is absent from the slim PHP-CLI image used in the dev container).
// Production Nextcloud images ship the calendar extension, so this guard is a
// no-op there. The algorithm is the standard Gauss/Meeus computation returning
// a Unix timestamp for noon (matching the extension's CAL_EASTER_DEFAULT).
if (function_exists('easter_date') === false) {
	/**
	 * Compute the Unix timestamp of Easter Sunday for a Gregorian year.
	 *
	 * @param int|null $year The year (defaults to the current year).
	 *
	 * @return int Unix timestamp (UTC noon) of Easter Sunday.
	 */
	function easter_date(?int $year = null): int {
		$year = ($year ?? (int)date('Y'));

		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv((($b - $f) + 1), 3);
		$h = (((19 * $a) + $b - $d - $g + 15) % 30);
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = ((32 + (2 * $e) + (2 * $i) - $h - $k) % 7);
		$m = intdiv(($a + (11 * $h) + (22 * $l)), 451);

		$month = intdiv(($h + $l - (7 * $m) + 114), 31);
		$day = ((($h + $l - (7 * $m) + 114) % 31) + 1);

		return gmmktime(0, 0, 0, $month, $day, $year);
	}//end easter_date()
}//end if

// Load the OC-internal and Doctrine stubs FIRST — before the OCP pre-load and
// before any OCP autoloader is registered.
//
// These used to be required at the BOTTOM of this file, which made them useless
// to the two consumers that actually need them:
//
//   1. The multi-pass OCP classmap pre-load below filters the Nextcloud
//      classmap down to `OCP\` / `NCU\` entries only, so it never loads
//      `OC\Hooks\Emitter`. `OCP\Files\IRootFolder extends Folder, Emitter`, so
//      IRootFolder failed to declare on every one of the 10 passes and ended up
//      cached nowhere — producing "Class or interface OCP\Files\IRootFolder
//      does not exist" for every test that mocks it.
//   2. `OCP\DB\QueryBuilder\IQueryBuilder` evaluates class constants that
//      reference `Doctrine\DBAL\ParameterType` at parse time, so the Doctrine
//      placeholders must likewise already be in the class table.
//
// Every declaration in both files is class_exists()/interface_exists()-guarded,
// so loading them this early is a no-op when a real Nextcloud runtime later
// supplies the genuine classes.
require_once __DIR__ . '/Unit/Stubs/DoctrineStubs.php';
require_once __DIR__ . '/Unit/Stubs/OcInternalStubs.php';

// Pre-load ALL OCP\ / NCU\ classes from the real Nextcloud lib/public tree
// BEFORE lib/base.php runs. This ensures every OCP interface/class is already
// in PHP's class cache before any installed-app vendor autoloader (e.g.
// openregister, which loads its old nextcloud/ocp v31 stub from inside
// Application::register()) can supply a stale version.
//
// Background: NC 34 uses #[\Override] in OC\Settings\Manager, OC\URLGenerator,
// OC\Activity\Manager, OC\Notification\Manager, etc. These reference interface
// methods added in NC 33 (getAdminDelegatedSettings, bulkPublish,
// linkToRemote, …). Some Conduction apps ship nextcloud/ocp v31 stubs (missing
// those methods) and load their own vendor autoloader with $loader->register(true)
// during Application::register(), placing the old classmap at the FRONT of the
// SPL queue. PHP 8.4 validates #[\Override] at class-compile time and throws a
// fatal error when the interface resolved is the stale stub.
//
// The files are loaded in multiple passes (up to 10) so that inter-interface
// dependencies are resolved automatically: if pass N fails to load a file
// because its dependency is not yet loaded, pass N+1 retries after the
// dependency has been loaded by an earlier successful entry. The try/catch
// swallows only genuine non-fixable errors; once a class is cached the pass
// exits early.
// NC ships Psr\Http\Client\ClientInterface (and other PSR packages) via its
// 3rdparty/ directory, registered through 3rdparty/autoload.php. Some OCP
// interfaces extend PSR interfaces (e.g. OCP\Http\Client\IClient extends
// Psr\Http\Client\ClientInterface). Without 3rdparty registered first, those
// OCP interfaces cannot be included in the multi-pass pre-load below, which
// leaves them uncached and lets openregister's stale classmap loader supply an
// older stub version that omits recently-added methods.
if (file_exists(__DIR__ . '/../../../3rdparty/autoload.php') === true) {
	require_once __DIR__ . '/../../../3rdparty/autoload.php';
}

$ncLibPublicDir = realpath(__DIR__ . '/../../../lib/public');
if ($ncLibPublicDir !== false && is_dir($ncLibPublicDir) === true) {
	$ncClassmapFile = __DIR__ . '/../../../lib/composer/composer/autoload_classmap.php';
	if (file_exists($ncClassmapFile) === true) {
		/** @var array<string,string> $ncFullClassmap */
		$ncFullClassmap = require $ncClassmapFile;

		// Filter to OCP\ and NCU\ entries only.
		$ocpClassmap = array_filter(
			$ncFullClassmap,
			static function (string $class): bool {
				return strncmp($class, 'OCP\\', 4) === 0 || strncmp($class, 'NCU\\', 4) === 0;
			},
			ARRAY_FILTER_USE_KEY
		);

		// Multi-pass load: retry on dependency errors until stable.
		$pending = $ocpClassmap;
		for ($pass = 0; $pass < 10 && count($pending) > 0; $pass++) {
			$stillPending = [];
			foreach ($pending as $class => $file) {
				if (class_exists($class, false) === true
					|| interface_exists($class, false) === true
					|| trait_exists($class, false) === true
					|| (function_exists('enum_exists') === true && enum_exists($class, false) === true)
				) {
					// Already cached by an earlier pass or autoloader.
					continue;
				}

				if (file_exists($file) === false) {
					continue;
				}

				// Use plain `include` (not `include_once`) so that if the
				// first pass fails mid-file (because a dependency was not yet
				// loaded), a subsequent pass can retry the same file. PHP's
				// `include_once` marks the file as "included" even after a
				// partial-failure, preventing any later retry.
				try {
					@include $file;
				} catch (\Throwable $e) {
					// Dependency not yet loaded — defer to next pass.
					$stillPending[$class] = $file;
				}
			}//end foreach

			$pending = $stillPending;
		}//end for

		unset($ocpClassmap, $ncFullClassmap, $pending, $stillPending);
	}//end if
}//end if

// Load a real Nextcloud server when one is present (CI/dev container). This
// must happen BEFORE the stub OCP PSR-4 registration below so that NC's
// classmap autoloader takes priority for any remaining OCP classes.
if (defined('OC_CONSOLE') === false) {
	if (file_exists(__DIR__ . '/../../../lib/base.php') === true) {
		include_once __DIR__ . '/../../../lib/base.php';
	}

	if (file_exists(__DIR__ . '/../../../tests/autoload.php') === true) {
		include_once __DIR__ . '/../../../tests/autoload.php';
	}
}

// Register OCP and NCU namespaces from the nextcloud/ocp stub package so that
// PHPUnit can mock OCP interfaces without a full Nextcloud installation.
// When a real Nextcloud is present (base.php was loaded above), the NC classmap
// loader already owns the OCP\ namespace, so we skip the stub registration to
// avoid overriding real OCP classes with an older stub version.
//
// This must test whether Nextcloud ACTUALLY BOOTSTRAPPED, not whether its
// `lib/base.php` merely exists on disk. In the standard `apps-extra/` checkout
// layout that file always exists, so the previous `file_exists()` check
// unconditionally suppressed the fallback registration — including in the
// common case where base.php was never loaded at all because phpunit.xml
// defines OC_CONSOLE (see the guard above), leaving the OCP\ namespace with no
// autoloader whatsoever. `\OC_App` is declared by base.php and by nothing else,
// so its presence is a true "NC runtime is live" signal.
$ncBaseLoaded = class_exists('\OC_App', false);
if ($ncBaseLoaded === false) {
	$loaders = spl_autoload_functions();
	foreach ($loaders as $loader) {
		if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
			$loader[0]->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
			$loader[0]->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
			break;
		}
	}
}

// (DoctrineStubs.php and OcInternalStubs.php are loaded near the top of this
// file — they must precede the OCP pre-load, not follow it. See the comment
// above the pre-load block.)

// Shared in-memory ObjectService fake. Lives in tests/Unit/Fixtures/ so
// every termijnbewaking + archief-edepot unit test file can resolve
// `FakeTermijnStore` even when run standalone (previously it sat at the
// bottom of TermijnServiceTest.php and only loaded if PHPUnit happened
// to require that file first).
require_once __DIR__ . '/Unit/Fixtures/FakeTermijnStore.php';

// Shared engine fake for the termijn timer mapping tests. Mirrors the REAL
// FlowTimerService signatures; references the FlowTimer stub lazily, so the
// load order relative to the stub block below does not matter.
require_once __DIR__ . '/Unit/Fixtures/FlowTimerEngineFake.php';

// Schema-aware stand-in for StufRegisterAccess. Reproduces the two live object
// store behaviours a hand-written mock hides — a save drops what the schema
// does not declare, and a filter on an undeclared property matches zero rows —
// so the StUF tests cannot agree with a caller that has drifted off contract.
require_once __DIR__ . '/Unit/Fixtures/SchemaAwareStufRegister.php';

// OCP\Http\Client interface stubs — the vendored nextcloud/ocp does not ship
// the OCP\Http\Client namespace, so services depending on IClientService
// (PublicationService, MandaatValidationService) cannot be mocked without these.
// Guarded by interface_exists() so they no-op under a real Nextcloud runtime.
require_once __DIR__ . '/Stubs/HttpClientStubs.php';

// ── The REAL flow engine, whenever OpenRegister sits next to this app ─────
//
// Everything below this line STUBS OpenRegister so the suite runs on a machine
// without it. A stub is honest about a node's own arithmetic and useless about
// the one property heartbeat recovery depends on: what the ENGINE does to a
// parked node's resume slot between passes, and what it hands back to the node
// when it re-enters it on a timer. openregister#3362 measured that class — 30
// of 32 added statements uncovered, because every recovery test mocked the
// seam it was meant to exercise.
//
// So when OpenRegister's source sits beside this app, register it and let every
// guard below resolve the REAL class. That layout is not hypothetical: the
// shared PHPUnit job clones openregister to `server/apps/openregister`, beside
// `server/apps/dossiq`, before it runs this bootstrap.
//
// ALL OR NOTHING, deliberately. A run holding the real FlowRunService and a
// stub FlowSuspension would be a third engine agreeing with neither, and its
// green would mean less than either.
//
// ⚠️ IT MUST GO IN FRONT OF tests/Stubs, AND ADDING A PSR-4 PATH CANNOT DO
// THAT. composer.json's autoload-dev maps `OCA\OpenRegister\` at tests/Stubs
// and the generated CLASSMAP names every stub file outright — a classmap hit
// is answered before PSR-4 is consulted at all, so both `addPsr4` and
// `setPsr4` leave the stub winning. Only a loader registered AHEAD of
// Composer's own decides first, which is what `register(true)` does. It knows
// exactly one prefix and returns nothing for anything else, so every other
// class still resolves exactly as before, and a class the real app does not
// have still falls through to its stub.
// ⚠️ ITS OWN DEPENDENCIES COUNT AS PART OF "PRESENT". The flow engine builds
// on symfony/workflow, which lives in OpenRegister's vendor and nowhere in
// this app's. Registering the source without them would produce classes that
// load and then fail mid-run — the worst of the three states, because it looks
// like the real engine and behaves like nothing. So an uninstalled sibling
// counts as absent and the stubs stay.
//
// The dependency loader is APPENDED, and OCP / NCU / OCA are filtered out of
// it. Composer's own generated autoloader PREPENDS itself, which is precisely
// how a sibling app's older `nextcloud/ocp` has shadowed the running one
// before (see the multi-pass OCP preload above, which exists for that). Taking
// only the third-party half, behind this app's own resolution, cannot do that.
$dossiqOpenRegisterLib = realpath(__DIR__ . '/../../openregister/lib');
$dossiqOpenRegisterVendor = realpath(__DIR__ . '/../../openregister/vendor/composer');
if (getenv('DOSSIQ_REAL_FLOW_ENGINE') === '1'
	&& $dossiqOpenRegisterLib !== false
	&& $dossiqOpenRegisterVendor !== false
	&& is_dir($dossiqOpenRegisterLib) === true
) {
	$dossiqEngineDeps = new \Composer\Autoload\ClassLoader();
	$dossiqNotNextcloud = static function (string $name): bool {
		foreach (['OCP\\', 'NCU\\', 'OCA\\', 'OC\\'] as $reserved) {
			if (strncmp($name, $reserved, strlen($reserved)) === 0) {
				return false;
			}
		}

		return true;
	};

	$dossiqEnginePsr4 = @include $dossiqOpenRegisterVendor . '/autoload_psr4.php';
	if (is_array($dossiqEnginePsr4) === true) {
		foreach ($dossiqEnginePsr4 as $dossiqPrefix => $dossiqPaths) {
			if ($dossiqNotNextcloud($dossiqPrefix) === true) {
				$dossiqEngineDeps->addPsr4($dossiqPrefix, $dossiqPaths);
			}
		}
	}

	$dossiqEngineMap = @include $dossiqOpenRegisterVendor . '/autoload_classmap.php';
	if (is_array($dossiqEngineMap) === true) {
		$dossiqEngineDeps->addClassMap(array_filter($dossiqEngineMap, $dossiqNotNextcloud, ARRAY_FILTER_USE_KEY));
	}

	$dossiqEngineDeps->register(false);

	// The app's own source goes IN FRONT of everything, including the stubs.
	$dossiqEngineLoader = new \Composer\Autoload\ClassLoader();
	$dossiqEngineLoader->addPsr4('OCA\\OpenRegister\\', $dossiqOpenRegisterLib . '/');
	$dossiqEngineLoader->register(true);

	unset($dossiqEngineDeps, $dossiqEngineLoader, $dossiqEnginePsr4, $dossiqEngineMap, $dossiqNotNextcloud, $dossiqPrefix, $dossiqPaths);
}

unset($dossiqOpenRegisterLib, $dossiqOpenRegisterVendor);

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466,
// ai-chat-companion-orchestrator) is absent. DossiqToolProvider implements
// OCA\OpenRegister\Mcp\IMcpToolProvider; the stub no-ops when the real
// interface is present (e.g. when the openregister app is installed). Must be
// in place before \OC_App::loadApp('dossiq') below tries to load that class.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	include_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

// Decision-event stubs — loaded when the decision app is absent so the dossiq
// delegation services + DecisionConcludedListener can be unit-tested against its
// event contract. These stubs no-op when the real classes are present.
//
// BOTH NAMESPACES are stubbed. The app renamed OCA\Decidesk -> OCA\Decidiq with
// no alias, and the production code now resolves whichever exists. Stubbing only
// the old one left the new spelling unknown to static analysis, which then
// proved the class_exists() call always false — reporting the resilient lookup
// as dead code. Both must be resolvable for the resolution to analyse as real.
foreach (['Decidiq', 'Decidesk'] as $stubNamespace) {
	foreach (['DecisionRequestedEvent', 'DecisionConcludedEvent', 'GovernanceBodyRequestedEvent', 'ApprovalRouteRequestedEvent'] as $stubEvent) {
		if (class_exists('\\OCA\\' . $stubNamespace . '\\Event\\' . $stubEvent) === false) {
			include_once __DIR__ . '/Stubs/' . $stubNamespace . '/Event/' . $stubEvent . '.php';
		}
	}
}

// The READ half of the same contract (decidiq#1118), which
// ContractDecisionDelegationService::readDecisionState() dispatches so a
// waiting flow node can ask what became of a decision it raised.
//
// NOT in the loop above, because it has exactly ONE spelling. It was added
// after the OCA\Decidesk -> OCA\Decidiq rename, so `OCA\Decidesk\Event\
// DecisionStateRequestedEvent` has never existed and stubbing it would teach
// static analysis that a class nobody ships is resolvable.
if (class_exists('\\OCA\\Decidiq\\Event\\DecisionStateRequestedEvent') === false) {
	include_once __DIR__ . '/Stubs/Decidiq/Event/DecisionStateRequestedEvent.php';
}

// Integriq's ADR-041 delivery-seam contract (absorb-dossiq-deliveries).
// PublicationService dispatches DeliveryRequestedEvent and
// DeliveryConcludedListener consumes DeliveryConcludedEvent; both resolve the
// classes by name so dossiq stays installable without integriq. The stubs
// mirror integriq's real constructor signatures verbatim and no-op when the
// real classes are present.
foreach (['DeliveryRequestedEvent', 'DeliveryConcludedEvent'] as $stubEvent) {
	if (class_exists('\\OCA\\Integriq\\Event\\' . $stubEvent) === false) {
		include_once __DIR__ . '/Stubs/Integriq/Event/' . $stubEvent . '.php';
	}
}

// Hermiq's oversight contract. procest resolves it by name so it stays
// installable without hermiq, which means the contract is only exercised in
// tests if something supplies the class.
if (class_exists('\\OCA\\Hermiq\\Event\\AiOversightRecordedEvent') === false) {
	include_once __DIR__ . '/Stubs/Hermiq/Event/AiOversightRecordedEvent.php';
}

// OpenRegister's flow-node contract. procest's six action nodes implement it,
// so without the stub they cannot even be loaded in a unit test on an instance
// where OpenRegister is absent.
if (interface_exists('\\OCA\\OpenRegister\\Service\\Flow\\IFlowNode') === false) {
	include_once __DIR__ . '/Stubs/Flow/IFlowNode.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\RegisterFlowNodesEvent') === false) {
	include_once __DIR__ . '/Stubs/Flow/RegisterFlowNodesEvent.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry') === false) {
	include_once __DIR__ . '/Stubs/Flow/FlowNodeRegistry.php';
}

// The flow-run surface the human-step listener reads: the run itself, the
// mapper that finds it, the service that resumes it, and the assignee rule.
//
// ⚠️ The FlowRunAssignee stub PERMITS EVERYTHING by design. The authorization
// rule belongs to OpenRegister and is tested there; a copy of it here would be
// a second implementation validated against itself. dossiq's tests inject their
// own double and assert the listener obeys its answer.
if (class_exists('\\OCA\\OpenRegister\\Db\\FlowRun') === false) {
	include_once __DIR__ . '/Stubs/Db/FlowRun.php';
}

if (class_exists('\\OCA\\OpenRegister\\Db\\FlowRunMapper') === false) {
	include_once __DIR__ . '/Stubs/Db/FlowRunMapper.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunService') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowRunService.php';
}

if (class_exists('\\OCA\\OpenRegister\\Exception\\FlowSignalRefused') === false) {
	include_once __DIR__ . '/Stubs/Exception/FlowSignalRefused.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunSignalService') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowRunSignalService.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunAssignee') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowRunAssignee.php';
}

// The suspend/resume vocabulary the two waiting nodes use. FlowSuspension must
// extend RuntimeException here as it does in the real app: a node suspends by
// THROWING it, so a stub that did not would make `@throws` tags analyse as
// not-a-Throwable and any catch in a test meaningless.
if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowSuspension') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowSuspension.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowNodeResumeState') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowNodeResumeState.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowRunContext') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowRunContext.php';
}

if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowResumeState') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowResumeState.php';
}

// The engine's value templating, which DossiqAskPersonNode uses to render a
// declared `{{ case.assignee }}` against the case before stamping a task.
if (class_exists('\\OCA\\OpenRegister\\Service\\Flow\\FlowValueTemplate') === false) {
	include_once __DIR__ . '/Stubs/Service/Flow/FlowValueTemplate.php';
}

// bag-location-save-validation: pre-persist OpenRegister event stubs —
// loaded when the openregister runtime is absent so
// LocationBagValidationListenerTest can exercise handle() against real
// stopPropagation()/setErrors() semantics. Self-skip when openregister is
// installed (real classes present).
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectCreatingEvent') === false) {
	include_once __DIR__ . '/Stubs/Event/ObjectCreatingEventStub.php';
}

if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectUpdatingEvent') === false) {
	include_once __DIR__ . '/Stubs/Event/ObjectUpdatingEventStub.php';
}

// bezwaar-decision: the post-persist counterpart, so
// BezwaarDecisionListenerTest can exercise the guard's real decision through
// handle() — including the probe's call shape, which is what silently broke.
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectUpdatedEvent') === false) {
	include_once __DIR__ . '/Stubs/Event/ObjectUpdatedEventStub.php';
	include_once __DIR__ . '/Stubs/Event/ObjectCreatedEventStub.php';
}

// REQ-SUB-007 bewijsstuk immutability: the pre-persist delete counterpart, so
// BewijsstukImmutabilityListenerTest can exercise the reject path on delete.
if (class_exists('\\OCA\\OpenRegister\\Event\\ObjectDeletingEvent') === false) {
	include_once __DIR__ . '/Stubs/Event/ObjectDeletingEventStub.php';
}

// termijnbewaking-op-engine-timers: the business-timer surface the termijn
// mapping arms and the fired-listener consumes. The event stub mirrors the
// REAL engine constructor signature — a stub that agrees with the caller
// cannot fail — and needs the FlowTimer stub declared first.
if (class_exists('\\OCA\\OpenRegister\\Db\\FlowTimer') === false) {
	include_once __DIR__ . '/Stubs/Db/FlowTimer.php';
}

if (class_exists('\\OCA\\OpenRegister\\Event\\FlowTimerFiredEvent') === false) {
	include_once __DIR__ . '/Stubs/Event/FlowTimerFiredEventStub.php';
}

// OpenRegister AppHost stubs (ADR-040) — loaded when the openregister runtime
// is absent so Application::register() (Bootstrap::register) and dossiq's
// DashboardController (extends GenericDashboardController) resolve in bare CI
// containers + standalone static analysis. The stubs self-skip when the real
// classes are present (openregister installed).
if (class_exists('\\OCA\\OpenRegister\\AppHost\\Bootstrap') === false) {
	include_once __DIR__ . '/Stubs/AppHost/Bootstrap.php';
}

if (class_exists('\\OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController') === false) {
	include_once __DIR__ . '/Stubs/AppHost/Controller/GenericDashboardController.php';
}

// Store plane (ADR-080): OpenRegister owns discovery, dossiq owns install.
// StoreController injects both types, so both have to resolve when the
// openregister runtime is absent. The stubs answer "not_configured" and
// nothing else — a stub that invented cards would let StoreControllerTest
// pass against behaviour no engine actually provides.
if (class_exists('\\OCA\\OpenRegister\\AppHost\\Service\\StoreDescriptor') === false) {
	include_once __DIR__ . '/Stubs/AppHost/Service/StoreDescriptor.php';
}

if (class_exists('\\OCA\\OpenRegister\\AppHost\\Service\\GenericStoreService') === false) {
	include_once __DIR__ . '/Stubs/AppHost/Service/GenericStoreService.php';
}

if (defined('OC_CONSOLE') === false && class_exists('\OC_App') === true) {
	\OC_App::loadApps();
	\OC_App::loadApp('dossiq');
	OC_Hook::clear();
}
