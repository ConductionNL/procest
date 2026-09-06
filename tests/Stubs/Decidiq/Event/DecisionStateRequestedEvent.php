<?php

/**
 * Decidiq DecisionStateRequestedEvent test stub.
 *
 * Mirrors decidiq's merged READ contract (decidiq#1118) so dossiq's delegation
 * service and the waiting flow node can be unit-tested without the decidiq app
 * installed. The real class ships in decidiq
 * (`OCA\Decidiq\Event\DecisionStateRequestedEvent`); this stub is loaded by
 * tests/bootstrap.php only when the real class is absent.
 *
 * 🔴 IT MIRRORS THE REAL API RATHER THAN THE CALLER'S ASSUMPTION ABOUT IT. A
 * stub written from the call site agrees with the caller by construction and
 * therefore cannot fail — dossiq#1756 measured 50 such disagreements at 11
 * points of drift, every one invisible to a green run. So the three result
 * slots are three separate booleans here exactly as they are there, and an
 * absent envelope is `null` rather than an empty array.
 *
 * ONE NAMESPACE ONLY. Unlike DecisionRequestedEvent there is no
 * `OCA\Decidesk\...` spelling to stub: the read half was added after the
 * rename, so that class has never existed.
 *
 * @category Tests
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://decidiq.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched by a consuming app to ask decidiq what became of a Decision. The
 * decidiq in-process listener writes the answer back into the result slots.
 */
class DecisionStateRequestedEvent extends Event {

	/**
	 * Whether decidiq's listener answered this request at all.
	 */
	private bool $handled = false;

	/**
	 * Whether the named actor may read this Decision's outcome.
	 */
	private bool $permitted = false;

	/**
	 * Whether a Decision carrying this id exists.
	 */
	private bool $found = false;

	/**
	 * The outcome envelope, when the caller may read it and it exists.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $envelope = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The consumer app asking.
	 * @param string $decisionId The id of the Decision to report on.
	 * @param string $actorId Nextcloud UID the read is authorized AS.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $decisionId,
		private readonly string $actorId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * @return string The consumer app asking for the state.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * @return string The id of the Decision being asked about.
	 */
	public function getDecisionId(): string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * @return string The Nextcloud UID the read is authorized as.
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * @return bool Whether decidiq's listener answered this request.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Mark whether decidiq's listener answered this request.
	 *
	 * @param bool $handled True when decidiq resolved the request to an answer.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * @return bool Whether the named actor may read this Decision's outcome.
	 */
	public function isPermitted(): bool {
		return $this->permitted;
	}//end isPermitted()

	/**
	 * Mark whether the named actor may read this Decision's outcome.
	 *
	 * @param bool $permitted True when the authorization guard allows the read.
	 *
	 * @return void
	 */
	public function setPermitted(bool $permitted): void {
		$this->permitted = $permitted;
	}//end setPermitted()

	/**
	 * @return bool Whether a Decision carrying this id exists.
	 */
	public function isFound(): bool {
		return $this->found;
	}//end isFound()

	/**
	 * Mark whether a Decision carrying this id exists.
	 *
	 * @param bool $found True when the Decision resolved.
	 *
	 * @return void
	 */
	public function setFound(bool $found): void {
		$this->found = $found;
	}//end setFound()

	/**
	 * @return array<string, mixed>|null The outcome envelope, or null.
	 */
	public function getEnvelope(): ?array {
		return $this->envelope;
	}//end getEnvelope()

	/**
	 * Set the outcome envelope (written by decidiq's listener).
	 *
	 * @param array<string, mixed> $envelope The envelope from getOutcomeEnvelope().
	 *
	 * @return void
	 */
	public function setEnvelope(array $envelope): void {
		$this->envelope = $envelope;
	}//end setEnvelope()

	/**
	 * The derived outcome status, when one was reported.
	 *
	 * @return string|null approved / rejected / withdrawn / pending, or null.
	 */
	public function getStatus(): ?string {
		if ($this->envelope === null) {
			return null;
		}

		return (string)($this->envelope['status'] ?? 'pending');
	}//end getStatus()
}//end class
