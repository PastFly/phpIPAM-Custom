<?php
declare(strict_types=1);

/** DNS3 collector/control storage contract. All non-transaction methods are called inside transaction(). */
interface IntegrationDnsCollectorControlRepository
{
    public function transaction(callable $work): mixed;
    public function collectors(): array;
    public function collector(string $id): ?array;
    public function putCollector(array $row): void;
    public function assignments(): array;
    public function assignment(string $connectionId): ?array;
    public function putAssignment(array $row): void;
    public function removeAssignment(string $connectionId): void;
    public function jobs(): array;
    public function putJob(array $row): void;
    public function removeJob(string $id): void;
    public function audit(array $event): void;
}
