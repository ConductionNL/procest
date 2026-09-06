<?php

/**
 * Dossiq Notificatie Service
 *
 * Handles ZGW notification (NRC) publishing — finds matching subscriptions
 * and delivers notifications via HTTP POST to registered callback URLs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Dossiq\Support\SuppressesWarnings;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing ZGW notifications to subscribers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class NotificatieService {

	use SuppressesWarnings;

	/**
	 * RFC1918 + loopback + link-local CIDR blocks to deny (SSRF protection).
	 *
	 * @var string[]
	 */
	private const BLOCKED_CIDRS = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'::1/128',
		'fc00::/7',
	];

	/**
	 * The OpenRegister ObjectService (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param ZgwMappingService $zgwMappingService The ZGW mapping service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwMappingService $zgwMappingService,
		private readonly LoggerInterface $logger,
	) {
		$this->loadOpenRegisterServices();
	}//end __construct()

	/**
	 * Load OpenRegister services dynamically.
	 *
	 * @return void
	 */
	private function loadOpenRegisterServices(): void {
		try {
			$container = \OC::$server;
			$this->objectService = $container->get(
				'OCA\OpenRegister\Service\ObjectService'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'NotificatieService: OpenRegister not available',
				['exception' => $e->getMessage()]
			);
		}
	}//end loadOpenRegisterServices()

	/**
	 * Publish a notification for a ZGW resource change.
	 *
	 * Finds all subscriptions matching the kanaal and delivers the
	 * notification payload via HTTP POST to each callback URL.
	 *
	 * @param string $channel The channel name (e.g. 'zaken', 'documenten')
	 * @param string $hoofdObject The main object URL
	 * @param string $resource The resource name (e.g. 'case', 'status')
	 * @param string $resourceUrl The resource URL
	 * @param string $action The action ('create', 'update', 'destroy')
	 * @param array $characteristics Optional filter attributes for matching
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publish(
		string $channel,
		string $hoofdObject,
		string $resource,
		string $resourceUrl,
		string $action,
		array $characteristics = [],
	): void {
		$notification = [
			'notificationChannel' => $channel,
			'hoofdObject' => $hoofdObject,
			'resource' => $resource,
			'resourceUrl' => $resourceUrl,
			'action' => $action,
			'aanmaakdatum' => (new DateTime())->format('c'),
			'kenmerken' => $characteristics,
		];

		try {
			$this->deliver(notification: $notification);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to deliver notification',
				[
					'notificationChannel' => $channel,
					'action' => $action,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end publish()

	/**
	 * Find matching subscriptions and deliver the notification.
	 *
	 * @param array $notification The notification payload
	 *
	 * @return void
	 */
	private function deliver(array $notification): void {
		if ($this->objectService === null) {
			return;
		}

		$abonnementMapping = $this->zgwMappingService->getMapping(
			resourceKey: 'abonnement'
		);
		if ($abonnementMapping === null) {
			return;
		}

		$query = $this->objectService->buildSearchQuery(
			requestParams: [],
			register: $abonnementMapping['sourceRegister'],
			schema: $abonnementMapping['sourceSchema']
		);
		$result = $this->objectService->searchObjectsPaginated(query: $query);

		$subscriptions = $result['results'] ?? [];
		$client = new Client(['timeout' => 10]);

		foreach ($subscriptions as $subscription) {
			$subData = $subscription;
			if (is_array($subscription) === false) {
				$subData = $subscription->jsonSerialize();
			}

			$this->deliverToSubscription(
				client: $client,
				subscription: $subData,
				notification: $notification
			);
		}
	}//end deliver()

	/**
	 * Deliver notification to a single subscription if it matches.
	 *
	 * @param Client $client The HTTP client
	 * @param array $subscription The subscription data
	 * @param array $notification The notification payload
	 *
	 * @return void
	 */
	private function deliverToSubscription(
		Client $client,
		array $subscription,
		array $notification,
	): void {
		$kanalen = $subscription['kanalen'] ?? [];

		// Check if this subscription listens to the notification channel.
		$matches = false;
		foreach ($kanalen as $channelConfig) {
			if (($channelConfig['name'] ?? '') === $notification['notificationChannel']) {
				$matches = true;
				break;
			}
		}

		if ($matches === false) {
			return;
		}

		$callbackUrl = $subscription['callbackUrl'] ?? '';
		$auth = $subscription['auth'] ?? '';

		if ($callbackUrl === '') {
			return;
		}

		// SSRF guard: validate callback URL before making outbound request.
		if ($this->isSafeCallbackUrl(url: $callbackUrl) === false) {
			$this->logger->warning(
				'Notification delivery blocked: callback URL failed SSRF check',
				['callbackUrl' => substr($callbackUrl, 0, 100)]
			);
			return;
		}

		try {
			$headers = ['Content-Type' => 'application/json'];
			if ($auth !== '') {
				$headers['Authorization'] = $auth;
			}

			$client->post(
				$callbackUrl,
				[
					'json' => $notification,
					'headers' => $headers,
				]
			);

			$this->logger->info(
				'Notification delivered',
				[
					'notificationChannel' => $notification['notificationChannel'],
					'callbackUrl' => $callbackUrl,
				]
			);
		} catch (GuzzleException $e) {
			$this->logger->warning(
				'Notification delivery failed',
				[
					'callbackUrl' => $callbackUrl,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end deliverToSubscription()

	/**
	 * Validate that a callback URL is safe to POST to (SSRF guard).
	 *
	 * Requires https scheme and verifies that the hostname resolves only to
	 * public IP addresses — blocks RFC1918, loopback, link-local, and cloud
	 * metadata endpoints (169.254.169.254).
	 *
	 * @param string $url The callback URL to validate
	 *
	 * @return bool True if the URL is safe for outbound delivery
	 */
	private function isSafeCallbackUrl(string $url): bool {
		$parsed = parse_url($url);
		$scheme = strtolower($parsed['scheme'] ?? '');

		// Only allow https for subscriber callbacks.
		if ($scheme !== 'https') {
			return false;
		}

		$host = $parsed['host'] ?? '';
		if ($host === '') {
			return false;
		}

		// DNS pin: resolve all A/AAAA records and block private ranges.
		$records = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return dns_get_record($host, (DNS_A | DNS_AAAA));
			}
		);
		if ($records === false || count($records) === 0) {
			$this->logger->warning(
				'NRC callback SSRF: DNS resolution returned no records',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return false;
		}

		foreach ($records as $record) {
			$ipAddress = $record['ip'] ?? ($record['ipv6'] ?? null);
			if ($ipAddress === null) {
				continue;
			}

			foreach (self::BLOCKED_CIDRS as $cidr) {
				if ($this->ipInCidr(ipAddress: $ipAddress, cidr: $cidr) === true) {
					$this->logger->warning(
						'NRC callback SSRF: host resolves to private/loopback address',
						['host' => $host, 'ip' => $ipAddress, 'cidr' => $cidr]
					);
					return false;
				}//end if
			}
		}

		return true;
	}//end isSafeCallbackUrl()

	/**
	 * Check if an IP address falls within a CIDR range (IPv4 and IPv6).
	 *
	 * @param string $ipAddress The IP address to test
	 * @param string $cidr The CIDR block (e.g. '10.0.0.0/8')
	 *
	 * @return bool True if the IP is within the range
	 */
	private function ipInCidr(string $ipAddress, string $cidr): bool {
		$isIpv6Cidr = str_contains($cidr, ':');
		$isIpv6Ip = str_contains($ipAddress, ':');

		if ($isIpv6Cidr === true && $isIpv6Ip === true) {
			return $this->ipv6InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		if ($isIpv6Cidr === false && $isIpv6Ip === false) {
			return $this->ipv4InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		// Address family mismatch: an IPv4 address never falls inside an IPv6
		// block and vice versa, so the CIDR simply does not apply.
		return false;
	}//end ipInCidr()

	/**
	 * Check if an IPv6 address falls within an IPv6 CIDR range.
	 *
	 * Compares the packed 16-byte representations byte by byte for the whole
	 * bytes of the prefix, then masks the single partial byte (if any).
	 *
	 * @param string $ipAddress The IPv6 address to test
	 * @param string $cidr The IPv6 CIDR block (e.g. 'fc00::/7')
	 *
	 * @return bool True if the address is within the range
	 */
	private function ipv6InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkBin = inet_pton($network);
		$inputBin = inet_pton($ipAddress);
		if ($networkBin === false || $inputBin === false) {
			return false;
		}//end if

		$fullBytes = intdiv($prefixLen, 8);
		$remainBits = $prefixLen % 8;
		for ($i = 0; $i < $fullBytes; $i++) {
			if ($networkBin[$i] !== $inputBin[$i]) {
				return false;
			}//end if
		}

		if ($remainBits > 0 && $fullBytes < 16) {
			$mask = (0xFF << (8 - $remainBits)) & 0xFF;
			if ((ord($networkBin[$fullBytes]) & $mask) !== (ord($inputBin[$fullBytes]) & $mask)) {
				return false;
			}//end if
		}//end if

		return true;
	}//end ipv6InCidr()

	/**
	 * Check if an IPv4 address falls within an IPv4 CIDR range.
	 *
	 * @param string $ipAddress The IPv4 address to test
	 * @param string $cidr The IPv4 CIDR block (e.g. '10.0.0.0/8')
	 *
	 * @return bool True if the address is within the range
	 */
	private function ipv4InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkLong = ip2long($network);
		$ipLong = ip2long($ipAddress);
		if ($networkLong === false || $ipLong === false) {
			return false;
		}//end if

		$mask = 0;
		if ($prefixLen > 0) {
			$mask = ~0 << (32 - $prefixLen);
		}//end if

		return ($ipLong & $mask) === ($networkLong & $mask);
	}//end ipv4InCidr()
}//end class
