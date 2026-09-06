<?php

/**
 * Test stub for OpenRegister's VerwerkingsactiviteitMapper.
 *
 * Minimal surface needed by dossiq unit tests: the catalogue seed repair
 * step calls findByCode / insert / update. The stub keeps an in-memory
 * code-indexed store so upsert-by-code and status-preservation semantics
 * are assertable. The real OR mapper persists to
 * oc_openregister_verwerkingsactiviteiten with vocabulary validation.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's VerwerkingsactiviteitMapper for unit tests.
 */
class VerwerkingsactiviteitMapper {
	/**
	 * In-memory store, keyed by activity code.
	 *
	 * @var array<string, Verwerkingsactiviteit>
	 */
	private array $store = [];

	/**
	 * Number of insert() calls (test accessor).
	 *
	 * @var int
	 */
	public int $inserts = 0;

	/**
	 * Number of update() calls (test accessor).
	 *
	 * @var int
	 */
	public int $updates = 0;

	/**
	 * Find by short readable code.
	 *
	 * @param string $code The activity code.
	 *
	 * @return Verwerkingsactiviteit|null Null when no row matches.
	 */
	public function findByCode(string $code): ?Verwerkingsactiviteit {
		return ($this->store[$code] ?? null);
	}//end findByCode()

	/**
	 * Insert, defaulting a blank status to `concept` (mirrors OR).
	 *
	 * @param Verwerkingsactiviteit $entity Entity to insert.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function insert(Verwerkingsactiviteit $entity): Verwerkingsactiviteit {
		if ($entity->getStatus() === null || $entity->getStatus() === '') {
			$entity->setStatus('concept');
		}

		$this->validate(entity: $entity);

		$this->store[(string)$entity->getCode()] = $entity;
		$this->inserts++;
		return $entity;
	}//end insert()

	/**
	 * Update an existing entity.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to update.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function update(Verwerkingsactiviteit $entity): Verwerkingsactiviteit {
		$this->validate(entity: $entity);

		$this->store[(string)$entity->getCode()] = $entity;
		$this->updates++;
		return $entity;
	}//end update()

	/**
	 * Refuse what the real mapper refuses.
	 *
	 * A STUB THAT ACCEPTS EVERYTHING CANNOT FAIL. This one used to store any
	 * entity it was handed, so the seed step's tests could not tell a catalogue
	 * OpenRegister accepts from one it rejects — and the shipped catalogue was
	 * the second kind. The messages are the real mapper's, so a failure here
	 * reads the same as the one an operator sees.
	 *
	 * Mirrors openregister `lib/Db/VerwerkingsactiviteitMapper::validate()`.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to validate.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the entity would be refused by OR.
	 */
	private function validate(Verwerkingsactiviteit $entity): void {
		if ($entity->getName() === null || trim((string)$entity->getName()) === '') {
			throw new \InvalidArgumentException('Verwerkingsactiviteit MUST have a name (AVG Art 30 §1(a))');
		}

		if ($entity->getPurpose() === null || trim((string)$entity->getPurpose()) === '') {
			throw new \InvalidArgumentException('Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))');
		}

		if (in_array($entity->getLegalBasis(), Verwerkingsactiviteit::LEGAL_BASIS_VOCABULARY, true) === false) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid legalBasis "%s"; expected one of: %s (AVG Art 6)',
					(string)$entity->getLegalBasis(),
					implode(', ', Verwerkingsactiviteit::LEGAL_BASIS_VOCABULARY)
				)
			);
		}

		if ($entity->getStatus() !== null
			&& $entity->getStatus() !== ''
			&& in_array($entity->getStatus(), Verwerkingsactiviteit::STATUS_VOCABULARY, true) === false
		) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid status "%s"; expected one of: %s',
					(string)$entity->getStatus(),
					implode(', ', Verwerkingsactiviteit::STATUS_VOCABULARY)
				)
			);
		}
	}//end validate()
}//end class
