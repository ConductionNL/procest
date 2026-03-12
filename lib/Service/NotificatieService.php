<?php

/**
 * Procest Notificatie Service
 *
 * Handles ZGW notification (NRC) publishing — finds matching subscriptions
 * and delivers notifications via HTTP POST to registered callback URLs.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing ZGW notifications to subscribers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class NotificatieService
{
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
     * @param LoggerInterface   $logger            The logger
     *
     * @return void
     */
    public function __construct(
        private readonly ZgwMappingService $zgwMappingService,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadOpenRegisterServices();
    }

    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $container           = \OC::$server;
            $this->objectService = $container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'NotificatieService: OpenRegister not available',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Publish a notification for a ZGW resource change.
     *
     * Finds all subscriptions matching the kanaal and delivers the
     * notification payload via HTTP POST to each callback URL.
     *
     * @param string $kanaal      The channel name (e.g. 'zaken', 'documenten')
     * @param string $hoofdObject The main object URL
     * @param string $resource    The resource name (e.g. 'zaak', 'status')
     * @param string $resourceUrl The resource URL
     * @param string $actie       The action ('create', 'update', 'destroy')
     * @param array  $kenmerken   Optional filter attributes for matching
     *
     * @return void
     */
    public function publish(
        string $kanaal,
        string $hoofdObject,
        string $resource,
        string $resourceUrl,
        string $actie,
        array $kenmerken = []
    ): void {
        $notification = [
            'kanaal'       => $kanaal,
            'hoofdObject'  => $hoofdObject,
            'resource'     => $resource,
            'resourceUrl'  => $resourceUrl,
            'actie'        => $actie,
            'aanmaakdatum' => (new DateTime())->format('c'),
            'kenmerken'    => $kenmerken,
        ];

        try {
            $this->deliver(notification: $notification);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to deliver notification',
                [
                    'kanaal'    => $kanaal,
                    'actie'     => $actie,
                    'exception' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Find matching subscriptions and deliver the notification.
     *
     * @param array $notification The notification payload
     *
     * @return void
     */
    private function deliver(array $notification): void
    {
        if ($this->objectService === null) {
            return;
        }

        $abonnementMapping = $this->zgwMappingService->getMapping(
            resourceKey: 'abonnement'
        );
        if ($abonnementMapping === null) {
            return;
        }

        $query  = $this->objectService->buildSearchQuery(
            requestParams: [],
            register: $abonnementMapping['sourceRegister'],
            schema: $abonnementMapping['sourceSchema']
        );
        $result = $this->objectService->searchObjectsPaginated(query: $query);

        $subscriptions = $result['results'] ?? [];
        $client        = new Client(['timeout' => 10]);

        foreach ($subscriptions as $subscription) {
            if (is_array($subscription) === true) {
                $subData = $subscription;
            } else {
                $subData = $subscription->jsonSerialize();
            }

            $this->deliverToSubscription(
                client: $client,
                subscription: $subData,
                notification: $notification
            );
        }
    }

    /**
     * Deliver notification to a single subscription if it matches.
     *
     * @param Client $client       The HTTP client
     * @param array  $subscription The subscription data
     * @param array  $notification The notification payload
     *
     * @return void
     */
    private function deliverToSubscription(
        Client $client,
        array $subscription,
        array $notification
    ): void {
        $kanalen = $subscription['kanalen'] ?? [];

        // Check if this subscription listens to the notification channel.
        $matches = false;
        foreach ($kanalen as $kanaalConfig) {
            if (($kanaalConfig['naam'] ?? '') === $notification['kanaal']) {
                $matches = true;
                break;
            }
        }

        if ($matches === false) {
            return;
        }

        $callbackUrl = $subscription['callbackUrl'] ?? '';
        $auth        = $subscription['auth'] ?? '';

        if ($callbackUrl === '') {
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
                        'json'    => $notification,
                        'headers' => $headers,
                    ]
            );

            $this->logger->info(
                'Notification delivered',
                [
                    'kanaal'      => $notification['kanaal'],
                    'callbackUrl' => $callbackUrl,
                ]
            );
        } catch (GuzzleException $e) {
            $this->logger->warning(
                'Notification delivery failed',
                [
                    'callbackUrl' => $callbackUrl,
                    'exception'   => $e->getMessage(),
                ]
            );
        }
    }
}
