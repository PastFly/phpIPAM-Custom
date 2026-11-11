<?php
declare(strict_types=1);

/** All methods except transaction() are called inside one serialized configuration transaction. */
interface IntegrationDnsConfigRepository
{
    public function transaction(callable $work): mixed;
    public function profiles(): array;
    public function profile(string $id): ?array;
    public function putProfile(array $row): void;
    public function removeProfile(string $id): void;
    public function connections(): array;
    public function connection(string $id): ?array;
    public function putConnection(array $row): void;
    public function removeConnection(string $id): void;
    public function audit(array $event): void;
}
