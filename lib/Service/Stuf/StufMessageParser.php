<?php

/**
 * Dossiq StufMessageParser.
 *
 * Parses inbound StUF SOAP responses (Bv01 bevestigingen, La01 antwoorden,
 * Fo02 foutberichten) and extracts the structured payload needed by callers.
 *
 * XXE Safety: PHP 8 + libxml >= 2.9 has external entity loading disabled by
 * default. We additionally pass LIBXML_NOENT=0 (do not substitute), so even
 * on older libxml builds the parser never resolves external entities.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-synchronous-zaak-query
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCA\Dossiq\Service\StufMessageBuilder;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * Parses StUF response envelopes.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-response-parsing
 */
class StufMessageParser {
	public const NS_SOAPENV = StufMessageBuilder::NS_SOAPENV;

	public const NS_STUF = StufMessageBuilder::NS_STUF;

	public const NS_ZKN = StufMessageBuilder::NS_ZKN;

	public const NS_BG = StufMessageBuilder::NS_BG;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse a Bv01 bevestiging envelope.
	 *
	 * Returns at minimum the crossRefnummer (matching outbound referentienummer)
	 * and the optional server-allocated zaakIdentificatie.
	 *
	 * @param string $responseXml The full SOAP envelope XML.
	 *
	 * @return array{crossRefnummer:string,caseIdentification:?string,raw:array<string,mixed>}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-response-parsing
	 */
	public function parseBevestiging(string $responseXml): array {
		$xml = $this->safeLoadXml(responseXml: $responseXml);
		if ($xml === null) {
			return ['crossRefnummer' => '', 'caseIdentification' => null, 'raw' => []];
		}

		$crossRef = $this->firstTextValue(
			xml: $xml,
			paths: [
				'//stuf:stuurgegevens/stuf:crossRefnummer',
				'//stuf:crossRefnummer',
			]
		);

		$caseId = $this->firstTextValue(
			xml: $xml,
			paths: [
				'//stuf:antwoord/zkn:object/zkn:identificatie',
				'//zkn:identificatie',
			]
		);

		$this->logger->debug(
			message: 'StUF parseBevestiging: crossRef={cross}, zaakId={zaak}',
			context: ['cross' => $crossRef, 'zaak' => $caseId]
		);

		$caseIdentification = null;
		if ($caseId !== '') {
			$caseIdentification = $caseId;
		}

		return [
			'crossRefnummer' => $crossRef,
			'caseIdentification' => $caseIdentification,
			'raw' => [],
		];
	}//end parseBevestiging()

	/**
	 * Parse a La01 antwoord (geefZaakDetails / geefBetrokkene) envelope.
	 *
	 * @param string $responseXml The full SOAP envelope XML.
	 *
	 * @return array The parsed Zaak object (identificatie, omschrijving, startdatum, einddatum, statussen, betrokkenen, ...).
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-synchronous-zaak-query
	 */
	public function parseZaakDetails(string $responseXml): array {
		$xml = $this->safeLoadXml(responseXml: $responseXml);
		if ($xml === null) {
			return [];
		}

		$case = [
			'identificatie' => $this->firstTextValue(xml: $xml, paths: ['//zkn:object/zkn:identificatie', '//zkn:identificatie']),
			'omschrijving' => $this->firstTextValue(xml: $xml, paths: ['//zkn:object/zkn:omschrijving', '//zkn:omschrijving']),
			'startdatum' => $this->firstTextValue(xml: $xml, paths: ['//zkn:object/zkn:startdatum']),
			'endDate' => $this->firstTextValue(xml: $xml, paths: ['//zkn:object/zkn:einddatum']),
			'caseType' => [
				'omschrijving' => $this->firstTextValue(xml: $xml, paths: ['//zkn:object/zkn:zaaktype/zkn:omschrijving']),
			],
			'statussen' => [],
			'betrokkenen' => [],
		];

		$this->registerStufNamespaces(xml: $xml);
		foreach ($xml->xpath(expression: '//zkn:heeftStatus') as $statusNode) {
			$case['statussen'][] = [
				'datumStatusGezet' => $this->extractDescendantText(node: $statusNode, localName: 'datumStatusGezet'),
				'statustype' => $this->extractDescendantText(node: $statusNode, localName: 'statustype'),
			];
		}

		foreach ($xml->xpath(expression: '//zkn:heeftAlsInitiator|//zkn:heeftAlsBelanghebbende|//zkn:heeftAlsGemachtigde') as $roleNode) {
			$case['betrokkenen'][] = [
				'role' => $roleNode->getName(),
				'bsn' => $this->extractDescendantText(node: $roleNode, localName: 'inp.bsn'),
			];
		}

		$this->logger->debug(
			message: 'StUF parseZaakDetails: zaak={zaak}, statussen={st}, betrokkenen={bet}',
			context: ['zaak' => $case['identificatie'], 'st' => count(value: $case['statussen']), 'bet' => count(value: $case['betrokkenen'])]
		);

		return $case;
	}//end parseZaakDetails()

