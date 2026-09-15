#!/usr/bin/env php
<?php
/**
 * Narrow read-only diagnostic for ambiguous vCenter PerfCounterInfo registries.
 * Reports aggregate conflict shape only; never emits counter ids, canonical names, provider text or credentials.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H_COUNTERDIAG_ERROR=identity\nPHASE5H_COUNTERDIAG_EXIT=1\n");
    exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';

$runtime = null;
$productionLock = null;
$lockHeld = false;
$startedWall = microtime(true);

$clean = static function (Throwable $e): string {
    $m = $e->getMessage();
    return preg_match('/^(?:batch|shadow|mirror|fast|active)_[a-z0-9_:-]{1,100}$/D', $m) ? $m : 'unexpected';
};

try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name) {
        require_once APP . '/functions/classes/class.' . $name . '.php';
    }
    foreach (['Store','Context'] as $name) {
        require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    }
    $root = dirname(__DIR__);
    require_once $root . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php';

    $db = new Database_PDO();
    $ctx = new IntegrationVmwareInventoryContext($db, APP, 1);

    IntegrationVmwareInventoryStore::noLinks(ACTIVE_WORKER_LOCK);
    clearstatcache(true, ACTIVE_WORKER_LOCK);
    $st = @lstat(ACTIVE_WORKER_LOCK);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== 33 || $st['gid'] !== 33 ||
        ($st['mode'] & 0777) !== 0600 || $st['nlink'] !== 1 || $st['size'] !== 0) {
        throw new RuntimeException('active_worker_lock_invalid');
    }
    $productionLock = @fopen(ACTIVE_WORKER_LOCK, 'r+b');
    if ($productionLock === false) throw new RuntimeException('active_worker_lock_invalid');
    $opened = fstat($productionLock);
    if (!$opened || $opened['ino'] !== $st['ino'] || $opened['dev'] !== $st['dev'] ||
        ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600 ||
        $opened['uid'] !== 33 || $opened['gid'] !== 33 || $opened['nlink'] !== 1 || $opened['size'] !== 0) {
        throw new RuntimeException('active_worker_lock_changed');
    }

    $waitStarted = microtime(true); $announced = false;
    while (!flock($productionLock, LOCK_EX | LOCK_NB)) {
        if (!$announced) { print "PHASE5H_COUNTERDIAG_LOCK_WAIT=1\n"; $announced = true; }
        if (microtime(true) - $waitStarted >= 600) throw new RuntimeException('active_worker_busy');
        usleep(250000);
        clearstatcache(true, ACTIVE_WORKER_LOCK);
        $now = @lstat(ACTIVE_WORKER_LOCK);
        if (!$now || $now['ino'] !== $st['ino'] || $now['dev'] !== $st['dev'] || $now['size'] !== 0) {
            throw new RuntimeException('active_worker_lock_changed');
        }
    }
    $lockHeld = true;
    $lockWaitMs = (int)round((microtime(true) - $waitStarted) * 1000);
    print "PHASE5H_COUNTERDIAG_LOCK_ACQUIRED=1\n";
    print 'PHASE5H_COUNTERDIAG_LOCK_WAIT_MS=' . $lockWaitMs . "\n";

    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());

    $session = new IntegrationVmwareBatchSession($runtime, [$ctx, 'current'], null, 32, 60);
    $result = $session->run(static function (IntegrationVmwareBatchSession $s): array {
        $manager = $s->manager('perfManager');
        $response = $s->read('RetrieveProperties', '<_this type="PropertyCollector">' .
            IntegrationVmwareBatchSession::xml($s->manager('propertyCollector')) . '</_this><specSet>' .
            '<propSet><type>PerformanceManager</type><all>false</all><pathSet>perfCounter</pathSet></propSet>' .
            '<objectSet><obj type="PerformanceManager">' . IntegrationVmwareBatchSession::xml($manager) . '</obj><skip>false</skip></objectSet></specSet>');

        $row = IntegrationVmwareBatchSession::one($response, 'returnval');
        $object = IntegrationVmwareBatchSession::one($row, 'obj');
        if ($object->getAttribute('type') !== 'PerformanceManager' ||
            IntegrationVmwareBatchSession::text($object) !== $manager ||
            IntegrationVmwareBatchSession::children($row, 'missingSet') !== []) {
            throw new RuntimeException('batch_counter_property_invalid');
        }
        $property = IntegrationVmwareBatchSession::one($row, 'propSet');
        if (IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($property, 'name')) !== 'perfCounter') {
            throw new RuntimeException('batch_counter_property_invalid');
        }
        $value = IntegrationVmwareBatchSession::one($property, 'val');
        $list = IntegrationVmwareBatchSession::children($value, 'PerfCounterInfo');
        if (count($list) < 1 || count($list) > 10000) throw new RuntimeException('batch_counter_registry_invalid');

        $selected = [
            'cpu.usage.average' => 'percent',
            'mem.usage.average' => 'percent',
            'disk.usage.average' => 'kiloBytesPerSecond',
        ];
        $byCanonical = []; $byId = []; $tuples = [];

        $nested = static function (DOMElement $counter, string $name): string {
            return IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one(
                IntegrationVmwareBatchSession::one($counter, $name), 'key'));
        };
        $integer = static function (DOMElement $node): int {
            $raw = IntegrationVmwareBatchSession::text($node);
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) throw new RuntimeException('batch_counter_registry_invalid');
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > 2147483647) throw new RuntimeException('batch_counter_registry_invalid');
            return $value;
        };

        foreach ($list as $counter) {
            $id = $integer(IntegrationVmwareBatchSession::one($counter, 'key'));
            $group = $nested($counter, 'groupInfo');
            $name = $nested($counter, 'nameInfo');
            $unit = $nested($counter, 'unitInfo');
            $rollup = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($counter, 'rollupType'));
            foreach ([$group, $name, $unit, $rollup] as $token) {
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $token)) throw new RuntimeException('batch_counter_registry_invalid');
            }
            $canonical = $group . '.' . $name . '.' . $rollup;
            $definition = (string)$id . '|' . $unit;
            $reverse = $canonical . '|' . $unit;
            $tuple = $canonical . '|' . (string)$id . '|' . $unit;
            $byCanonical[$canonical][$definition] = true;
            $byId[(string)$id][$reverse] = true;
            $tuples[$tuple] = ($tuples[$tuple] ?? 0) + 1;
        }

        $exactDuplicateEntries = 0;
        foreach ($tuples as $count) if ($count > 1) $exactDuplicateEntries += $count - 1;

        $canonicalConflictKeys = 0;
        foreach ($byCanonical as $definitions) if (count($definitions) > 1) ++$canonicalConflictKeys;
        $idConflictKeys = 0;
        foreach ($byId as $definitions) if (count($definitions) > 1) ++$idConflictKeys;

        $selectedFound = 0; $selectedCanonicalConflicts = 0; $selectedIdConflicts = 0;
        $selectedUnitConflicts = 0; $selectedExactDuplicateEntries = 0;
        $selectedCanonicalConflictSet = []; $selectedIdConflictSet = [];

        foreach ($selected as $canonical => $expectedUnit) {
            $definitions = $byCanonical[$canonical] ?? [];
            if ($definitions === []) continue;
            ++$selectedFound;
            if (count($definitions) > 1) {
                ++$selectedCanonicalConflicts;
                $selectedCanonicalConflictSet[$canonical] = true;
            }
            foreach (array_keys($definitions) as $definition) {
                [$idText, $unit] = explode('|', $definition, 2);
                if ($unit !== $expectedUnit) ++$selectedUnitConflicts;
                $reverseSet = $byId[$idText] ?? [];
                foreach (array_keys($reverseSet) as $reverse) {
                    $suffix = '|' . $expectedUnit;
                    if ($reverse !== $canonical . $suffix) {
                        $selectedIdConflictSet[$idText] = true;
                        break;
                    }
                }
                $tuple = $canonical . '|' . $idText . '|' . $unit;
                $count = $tuples[$tuple] ?? 0;
                if ($count > 1) $selectedExactDuplicateEntries += $count - 1;
            }
        }
        $selectedIdConflicts = count($selectedIdConflictSet);
        $nonSelectedCanonicalConflicts = max(0, $canonicalConflictKeys - count($selectedCanonicalConflictSet));
        $nonSelectedIdConflicts = max(0, $idConflictKeys - $selectedIdConflicts);
        $selectedMappingSafe = $selectedFound === count($selected) && $selectedCanonicalConflicts === 0 &&
            $selectedIdConflicts === 0 && $selectedUnitConflicts === 0;

        return [
            'registry_entries'=>count($list),
            'unique_canonicals'=>count($byCanonical),
            'unique_ids'=>count($byId),
            'exact_duplicate_entries'=>$exactDuplicateEntries,
            'canonical_conflict_keys'=>$canonicalConflictKeys,
            'id_conflict_keys'=>$idConflictKeys,
            'selected_found'=>$selectedFound,
            'selected_exact_duplicate_entries'=>$selectedExactDuplicateEntries,
            'selected_canonical_conflicts'=>$selectedCanonicalConflicts,
            'selected_id_conflicts'=>$selectedIdConflicts,
            'selected_unit_conflicts'=>$selectedUnitConflicts,
            'nonselected_canonical_conflicts'=>$nonSelectedCanonicalConflicts,
            'nonselected_id_conflicts'=>$nonSelectedIdConflicts,
            'selected_mapping_safe'=>$selectedMappingSafe,
        ];
    });

    $stats = $session->statistics();
    $result['requests'] = $stats['requests'];
    $result['response_bytes'] = $stats['response_bytes'];
    $result['wall_ms'] = (int)round((microtime(true) - $startedWall) * 1000);
    $result['lock_wait_ms'] = $lockWaitMs;
    $result['shadow_written'] = false;
    print 'PHASE5H_COUNTERDIAG_RESULT=' . json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    print "PHASE5H_COUNTERDIAG_EXIT=0\n";
    $rc = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'PHASE5H_COUNTERDIAG_ERROR=' . $clean($e) . "\n");
    fwrite(STDERR, "PHASE5H_COUNTERDIAG_EXIT=1\n");
    $rc = 1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
}
exit($rc);
