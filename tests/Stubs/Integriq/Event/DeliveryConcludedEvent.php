<?php

/**
 * Integriq DeliveryConcludedEvent test stub.
 *
 * Mirrors integriq's ADR-041 terminal delivery outcome contract verbatim
 * (constructor parameter names AND order) so DeliveryConcludedListener can be
 * unit-tested without the integriq app installed. The real class ships in
 * integriq (`lib/Event/DeliveryConcludedEvent.php`, change
 * absorb-dossiq-deliveries); this stub is loaded by tests/bootstrap.php only
 * when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Terminal outcome of a cross-app delivery request.
 */
class DeliveryConcludedEvent extends Event {
	/**
	 * Terminal status: the delivery succeeded.
	 */
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * Terminal status: the retry budget is spent, no further attempts.
	 */
	public const STATUS_ABANDONED = 'abandoned';

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that raised the original request.
	 * @param string $correlationId The caller's correlation id, echoed verbatim.
	 * @param string $subjectId The subject object id from the original request.
	 * @param string $channel The delivery channel from the original request.
	 * @param string $status Terminal status: {@see self::STATUS_DELIVERED} or {@see self::STATUS_ABANDONED}.
	 * @param string $eventId Uuid of the CloudEvent `event` object.
	 * @param string $messageId Uuid of the `event_message` delivery record.
	 * @param int $attempts How many delivery attempts were made.
	 * @param string|null $error The last delivery error, or null on success.
	 * @param string $concludedAt ISO 8601 timestamp of the terminal transition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $correlationId,
		private readonly string $subjectId,
		private readonly string $channel,
		private readonly string $status,
		private readonly string $eventId,
		private readonly string $messageId,
		private readonly int $attempts,
		private readonly ?string $error,
		private readonly string $concludedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that raised the original request.
	 *
	 * @return string The source app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The caller's correlation id.
	 *
	 * @return string The correlation id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * The subject object id from the original request.
	 *
	 * @return string The subject id.
	 */
	public function getSubjectId(): string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * The delivery channel from the original request.
	 *
	 * @return string The channel.
	 */
	public function getChannel(): string {
		return $this->channel;
	}//end getChannel()

	/**
	 * Terminal status of the delivery.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Uuid of the CloudEvent `event` object.
	 *
	 * @return string The event uuid.
	 */
	public function getEventId(): string {
		return $this->eventId;
	}//end getEventId()

	/**
	 * Uuid of the `event_message` delivery record.
	 *
	 * @return string The message uuid.
	 */
	public function getMessageId(): string {
		return $this->messageId;
	}//end getMessageId()

	/**
	 * How many delivery attempts were made.
	 *
	 * @return int The attempt count.
	 */
	public function getAttempts(): int {
		return $this->attempts;
	}//end getAttempts()

	/**
	 * The last delivery error.
	 *
	 * @return string|null The error, or null on success.
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()

	/**
	 * When the delivery reached its terminal state.
	 *
	 * @return string ISO 8601 timestamp.
	 */
	public function getConcludedAt(): string {
		return $this->concludedAt;
	}//end getConcludedAt()
}//end class
