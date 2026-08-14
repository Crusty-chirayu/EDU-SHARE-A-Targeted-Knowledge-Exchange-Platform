<?php
declare(strict_types=1);

namespace EduShare\Modules\Resources\Infrastructure;

use EduShare\Modules\Resources\Application\ResourceMetadata;
use EduShare\Modules\Resources\Application\ResourceMetadataUpdate;
use EduShare\Modules\Resources\Application\ResourceRepository;
use EduShare\Modules\Resources\Application\VerifiedResourceFile;

final class MysqliResourceRepository implements ResourceRepository
{
    public function __construct(private readonly \mysqli $connection)
    {
    }

    public function begin(): void
    {
        $this->connection->begin_transaction();
    }

    public function lockOwner(int $ownerId): void
    {
        $statement = $this->connection->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->bind_param('i', $ownerId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        if ($row === null) {
            throw new \RuntimeException('The resource owner could not be locked.');
        }
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollback();
    }

    public function find(int $resourceId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT r.id, r.owner_id, r.title, r.description, r.document_type, r.language_code,
                       r.university_id, r.department_id, r.course_id, r.subject_id, r.semester,
                       r.visibility, r.publication_status, r.moderation_status,
                       r.current_version_number, r.deletion_status, r.created_at, r.updated_at,
                       u.name AS university_name, d.name AS department_name, c.name AS course_name,
                       s.name AS subject_name, owner.full_name AS owner_name,
                       (SELECT COUNT(*) FROM resource_versions version_count
                         WHERE version_count.resource_id = r.id) AS version_count,
                       (SELECT COUNT(*) FROM resource_files file_count
                         JOIN resource_versions current_version ON current_version.id = file_count.resource_version_id
                        WHERE current_version.resource_id = r.id
                          AND current_version.version_number = r.current_version_number
                          AND file_count.storage_status = \'available\') AS file_count
                  FROM resources r
                  JOIN users owner ON owner.id = r.owner_id
                  JOIN universities u ON u.id = r.university_id
                  JOIN departments d ON d.id = r.department_id
                  JOIN courses c ON c.id = r.course_id
                  JOIN subjects s ON s.id = r.subject_id
                 WHERE r.id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->bind_param('i', $resourceId);
        $statement->execute();
        return $statement->get_result()->fetch_assoc() ?: null;
    }

    public function findFileForDownload(int $resourceId, ?int $fileId): ?array
    {
        $sql = 'SELECT rf.id, rf.original_filename, rf.storage_key, rf.extension, rf.mime_type,
                       rf.file_size, rf.checksum_sha256, rf.scan_status, rf.processing_status,
                       rf.storage_status, rv.version_number, r.id AS resource_id, r.owner_id,
                       r.visibility, r.publication_status, r.moderation_status, r.deletion_status
                  FROM resource_files rf
                  JOIN resource_versions rv ON rv.id = rf.resource_version_id
                  JOIN resources r ON r.id = rv.resource_id
                 WHERE r.id = ? AND rf.storage_status = \'available\'
                   AND rf.processing_status = \'ready\' AND rf.scan_status <> \'blocked\'';
        if ($fileId !== null) {
            $sql .= ' AND rf.id = ?';
        } else {
            $sql .= ' AND rv.version_number = r.current_version_number';
        }
        $sql .= ' ORDER BY rf.id LIMIT 1';
        $statement = $this->connection->prepare($sql);
        if ($fileId !== null) {
            $statement->bind_param('ii', $resourceId, $fileId);
        } else {
            $statement->bind_param('i', $resourceId);
        }
        $statement->execute();
        return $statement->get_result()->fetch_assoc() ?: null;
    }

    public function versionsWithFiles(int $resourceId): array
    {
        $statement = $this->connection->prepare(
            'SELECT rv.id AS version_id, rv.version_number, rv.change_description,
                    rv.lifecycle_status, rv.created_at AS version_created_at,
                    rf.id AS file_id, rf.original_filename, rf.extension, rf.mime_type,
                    rf.file_size, rf.checksum_sha256, rf.scan_status,
                    rf.processing_status, rf.storage_status, rf.created_at AS file_created_at
               FROM resource_versions rv
               LEFT JOIN resource_files rf ON rf.resource_version_id = rv.id
              WHERE rv.resource_id = ?
              ORDER BY rv.version_number DESC, rf.id ASC'
        );
        $statement->bind_param('i', $resourceId);
        $statement->execute();
        return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function resourceIdForOwnerChecksum(
        int $ownerId,
        string $checksum,
        ?int $excludingResourceId = null
    ): ?int {
        $sql = 'SELECT r.id
                  FROM resource_files rf
                  JOIN resource_versions rv ON rv.id = rf.resource_version_id
                  JOIN resources r ON r.id = rv.resource_id
                 WHERE r.owner_id = ? AND rf.checksum_sha256 = ?
                   AND r.deletion_status = \'active\' AND rf.storage_status = \'available\'';
        if ($excludingResourceId !== null) {
            $sql .= ' AND r.id <> ?';
        }
        $sql .= ' ORDER BY r.id LIMIT 1';

        $statement = $this->connection->prepare($sql);
        if ($excludingResourceId === null) {
            $statement->bind_param('is', $ownerId, $checksum);
        } else {
            $statement->bind_param('isi', $ownerId, $checksum, $excludingResourceId);
        }
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return $row === null ? null : (int) $row['id'];
    }

    public function insertResource(int $ownerId, ResourceMetadata $metadata, string $documentType): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO resources
                (owner_id, title, description, document_type, university_id, department_id,
                 course_id, subject_id, semester, visibility, publication_status, moderation_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'published\', \'approved\')'
        );
        $title = $metadata->title;
        $description = $metadata->description;
        $universityId = $metadata->universityId;
        $departmentId = $metadata->departmentId;
        $courseId = $metadata->courseId;
        $subjectId = $metadata->subjectId;
        $semester = $metadata->semester;
        $visibility = $metadata->visibility;
        $statement->bind_param(
            'isssiiiiis',
            $ownerId,
            $title,
            $description,
            $documentType,
            $universityId,
            $departmentId,
            $courseId,
            $subjectId,
            $semester,
            $visibility
        );
        $statement->execute();
        return (int) $this->connection->insert_id;
    }

    public function insertVersion(
        int $resourceId,
        int $versionNumber,
        int $creatorId,
        ?string $changeDescription
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO resource_versions
                (resource_id, version_number, created_by, change_description, lifecycle_status)
             VALUES (?, ?, ?, ?, \'current\')'
        );
        $statement->bind_param('iiis', $resourceId, $versionNumber, $creatorId, $changeDescription);
        $statement->execute();
        return (int) $this->connection->insert_id;
    }

    public function insertFile(int $versionId, VerifiedResourceFile $file, string $storageKey): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO resource_files
                (resource_version_id, original_filename, storage_key, extension, mime_type,
                 file_size, checksum_sha256, scan_status, processing_status, storage_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'not_scanned\', \'ready\', \'available\')'
        );
        $originalFilename = $file->originalFilename;
        $extension = $file->extension;
        $mimeType = $file->mimeType;
        $size = $file->size;
        $checksum = $file->checksumSha256;
        $statement->bind_param(
            'issssis',
            $versionId,
            $originalFilename,
            $storageKey,
            $extension,
            $mimeType,
            $size,
            $checksum
        );
        $statement->execute();
        return (int) $this->connection->insert_id;
    }

    public function advanceCurrentVersion(int $resourceId, int $versionNumber, string $documentType): void
    {
        $supersede = $this->connection->prepare(
            'UPDATE resource_versions
                SET lifecycle_status = CASE WHEN version_number = ? THEN \'current\' ELSE \'superseded\' END
              WHERE resource_id = ?'
        );
        $supersede->bind_param('ii', $versionNumber, $resourceId);
        $supersede->execute();

        $resource = $this->connection->prepare(
            'UPDATE resources SET current_version_number = ?, document_type = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND deletion_status = \'active\''
        );
        $resource->bind_param('isi', $versionNumber, $documentType, $resourceId);
        $resource->execute();
        if ($resource->affected_rows !== 1) {
            throw new \RuntimeException('The resource version pointer could not be advanced.');
        }
    }

    public function updateMetadata(int $resourceId, ResourceMetadataUpdate $changes): void
    {
        $statement = $this->connection->prepare(
            'UPDATE resources
                SET title = ?, description = ?, visibility = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND deletion_status = \'active\''
        );
        $title = $changes->title;
        $description = $changes->description;
        $visibility = $changes->visibility;
        $statement->bind_param('sssi', $title, $description, $visibility, $resourceId);
        $statement->execute();
    }

    public function lockStoredFiles(int $resourceId): array
    {
        $statement = $this->connection->prepare(
            'SELECT rf.id, rf.storage_key, rf.storage_status, rf.quarantine_token
               FROM resource_files rf
               JOIN resource_versions rv ON rv.id = rf.resource_version_id
              WHERE rv.resource_id = ?
              ORDER BY rv.version_number, rf.id
              FOR UPDATE'
        );
        $statement->bind_param('i', $resourceId);
        $statement->execute();
        $rows = [];
        foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'storage_key' => (string) $row['storage_key'],
                'storage_status' => (string) $row['storage_status'],
                'quarantine_token' => $row['quarantine_token'] === null
                    ? null
                    : (string) $row['quarantine_token'],
            ];
        }
        return $rows;
    }

    public function setFileQuarantine(
        int $fileId,
        string $quarantineToken,
        string $status = 'quarantined'
    ): void {
        if (!in_array($status, ['quarantined', 'cleanup_pending'], true)
            || preg_match('/^[a-f0-9]{64}\.quarantine$/D', $quarantineToken) !== 1) {
            throw new \InvalidArgumentException('Invalid file quarantine state.');
        }
        $statement = $this->connection->prepare(
            'UPDATE resource_files SET storage_status = ?, quarantine_token = ? WHERE id = ?'
        );
        $statement->bind_param('ssi', $status, $quarantineToken, $fileId);
        $statement->execute();
    }

    public function setOneFileStorageStatus(int $fileId, string $status): void
    {
        if (!in_array($status, ['available', 'deleted', 'missing'], true)) {
            throw new \InvalidArgumentException('Invalid terminal file storage status.');
        }
        $statement = $this->connection->prepare(
            'UPDATE resource_files SET storage_status = ?, quarantine_token = NULL WHERE id = ?'
        );
        $statement->bind_param('si', $status, $fileId);
        $statement->execute();
    }

    public function markResourceDeleted(int $resourceId, string $status): void
    {
        if (!in_array($status, ['pending_cleanup', 'deleted'], true)) {
            throw new \InvalidArgumentException('Invalid resource deletion status.');
        }
        $statement = $this->connection->prepare(
            'UPDATE resources
                SET deletion_status = ?, publication_status = \'archived\', deleted_at = CURRENT_TIMESTAMP
              WHERE id = ? AND deletion_status = \'active\''
        );
        $statement->bind_param('si', $status, $resourceId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            throw new \RuntimeException('Resource deletion state could not be recorded.');
        }
    }

    public function setResourceDeletionStatus(int $resourceId, string $status): void
    {
        if (!in_array($status, ['pending_cleanup', 'deleted'], true)) {
            throw new \InvalidArgumentException('Invalid resource deletion status.');
        }
        $statement = $this->connection->prepare(
            'UPDATE resources SET deletion_status = ? WHERE id = ? AND deletion_status <> \'active\''
        );
        $statement->bind_param('si', $status, $resourceId);
        $statement->execute();
    }
}
