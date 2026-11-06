<?php
declare(strict_types=1);

/** All methods except transaction must run under the shared DNS configuration guard. */
interface IntegrationDnsZoneSelectionRepository
{
    public function transaction(callable $work): mixed;
    public function source(string $id): ?array;
    public function collector(string $id): ?array;
    public function assignment(string $sourceId): ?array;
    /** Latest complete successful discovery, with exact source/collector/revisions and bounded zone metadata. */
    public function latestDiscovery(string $sourceId): ?array;
    public function dataset(string $id): ?array;
    public function datasetByZone(string $sourceId,string $zone): ?array;
    public function selectedCount(string $sourceId): int;
    /** Dataset source/zone/instance/scope are immutable once created. */
    public function putDataset(array $row): void;
    public function audit(array $event): void;
}
