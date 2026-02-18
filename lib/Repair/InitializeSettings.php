<?php

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class InitializeSettings implements IRepairStep
{
    private const REGISTER_NAME = 'case-management';

    private const SCHEMAS = [
        'case' => [
            'title' => 'Case',
            'description' => 'A case in the case management system',
            'properties' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'default' => 'open'],
                'assignee' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'default' => 'normal'],
                'created' => ['type' => 'string', 'format' => 'date-time'],
                'updated' => ['type' => 'string', 'format' => 'date-time'],
                'closed' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
        'task' => [
            'title' => 'Task',
            'description' => 'A task within a case',
            'properties' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'default' => 'todo'],
                'assignee' => ['type' => 'string'],
                'case' => ['type' => 'string'],
                'dueDate' => ['type' => 'string', 'format' => 'date-time'],
                'priority' => ['type' => 'string', 'default' => 'normal'],
            ],
        ],
        'status' => [
            'title' => 'Status',
            'description' => 'Status definition for case workflow',
            'properties' => [
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'order' => ['type' => 'integer', 'default' => 0],
                'isFinal' => ['type' => 'boolean', 'default' => false],
            ],
        ],
        'role' => [
            'title' => 'Role',
            'description' => 'Role definition for case participants',
            'properties' => [
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'permissions' => ['type' => 'array'],
            ],
        ],
        'result' => [
            'title' => 'Result',
            'description' => 'Case outcome or result',
            'properties' => [
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'case' => ['type' => 'string'],
            ],
        ],
        'decision' => [
            'title' => 'Decision',
            'description' => 'Decision made on a case',
            'properties' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'case' => ['type' => 'string'],
                'decidedBy' => ['type' => 'string'],
                'decidedAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Initialize Procest case-management register and schemas';
    }

    public function run(IOutput $output): void
    {
        $output->info('Initializing Procest settings...');

        if (!$this->appManager->isEnabledForUser('openregister')) {
            $output->warning('OpenRegister is not installed or enabled. Skipping auto-configuration.');
            $this->logger->warning('Procest: OpenRegister not available, skipping register initialization');
            return;
        }

        try {
            $this->initializeRegisterAndSchemas($output);
        } catch (\Exception $e) {
            $output->warning('Could not auto-configure: ' . $e->getMessage());
            $this->logger->error('Procest initialization failed', ['exception' => $e->getMessage()]);
        }
    }

    private function initializeRegisterAndSchemas(IOutput $output): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerService = $this->container->get('OCA\OpenRegister\Service\RegisterService');
        } catch (\Exception $e) {
            $output->warning('Could not access OpenRegister services: ' . $e->getMessage());
            return;
        }

        // Look for existing register
        $registers = $registerService->findAll();
        $register = null;
        foreach ($registers as $reg) {
            if ($reg->getTitle() === self::REGISTER_NAME || $reg->getSlug() === self::REGISTER_NAME) {
                $register = $reg;
                break;
            }
        }

        if ($register === null) {
            // Create the register
            $register = $registerService->createFromArray([
                'title' => self::REGISTER_NAME,
                'slug' => self::REGISTER_NAME,
                'description' => 'Case management register for Procest',
            ]);
            $output->info('Created case-management register with ID ' . $register->getId());
        } else {
            $output->info('Found existing case-management register with ID ' . $register->getId());
        }

        $this->appConfig->setValueString(Application::APP_ID, 'register', (string) $register->getId());

        // Create or find schemas
        $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        $existingSchemas = $schemaMapper->findAll();

        foreach (self::SCHEMAS as $slug => $definition) {
            $configKey = $slug . '_schema';
            $existingId = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');

            if ($existingId !== '') {
                $output->info("Schema '$slug' already configured with ID $existingId");
                continue;
            }

            // Look for existing schema by slug within this register
            $found = null;
            foreach ($existingSchemas as $schema) {
                if ($schema->getSlug() === $slug && $schema->getRegister() === $register->getId()) {
                    $found = $schema;
                    break;
                }
            }

            if ($found === null) {
                $found = $schemaMapper->createFromArray([
                    'title' => $definition['title'],
                    'slug' => $slug,
                    'description' => $definition['description'],
                    'register' => $register->getId(),
                    'properties' => json_encode($definition['properties']),
                ]);
                $output->info("Created schema '$slug' with ID " . $found->getId());
            } else {
                $output->info("Found existing schema '$slug' with ID " . $found->getId());
            }

            $this->appConfig->setValueString(Application::APP_ID, $configKey, (string) $found->getId());
        }

        $this->logger->info('Procest: Register and schemas initialized successfully');
    }
}
