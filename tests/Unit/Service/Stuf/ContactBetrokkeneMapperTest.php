<?php

/**
 * Unit tests for ContactBetrokkeneMapper.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-23-procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\ContactBetrokkeneMapper;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Tests\Unit\Fixtures\SchemaAwareStufRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactBetrokkeneMapper.
 */
class ContactBetrokkeneMapperTest extends TestCase {
	private ContactBetrokkeneMapper $mapper;

	private SchemaAwareStufRegister $register;

	/**
	 * Set up.
	 *
	 * WHY THIS IS NOT A createMock(). The mock this test used to carry echoed
	 * the payload straight back, so every assertion below read the value the
	 * mapper had just written and none of them could ever fail. That is how
	 * `$saved['bronEntiteit']` stayed green for three weeks while live dropped
	 * the property on every save: the schema declares `sourceEntity`, and
	 * OpenRegister gives an undeclared property no column at all.
	 *
	 * {@see SchemaAwareStufRegister} reads the SHIPPED schema fragment and
	 * reproduces both live behaviours — the drop on save, and the `1 = 0` clamp
	 * on a filter it cannot resolve — so this file now reds against the code it
	 * used to pass on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new SchemaAwareStufRegister();
		$this->mapper = new ContactBetrokkeneMapper(
			$this->register,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The mappings this test's fake has stored.
	 *
	 * @return array<int, array<string, mixed>> The stored mapping rows.
	 */
	private function storedMappings(): array {
		return array_values(($this->register->store[StufRegisterAccess::SCHEMA_MAPPING] ?? []));
	}//end storedMappings()

	/**
	 * @return void
	 */
	public function testBsnExtractionFromFlatField(): void {
		$this->assertSame('123456789', $this->mapper->bsnFromContact(['bsn' => '123456789']));
		$this->assertSame('987654321', $this->mapper->bsnFromContact(['identifiers' => ['bsn' => '987654321']]));
		$this->assertNull($this->mapper->bsnFromContact(['email' => 'x@example.com']));
	}//end testBsnExtractionFromFlatField()

	/**
	 * @return void
	 */
	public function testFindOrCreateReusesExistingMapping(): void {
		$this->register->seed(
			StufRegisterAccess::SCHEMA_MAPPING,
			[
				'id' => 'm-1',
				'sourceEntity' => 'contact',
				'sourceId' => 'c-1',
				'endpointId' => 'ep-1',
				'externalIdentification' => 'NPS-999',
			]
		);
		$bsn = $this->mapper->findOrCreateBetrokkene(
			contact: ['id' => 'c-1', 'bsn' => '111111111'],
			endpoint: ['id' => 'ep-1'],
			lookupCallable: fn () => 'should-not-be-called'
		);
		$this->assertSame('NPS-999', $bsn);
	}//end testFindOrCreateReusesExistingMapping()

	/**
	 * @return void
	 */
	public function testFindOrCreateUsesLookupResult(): void {
		$bsn = $this->mapper->findOrCreateBetrokkene(
			contact: ['id' => 'c-2', 'bsn' => '222222222'],
			endpoint: ['id' => 'ep-2'],
			lookupCallable: fn (string $b, array $ep) => 'NPS-FROM-LOOKUP'
		);
		$this->assertSame('NPS-FROM-LOOKUP', $bsn);
		$stored = $this->storedMappings();
		$this->assertNotEmpty($stored);
		$saved = end($stored);
		$this->assertSame('NPS-FROM-LOOKUP', $saved['externalIdentification']);
		// The identity keys, read back out of the store rather than out of the
		// payload: these are the two the schema must declare for the mapping to
		// be findable again.
		$this->assertSame('contact', ($saved['sourceEntity'] ?? null));
		$this->assertSame('c-2', ($saved['sourceId'] ?? null));
	}//end testFindOrCreateUsesLookupResult()

	/**
	 * @return void
	 */
	public function testFindOrCreateFallbackOnLookupMissReusesBsn(): void {
		$bsn = $this->mapper->findOrCreateBetrokkene(
			contact: ['id' => 'c-3', 'bsn' => '333333333'],
			endpoint: ['id' => 'ep-3'],
			lookupCallable: fn () => null
		);
		$this->assertSame('333333333', $bsn);
		$stored = $this->storedMappings();
		$saved = end($stored);
		$this->assertSame('333333333', $saved['externalIdentification']);
	}//end testFindOrCreateFallbackOnLookupMissReusesBsn()

	/**
	 * @return void
	 */
	public function testFindOrCreateReturnsEmptyWhenNoBsn(): void {
		$bsn = $this->mapper->findOrCreateBetrokkene(
			contact: ['id' => 'c-4'],
			endpoint: ['id' => 'ep-4'],
			lookupCallable: fn () => 'never'
		);
		$this->assertSame('', $bsn);
	}//end testFindOrCreateReturnsEmptyWhenNoBsn()
}//end class
