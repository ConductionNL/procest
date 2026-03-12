<?php

/**
 * Procest ZGW Document Service
 *
 * Handles binary file storage for ZGW Documenten API (DRC) documents.
 * Stores files in Nextcloud's file system and manages locking.
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing binary document storage in the DRC.
 *
 * Stores document files under the admin user's Nextcloud files at:
 * /admin/files/procest/documenten/{uuid}/{filename}
 */
class ZgwDocumentService
{
    /**
     * Base folder path for document storage.
     */
    private const STORAGE_BASE = 'procest/documenten';

    /**
     * Constructor.
     *
     * @param IRootFolder     $rootFolder The Nextcloud root folder
     * @param LoggerInterface $logger     The logger
     *
     * @return void
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Store a document file from base64 content.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     * @param string $content  The base64-encoded file content
     *
     * @return int The file size in bytes
     */
    public function storeBase64(string $uuid, string $fileName, string $content): int
    {
        $decoded = base64_decode(string: $content, strict: false);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('Invalid base64 content');
        }

        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->newFile(path: $fileName);
        $file->putContent(data: $decoded);

        return strlen(string: $decoded);
    }

    /**
     * Store a document file from raw binary content.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     * @param string $content  The raw binary content
     *
     * @return int The file size in bytes
     */
    public function storeRaw(string $uuid, string $fileName, string $content): int
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->newFile(path: $fileName);
        $file->putContent(data: $content);

        return strlen(string: $content);
    }

    /**
     * Get the binary content of a stored document.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return string The file content
     *
     * @throws NotFoundException If the file does not exist.
     */
    public function getContent(string $uuid, string $fileName): string
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);

        return $folder->get(path: $fileName)->getContent();
    }

    /**
     * Check whether a document file exists.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return bool True if the file exists
     */
    public function fileExists(string $uuid, string $fileName): bool
    {
        try {
            $folder = $this->getDocumentFolder(uuid: $uuid);
            $folder->get(path: $fileName);
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * Delete all files for a document.
     *
     * @param string $uuid The document UUID
     *
     * @return void
     */
    public function deleteFiles(string $uuid): void
    {
        try {
            $userFolder = $this->getUserFolder();
            $path       = self::STORAGE_BASE . '/' . $uuid;
            if ($userFolder->nodeExists(path: $path) === true) {
                $userFolder->get(path: $path)->delete();
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to delete document files for ' . $uuid,
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Get the MIME type of a stored file.
     *
     * @param string $uuid     The document UUID
     * @param string $fileName The file name
     *
     * @return string The MIME type
     *
     * @throws NotFoundException If the file does not exist.
     */
    public function getMimeType(string $uuid, string $fileName): string
    {
        $folder = $this->getDocumentFolder(uuid: $uuid);
        $file   = $folder->get(path: $fileName);

        return $file->getMimeType();
    }

    /**
     * Get or create the document storage folder for a UUID.
     *
     * @param string $uuid The document UUID
     *
     * @return Folder The document folder
     */
    private function getDocumentFolder(string $uuid): Folder
    {
        $userFolder = $this->getUserFolder();
        $path       = self::STORAGE_BASE . '/' . $uuid;

        if ($userFolder->nodeExists(path: $path) === false) {
            $userFolder->newFolder(path: $path);
        }

        return $userFolder->get(path: $path);
    }

    /**
     * Get the admin user's root folder.
     *
     * @return Folder The user folder
     */
    private function getUserFolder(): Folder
    {
        return $this->rootFolder->getUserFolder(userId: 'admin');
    }
}
