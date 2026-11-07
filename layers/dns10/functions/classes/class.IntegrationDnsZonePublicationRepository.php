<?php
declare(strict_types=1);

/**
 * All methods except transaction run inside its shared DNS configuration guard.
 * Adapters must atomically roll back generation, head, pruning and job/audit writes.
 */
interface IntegrationDnsZonePublicationRepository
{
    public function transaction(callable $work): mixed;
    public function uploadToken(string $id): ?array;
    public function source(string $id): ?array;
    public function collector(string $id): ?array;
    public function assignment(string $sourceId): ?array;
    public function dataset(string $id): ?array;
    public function job(string $id): ?array;
    /** Returns ['current'=>?generation,'previous'=>?generation]; dangling pointers must fail closed. */
    public function head(string $datasetId): array;
    /** Count every generation for this dataset, including any unreferenced rows. */
    public function generationCount(string $datasetId): int;
    public function accountedBytes(): int;
    public function insertGeneration(array $generation,array $records): void;
    public function setHead(string $datasetId,string $currentId,?string $previousId): void;
    public function removeGeneration(string $generationId): void;
    /** Complete exactly the matching still-running lease; audit in this transaction. */
    public function completeJob(string $jobId,#[SensitiveParameter] string $leaseId,int $now): void;
}