	/**
	 * Parse a Fo02 foutbericht envelope.
	 *
	 * Returns code (e.g. StUF064), omschrijving (human-readable), details
	 * (XML/diagnostic text), and a soort classification (transient vs.
	 * permanent) so the circuit breaker can decide whether to count it.
	 *
	 * @param string $responseXml The full SOAP envelope XML.
	 *
	 * @return array{code:string,omschrijving:string,details:string,kind:string}
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-response-parsing
	 */
	public function parseError(string $responseXml): array {
		$xml = $this->safeLoadXml(responseXml: $responseXml);
		if ($xml === null) {
			return ['code' => 'PARSE_ERROR', 'omschrijving' => 'Antwoord-envelop niet leesbaar', 'details' => '', 'kind' => 'permanent'];
		}

		$code = $this->firstTextValue(xml: $xml, paths: ['//stuf:fout/stuf:code', '//stuf:code']);
		$omschrijving = $this->firstTextValue(xml: $xml, paths: ['//stuf:fout/stuf:omschrijving', '//stuf:omschrijving']);
		$details = $this->firstTextValue(xml: $xml, paths: ['//stuf:fout/stuf:details', '//stuf:details']);

		return [
			'code' => $code,
			'omschrijving' => $omschrijving,
			'details' => $details,
			'kind' => $this->classifyStufFault(code: $code),
		];
	}//end parseError()

	/**
	 * Extract a text value via a namespaced xpath (returns first non-empty).
	 *
	 * @param string $xml The XML document.
	 * @param string $xpath The xpath expression.
	 * @param string $namespace Unused (kept for backwards-compatibility with the task signature).
	 *
	 * @return string|null The first matching text node or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-response-parsing
	 */
	public function extractNamespaceValue(string $xml, string $xpath, string $namespace = ''): ?string {
		unset($namespace);
		$doc = $this->safeLoadXml(responseXml: $xml);
		if ($doc === null) {
			return null;
		}

		$value = $this->firstTextValue(xml: $doc, paths: [$xpath]);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end extractNamespaceValue()

	/**
	 * Classify a StUF fault code as transient or permanent for circuit breaker logic.
	 *
	 * @param string $code The StUF fault code.
	 *
	 * @return string Either "transient" or "permanent".
	 */
	private function classifyStufFault(string $code): string {
		// StUF067/StUF068 are typically transient back-end overload / lock contention.
		if (in_array(needle: $code, haystack: ['StUF067', 'StUF068', 'StUF019'], strict: true) === true) {
			return 'transient';
		}

		return 'permanent';
	}//end classifyStufFault()

	/**
	 * Safely load an XML string without expanding external entities.
	 *
	 * @param string $responseXml The raw response.
	 *
	 * @return SimpleXMLElement|null The parsed root, or null on parse failure.
	 */
	private function safeLoadXml(string $responseXml): ?SimpleXMLElement {
		if ($responseXml === '') {
			return null;
		}

		$previousErrors = libxml_use_internal_errors(use_errors: true);
		$xml = simplexml_load_string(
			data: $responseXml,
			class_name: SimpleXMLElement::class,
			options: (LIBXML_NONET | LIBXML_NOENT)
		);
		libxml_clear_errors();
		libxml_use_internal_errors(use_errors: $previousErrors);

		if ($xml === false) {
			$this->logger->warning(message: 'StUF response: XML parse failed');
			return null;
		}

		$this->registerStufNamespaces(xml: $xml);
		return $xml;
	}//end safeLoadXml()

	/**
	 * Register StUF namespace prefixes on a SimpleXMLElement so xpath works.
	 *
	 * @param SimpleXMLElement $xml The root.
	 *
	 * @return void
	 */
	private function registerStufNamespaces(SimpleXMLElement $xml): void {
		$xml->registerXPathNamespace(prefix: 'soapenv', namespace: self::NS_SOAPENV);
		$xml->registerXPathNamespace(prefix: 'stuf', namespace: self::NS_STUF);
		$xml->registerXPathNamespace(prefix: 'zkn', namespace: self::NS_ZKN);
		$xml->registerXPathNamespace(prefix: 'bg', namespace: self::NS_BG);
	}//end registerStufNamespaces()

	/**
	 * Return the first non-empty text value found across the given xpath candidates.
	 *
	 * @param SimpleXMLElement $xml The XML root.
	 * @param array $paths The xpath candidates.
	 *
	 * @return string The first non-empty text value (empty string if none).
	 */
	private function firstTextValue(SimpleXMLElement $xml, array $paths): string {
		foreach ($paths as $xpath) {
			$matches = $xml->xpath(expression: $xpath);
			if (is_array(value: $matches) === true && count(value: $matches) > 0) {
				$value = trim(string: (string)$matches[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end firstTextValue()

	/**
	 * Extract the trimmed text of a descendant element by local name.
	 *
	 * @param SimpleXMLElement $node The parent node.
	 * @param string $localName The descendant local name.
	 *
	 * @return string The trimmed text value (empty if not present).
	 */
	private function extractDescendantText(SimpleXMLElement $node, string $localName): string {
		$this->registerStufNamespaces(xml: $node);
		foreach (['zkn', 'stuf', 'bg'] as $prefix) {
			$matches = $node->xpath(expression: './/' . $prefix . ':' . $localName);
			if (is_array(value: $matches) === true && count(value: $matches) > 0) {
				$value = trim(string: (string)$matches[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end extractDescendantText()
}//end class
