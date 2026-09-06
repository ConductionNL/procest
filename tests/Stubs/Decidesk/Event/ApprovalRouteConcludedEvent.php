<?php

/**
 * Decidesk ApprovalRouteConcludedEvent test stub.
 *
 * Mirrors the decision app's terminal conclusion event so dossiq's
 * ParaferingConcludedListener can be unit-tested without the app installed,
 * and so static analysis sees the class the listener registers against exists.
 *
 * 🔴 THE GETTER SHAPE IS THE CONTRACT. The listener reads every field through
 * duck-typed getters; keep the names identical to decidiq's, including the
 * enriched payload (subjectSchema, externalReference, actions).
 *
 * @category Tests
 * @package  OCA\Decidesk\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://decidesk.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

use OCP\EventDispatcher\Event;

/**
 * A subject has reached the end of its approval route.
 */
class ApprovalRouteConcludedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $subject The subject that finished travelling.
	 * @param string $sourceApp The producing app id.
	 * @param string $outcome The final outcome.
	 * @param string $actor The final actor.
	 * @param string $correlationId The correlation id.
	 * @param string $subjectSchema The subject's schema slug.
	 * @param string $externalReference The producer's own route reference.
	 * @param array<int,array<string,mixed>> $actions The chronological sign-off record.
	 */
	public function __construct(
		private readonly string $subject,
		private readonly string $sourceApp,
		private readonly string $outcome,
		private readonly string $actor = '',
		private readonly string $correlationId = '',
		private readonly string $subjectSchema = '',
		private readonly string $externalReference = '',
		private readonly array $actions = [],
	) {
		parent::__construct();
	}

	/** @return string The subject. */
	public function getSubject(): string {
		return $this->subject;
	}

	/** @return string The app id. */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}

	/** @return string The outcome. */
	public function getOutcome(): string {
		return $this->outcome;
	}

	/** @return string The actor. */
	public function getActor(): string {
		return $this->actor;
	}

	/** @return string The correlation id. */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}

	/** @return string The subject schema. */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;
	}

	/** @return string The external reference. */
	public function getExternalReference(): string {
		return $this->externalReference;
	}

	/** @return array<int,array<string,mixed>> The actions. */
	public function getActions(): array {
		return $this->actions;
	}
}
