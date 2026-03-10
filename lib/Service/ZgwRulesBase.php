<?php

/**
 * Procest ZGW Rules Base
 *
 * Shared utilities for ZGW business rule validation services.
 * Each register has its own rules service (ZgwZrcRulesService, etc.)
 * that extends this base.
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

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Base class for ZGW register-specific business rule services.
 *
 * Provides shared utilities: UUID extraction, URL validation,
 * external URL fetching, OpenRegister lookups, error builders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
abstract class ZgwRulesBase
{

    /**
     * The OpenRegister ObjectService (set per-request).
     *
     * @var object|null
     */
    protected ?object $objectService = null;

    /**
     * The mapping config (set per-request).
     *
     * @var array|null
     */
    protected ?array $mappingConfig = null;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          The logger
     * @param SettingsService $settingsService The settings service
     *
     * @return void
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * Set the per-request services for cross-resource lookups.
     *
     * @param object|null $objectService The OpenRegister ObjectService
     * @param array|null  $mappingConfig The mapping config
     *
     * @return void
     */
    public function setContext(?object $objectService, ?array $mappingConfig): void
    {
        $this->objectService = $objectService;
        $this->mappingConfig = $mappingConfig;
    }//end setContext()

    /**
     * Build a successful validation result (pass-through).
     *
     * @param array $body The (possibly enriched) request body
     *
     * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
     */
    protected function ok(array $body): array
    {
        return [
            'valid'        => true,
            'status'       => 200,
            'detail'       => '',
            'enrichedBody' => $body,
        ];
    }//end ok()

    /**
     * Build a validation error result.
     *
     * @param int    $status        HTTP status code (400 or 403)
     * @param string $detail        Error detail message
     * @param array  $invalidParams Invalid parameter entries
     * @param string $code          Optional error code
     *
     * @return array{valid: bool, status: int, detail: string, invalidParams: array, enrichedBody: array}
     */
    protected function error(
        int $status,
        string $detail,
        array $invalidParams=[],
        string $code=''
    ): array {
        $result = [
            'valid'         => false,
            'status'        => $status,
            'detail'        => $detail,
            'invalidParams' => $invalidParams,
            'enrichedBody'  => [],
        ];
        if ($code !== '') {
            $result['code'] = $code;
        }

        return $result;
    }//end error()

    /**
     * Build a field-level validation error.
     *
     * @param string $fieldName The field name
     * @param string $code      The error code
     * @param string $reason    The error reason
     *
     * @return array{name: string, code: string, reason: string}
     */
    protected function fieldError(string $fieldName, string $code, string $reason): array
    {
        return [
            'name'   => $fieldName,
            'code'   => $code,
            'reason' => $reason,
        ];
    }//end fieldError()

    /**
     * Build a field immutability error response.
     *
     * @param string $fieldName The immutable field name
     *
     * @return array The validation error result
     */
    protected function fieldImmutableError(string $fieldName): array
    {
        $detail = "Het veld {$fieldName} mag niet gewijzigd worden.";
        return $this->error(
                400,
                $detail,
                [
                    $this->fieldError($fieldName, 'wijzigen-niet-toegelaten', $detail),
                ]
                );
    }//end fieldImmutableError()

    /**
     * Extract a UUID from a URL or plain UUID string.
     *
     * @param string $value The URL or UUID
     *
     * @return string|null The extracted UUID, or null
     */
    protected function extractUuid(string $value): ?string
    {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1
        ) {
            return $value;
        }

        if (preg_match(
            '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
            $value,
            $matches
        ) === 1
        ) {
            return $matches[1];
        }

        return null;
    }//end extractUuid()

    /**
     * Check if a URL is syntactically valid.
     *
     * @param string $url The URL to check
     *
     * @return bool True if valid
     */
    protected function isValidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // ZGW resource URLs must end with a valid UUID as the last path segment.
        // Reject URLs that don't point to a specific resource (collection endpoints)
        // and URLs with trailing garbage after the UUID.
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i',
            $path
        ) === 1;
    }//end isValidUrl()

    /**
     * Validate a type URL (zaaktype, besluittype, informatieobjecttype).
     *
     * Checks: URL format, UUID extraction, exists in OpenRegister,
     * publication status (concept=false).
     *
     * @param string $typeUrl   The type URL from the request body
     * @param string $fieldName The field name for error reporting
     * @param string $schemaKey The settings key for the type's schema
     *
     * @return array|null Validation error, or null if valid
     */
    protected function validateTypeUrl(string $typeUrl, string $fieldName, string $schemaKey): ?array
    {
        $extractedUuid = $this->extractUuid($typeUrl);
        if ($extractedUuid === null) {
            return $this->error(
                    400,
                    "De {$fieldName} URL is ongeldig.",
                    [
                        $this->fieldError(
                    $fieldName,
                    'bad-url',
                    "De {$fieldName} URL is ongeldig of wijst niet naar een {$fieldName} resource."
                ),
                    ]
                    );
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: $schemaKey);

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $typeObject = $this->objectService->find(
                id: $extractedUuid,
                register: $register,
                schema: $schema
            );
        } catch (\Throwable $e) {
            return $this->error(
                    400,
                    "De {$fieldName} URL is ongeldig.",
                    [
                        $this->fieldError(
                    $fieldName,
                    'bad-url',
                    "De {$fieldName} URL is ongeldig of wijst niet naar een {$fieldName} resource."
                ),
                    ]
                    );
        }

        $typeData = is_array($typeObject) === true ? $typeObject : $typeObject->jsonSerialize();
        $isDraft  = $typeData['isDraft'] ?? true;
        if ($isDraft === true) {
            return $this->error(
                    400,
                    ucfirst($fieldName).' is nog in concept.',
                    [
                        $this->fieldError($fieldName, 'not-published', ucfirst($fieldName).' is nog in concept.'),
                    ]
                    );
        }

        return null;
    }//end validateTypeUrl()

    /**
     * Validate an informatieobject URL resolves to an existing document.
     *
     * @param string $ioUrl The informatieobject URL
     *
     * @return array|null Validation error, or null if valid
     */
    protected function validateInformatieobjectUrl(string $ioUrl): ?array
    {
        if ($this->isValidUrl($ioUrl) === false) {
            return $this->error(
                    400,
                    'De informatieobject URL is ongeldig.',
                    [
                        $this->fieldError('informatieobject', 'bad-url', 'Ongeldige URL.'),
                    ]
                    );
        }

        $ioUuid = $this->extractUuid($ioUrl);

        // brc-003a: If UUID extraction fails, the URL doesn't point to a valid resource.
        if ($ioUuid === null) {
            return $this->error(
                400,
                'De informatieobject URL is ongeldig.',
                [
                    $this->fieldError(
                        'informatieobject',
                        'bad-url',
                        'De informatieobject URL bevat geen geldig UUID.'
                    ),
                ]
            );
        }

        // If we can look up the document in our own register, do so.
        // If the document is not found locally, that is acceptable — it may
        // be an external informatieobject managed by another DRC instance.
        // We only reject when the URL is syntactically invalid (checked above).
        if ($this->objectService !== null) {
            $register  = $this->mappingConfig['sourceRegister'] ?? '';
            $docSchema = $this->settingsService->getConfigValue(key: 'document_schema');
            if ($register !== '' && $docSchema !== '') {
                try {
                    $this->objectService->find(
                        id: $ioUuid,
                        register: $register,
                        schema: $docSchema
                    );
                } catch (\Throwable $e) {
                    // Document not found locally — acceptable for external DRC URLs.
                    $this->logger->debug(
                        'Informatieobject UUID not found locally, assuming external: '.$ioUuid
                    );
                }
            }
        }//end if

        return null;
    }//end validateInformatieobjectUrl()

    /**
     * Validate an external URL is reachable (basic URL + UUID format check).
     *
     * @param string $url       The URL to validate
     * @param string $fieldName The field name for error reporting
     *
     * @return array|null Validation error, or null if valid
     */
    protected function validateExternalUrl(string $url, string $fieldName): ?array
    {
        if ($this->isValidUrl($url) === false) {
            return $this->error(
                    400,
                    "De {$fieldName} URL is ongeldig.",
                    [
                        $this->fieldError($fieldName, 'bad-url', "De {$fieldName} URL is ongeldig."),
                    ]
                    );
        }

        $path        = parse_url($url, PHP_URL_PATH) ?? '';
        $segments    = array_filter(explode('/', $path));
        $lastSegment = end($segments) ?: '';
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (preg_match($uuidPattern, $lastSegment) !== 1) {
            return $this->error(
                400,
                "De {$fieldName} URL wijst niet naar een geldig object.",
                [$this->fieldError(
                    $fieldName,
                    'invalid-resource',
                    "De {$fieldName} URL wijst niet naar een geldig object."
                )
                ]
            );
        }

        return null;
    }//end validateExternalUrl()

    /**
     * Fetch data from an external URL (selectielijst, resultaattypeomschrijving).
     *
     * @param string $url The URL to fetch
     *
     * @return array|null The JSON response data, or null on failure
     */
    protected function fetchExternalUrl(string $url): ?array
    {
        try {
            $client   = new Client(['timeout' => 10, 'verify' => false]);
            $response = $client->get($url);
            $data     = json_decode((string) $response->getBody(), true);
            if (is_array($data) === false) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to fetch external URL: '.$e->getMessage(),
                ['url' => $url]
            );
            return null;
        }
    }//end fetchExternalUrl()

    /**
     * Generate a unique identificatie string.
     *
     * @param string $prefix A prefix for the identifier (e.g. 'ZAAK', 'BESLUIT')
     *
     * @return string A unique identifier
     */
    protected function generateIdentificatie(string $prefix): string
    {
        $timestamp = strtoupper(base_convert((string) time(), 10, 36));
        $random    = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return $prefix.'-'.$timestamp.'-'.$random;
    }//end generateIdentificatie()

    /**
     * Find an object UUID by a field value (omschrijving/identificatie).
     *
     * @param string $register The OpenRegister register ID
     * @param string $schema   The OpenRegister schema ID
     * @param string $field    The field to search by
     * @param string $value    The value to search for
     *
     * @return string|null The object UUID, or null if not found
     */
    protected function findObjectByField(
        string $register,
        string $schema,
        string $field,
        string $value
    ): ?string {
        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: [$field => $value, '_limit' => 1],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === true) {
                return null;
            }

            $obj  = $results[0];
            $data = is_array($obj) === true ? $obj : $obj->jsonSerialize();

            return $data['id'] ?? ($data['@self']['id'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Reference resolution failed: '.$e->getMessage(),
                ['field' => $field, 'value' => $value]
            );
            return null;
        }//end try
    }//end findObjectByField()

    /**
     * Find all objects matching a field value.
     *
     * @param string $register The register to search in
     * @param string $schema   The schema to search in
     * @param string $field    The field to match on
     * @param string $value    The field value to search for
     *
     * @return array<string> Array of matching object UUIDs
     */
    protected function findAllObjectsByField(
        string $register,
        string $schema,
        string $field,
        string $value
    ): array {
        try {
            $query  = $this->objectService->buildSearchQuery(
                requestParams: [$field => $value, '_limit' => 100],
                register: $register,
                schema: $schema
            );
            $result = $this->objectService->searchObjectsPaginated(query: $query);

            $ids = [];
            foreach (($result['results'] ?? []) as $obj) {
                $data = is_array($obj) === true ? $obj : $obj->jsonSerialize();
                $id   = $data['id'] ?? ($data['@self']['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Reference resolution failed: '.$e->getMessage(),
                ['field' => $field, 'value' => $value]
            );
            return [];
        }//end try
    }//end findAllObjectsByField()

    /**
     * Look up an object in OpenRegister by UUID and schema key.
     *
     * @param string $uuid      The object UUID
     * @param string $schemaKey The settings config key for the schema
     *
     * @return array|null The object data, or null on failure
     */
    protected function findBySchemaKey(string $uuid, string $schemaKey): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->settingsService->getConfigValue(key: $schemaKey);

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $obj = $this->objectService->find(
                id: $uuid,
                register: $register,
                schema: $schema
            );
            return is_array($obj) === true ? $obj : $obj->jsonSerialize();
        } catch (\Throwable $e) {
            return null;
        }
    }//end findBySchemaKey()

    /**
     * Check unique combination of two fields (identificatie + organisatie).
     *
     * @param string $field1Value  First field value (e.g. identificatie)
     * @param string $field1Search OpenRegister field name to search
     * @param string $field2Value  Second field value (e.g. organisatie)
     * @param string $field2Search OpenRegister field name to search
     * @param string $errorField   Field name for error reporting
     *
     * @return array|null Validation error if duplicate found, null if unique
     */
    protected function checkFieldUniqueness(
        string $field1Value,
        string $field1Search,
        string $field2Value,
        string $field2Search,
        string $errorField
    ): ?array {
        if ($field1Value === '' || $this->objectService === null) {
            return null;
        }

        $register = $this->mappingConfig['sourceRegister'] ?? '';
        $schema   = $this->mappingConfig['sourceSchema'] ?? '';
        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            // Build query directly to avoid buildSearchQuery's underscore-splitting
            // which breaks camelCase field names like sourceOrganisation.
            // Search only by field1 (identifier) because OpenRegister may store
            // numeric strings (e.g. "000000000") as integers, which breaks
            // exact-match search for field2 (sourceOrganisation).
            $query = [
                '@self' => [
                    'register' => (int) $register,
                    'schema'   => (int) $schema,
                ],
                $field1Search => $field1Value,
            ];

            $result = $this->objectService->searchObjectsPaginated(
                query: $query,
                _rbac: false,
                _multitenancy: false
            );

            // Post-filter results by field2 value in memory, comparing both
            // string and numeric forms to handle integer coercion by OpenRegister.
            // OpenRegister may store numeric-looking strings (e.g. "000000000")
            // as integer 0, which the magic mapper may serialize to empty string.
            // When the stored value is empty but field2 was provided, we still
            // count it as a match (conservative: assume coercion happened).
            $matchCount = 0;
            foreach (($result['results'] ?? []) as $obj) {
                $data       = is_array($obj) === true ? $obj : $obj->jsonSerialize();
                $storedVal  = $data[$field2Search] ?? null;
                $storedStr  = (string) $storedVal;
                $compareStr = (string) $field2Value;

                // Match when: no field2 filter, or values match directly,
                // or stored is empty/0 (likely coerced from numeric string).
                $isMatch = ($field2Value === '')
                    || ($storedStr === $compareStr)
                    || ($storedStr === '' && $field2Value !== '')
                    || ($storedStr === '0' && preg_match('/^0+$/', $field2Value) === 1);

                if ($isMatch === true) {
                    $matchCount++;
                }
            }

            if ($matchCount > 0) {
                return $this->error(
                    400,
                    'De combinatie is niet uniek.',
                    [$this->fieldError(
                        $errorField,
                        'identificatie-niet-uniek',
                        'De combinatie bestaat al.'
                    )
                    ]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Uniqueness check failed: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkFieldUniqueness()
}//end class
