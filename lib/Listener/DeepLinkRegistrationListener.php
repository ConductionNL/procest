<?php

/**
 * Procest DeepLinkRegistrationListener
 *
 * Registers Procest's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
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

namespace OCA\Procest\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers Procest's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for Procest schemas
 * (cases, tasks, etc.) will link directly to Procest's detail views.
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        // Register case detail deep links.
        $event->register(
            appId: 'procest',
            registerSlug: 'case-management',
            schemaSlug: 'case',
            urlTemplate: '/apps/procest/#/cases/{uuid}'
        );

        // Register task detail deep links.
        $event->register(
            appId: 'procest',
            registerSlug: 'case-management',
            schemaSlug: 'task',
            urlTemplate: '/apps/procest/#/tasks/{uuid}'
        );
    }
}
