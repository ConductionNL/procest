<?php

/**
 * Test stub for OpenRegister's Organisation entity.
 *
 * Minimal surface needed by dossiq unit tests: the tenant migration builds an
 * Organisation from a legacy tenant row. Only the accessors the dossiq code
 * touches are stubbed.
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
 * Stub of OpenRegister's Organisation for unit tests.
 */
class Organisation {
	/** @var string|null */
	private ?string $uuid = null;

	/** @var string|null */
	private ?string $slug = null;

	/** @var string|null */
	private ?string $name = null;

	/** @var string|null */
	private ?string $status = 'active';

	/** @var array<int, string> */
	private array $groups = [];

	/** @var bool */
	private bool $active = true;

	/** @var int|null */
	private ?int $storageQuota = null;

	/**
	 * 🔴 THE STUB HAS TO MIRROR THE REAL CLASS, FIELD FOR FIELD.
	 *
	 * The real Organisation extends Entity, whose `__call` synthesises a
	 * setter for every declared property. This stub has no parent and no
	 * `__call`, so it answers ONLY to what it declares. A field the real class
	 * carries and the stub does not is one that fatals here while working in
	 * production, and one no test can reach.
	 *
	 * These seven were added for the partner migration, each verified present
	 * on OCA\OpenRegister\Db\Organisation.
	 *
	 * @var string|null
	 */
	private ?string $type = 'organisation';

	/**
	 * @var boolean|null
	 */
	private ?bool $isLocalTenant = true;

	/**
	 * @var string|null
	 */
	private ?string $oin = null;

	/**
	 * @var string|null
	 */
	private ?string $contactEmail = null;

	/**
	 * @var string|null
	 */
	private ?string $defaultPermissionLevel = null;

	/**
	 * @var integer|null
	 */
	private ?int $qualityScore = null;

	/**
	 * @var string|null
	 */
	private ?string $qualityStatus = null;

	// phpcs:disable Squiz.Commenting.FunctionComment.Missing

	public function getUuid(): ?string {
		return $this->uuid;
	}

	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}

	public function getSlug(): ?string {
		return $this->slug;
	}

	public function setSlug(?string $slug): void {
		$this->slug = $slug;
	}

	public function getName(): ?string {
		return $this->name;
	}

	public function setName(?string $name): void {
		$this->name = $name;
	}

	public function getStatus(): ?string {
		return $this->status;
	}

	public function setStatus(?string $status): void {
		$this->status = $status;
	}

	/**
	 * @return array<int, string>
	 */
	public function getGroups(): array {
		return $this->groups;
	}

	/**
	 * @param array<int, string>|null $groups Groups list.
	 */
	public function setGroups(?array $groups): void {
		$this->groups = ($groups ?? []);
	}

	public function getActive(): bool {
		return $this->active;
	}

	public function isActive(): bool {
		return $this->active;
	}

	public function setActive(mixed $active): void {
		$this->active = (bool)$active;
	}

	public function getStorageQuota(): ?int {
		return $this->storageQuota;
	}

	public function setStorageQuota(?int $storageQuota): void {
		$this->storageQuota = $storageQuota;
	}

	/**
	 * @return string|null The organisation type.
	 */
	public function getType(): ?string {
		return $this->type;
	}

	/**
	 * @param string|null $type The organisation type.
	 *
	 * @return void
	 */
	public function setType(?string $type): void {
		$this->type = $type;
	}

	/**
	 * @return boolean|null Whether this is a tenant of this instance.
	 */
	public function getIsLocalTenant(): ?bool {
		return $this->isLocalTenant;
	}

	/**
	 * @param boolean|null $isLocalTenant Whether this is a local tenant.
	 *
	 * @return void
	 */
	public function setIsLocalTenant(?bool $isLocalTenant): void {
		$this->isLocalTenant = $isLocalTenant;
	}

	/**
	 * @return string|null The OIN.
	 */
	public function getOin(): ?string {
		return $this->oin;
	}

	/**
	 * @param string|null $oin The OIN.
	 *
	 * @return void
	 */
	public function setOin(?string $oin): void {
		$this->oin = $oin;
	}

	/**
	 * @return string|null The contact email.
	 */
	public function getContactEmail(): ?string {
		return $this->contactEmail;
	}

	/**
	 * @param string|null $contactEmail The contact email.
	 *
	 * @return void
	 */
	public function setContactEmail(?string $contactEmail): void {
		$this->contactEmail = $contactEmail;
	}

	/**
	 * @return string|null The default permission level.
	 */
	public function getDefaultPermissionLevel(): ?string {
		return $this->defaultPermissionLevel;
	}

	/**
	 * @param string|null $defaultPermissionLevel The default permission level.
	 *
	 * @return void
	 */
	public function setDefaultPermissionLevel(?string $defaultPermissionLevel): void {
		$this->defaultPermissionLevel = $defaultPermissionLevel;
	}

	/**
	 * @return integer|null The quality score.
	 */
	public function getQualityScore(): ?int {
		return $this->qualityScore;
	}

	/**
	 * @param integer|null $qualityScore The quality score.
	 *
	 * @return void
	 */
	public function setQualityScore(?int $qualityScore): void {
		$this->qualityScore = $qualityScore;
	}

	/**
	 * @return string|null The quality status.
	 */
	public function getQualityStatus(): ?string {
		return $this->qualityStatus;
	}

	/**
	 * @param string|null $qualityStatus The quality status.
	 *
	 * @return void
	 */
	public function setQualityStatus(?string $qualityStatus): void {
		$this->qualityStatus = $qualityStatus;
	}

	// phpcs:enable
}//end class
