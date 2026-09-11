<?php
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php';
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php';
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryReader.php';
final class MirrorFixtureProvider implements IntegrationVmwareInventoryProvider
{
    public int $calls = 0;
    public array $ids = ['vm' => ['vm-1', 'vm-2'], 'host' => ['host-1'], 'datastore' => ['datastore-1']];
    public ?string $fault = null;
    public bool $optionalFails = false;
    public bool $partial = false;
    public $duringCollect = null;
    public function discover(): array
    {
        $this->calls++;
        if ($this->fault) throw new RuntimeException($this->fault);
        return ['ok' => true, 'ids' => $this->ids, 'core' => ['ok' => true, 'topology' => [], 'datastores' => []]];
    }
    public function collect(string $kind, string $id, array $seed): array
    {
        $this->calls++;
        if ($this->duringCollect) ($this->duringCollect)();
        if ($this->fault) throw new RuntimeException($this->fault);
        return ['ok' => true, 'partial' => $this->partial, 'name' => 'Name ' . $id, 'id' => $id,
            'password' => 'DO-NOT-PERSIST', 'headers' => ['Cookie' => 'DO-NOT-PERSIST'],
            'annotation' => '<script>ordinary inventory text</script>',
            'sections' => ['guest' => $this->optionalFails ? ['state' => 'unavailable', 'data' => null] :
                ['state' => 'ok', 'observed_at' => 1000, 'data' => ['memory_mb' => 4096]]]];
    }
}
$n = 0;
function check(bool $ok, string $why): void { global $n; if (!$ok) throw new RuntimeException('FAIL: ' . $why); $n++; }
function fails(callable $fn, string $contains): void {
    try { $fn(); } catch (Throwable $e) { check(str_contains($e->getMessage(), $contains), 'expected ' . $contains . ', got ' . $e->getMessage()); return; }
    throw new RuntimeException('FAIL: expected exception ' . $contains);
}
$base = sys_get_temp_dir() . '/phpipam-mirror-tests-' . bin2hex(random_bytes(8)); mkdir($base, 0700);
function folder(string $name): string { global $base; $p = $base . '/' . $name; mkdir($p, 0700); return $p; }
function wipe(string $path): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') wipe($path . '/' . $name);
    rmdir($path);
}
$key = random_bytes(32); $binding = str_repeat('a', 64); $now = 1000; $allowed = true;
$clock = static function () use (&$now): int { return $now; };
$guard = static function () use (&$allowed): bool { return $allowed; };
try {
    $dir = folder('main'); $s = new IntegrationVmwareInventoryStore($dir, $key, $binding); $p = new MirrorFixtureProvider();
    $reader = new IntegrationVmwareInventoryReader($s, $guard, $clock);
    $before = scandir($dir); $result = $reader->query('vm', 'vm-1');
    check($result['state'] === 'initializing' && $result['data'] === null, 'initial state without blocking loader');
    check(scandir($dir) === $before && $p->calls === 0, 'empty web read does not create files or call provider');
    $w = new IntegrationVmwareInventoryWorker($s, $p, $guard, $clock);
    $first = $w->tick(2); check($first['state'] === 'collecting', 'bounded tick');
    check(!$reader->query()['has_inventory'], 'incomplete data is never visible');
    $calls = $p->calls;
    unset($w, $s);
    $s = new IntegrationVmwareInventoryStore($dir, $key, $binding);
    $w = new IntegrationVmwareInventoryWorker($s, $p, $guard, $clock);
    check($w->tick(20)['state'] === 'ready', 'resume after reconstructed process objects');
    check($p->calls - $calls === 7, 'resume does not restart completed discovery and first domain');
    $reader = new IntegrationVmwareInventoryReader($s, $guard, $clock);
    $vm = $reader->query('vm', 'vm-1'); check($vm['data']['name'] === 'Name vm-1', 'single local card');
    check(!array_key_exists('password', $vm['data']) && !array_key_exists('headers', $vm['data']), 'credential fields stripped');
    check(!str_contains(file_get_contents($dir . '/current.enc'), 'Name vm-1'), 'real ciphertext on disk');
    check((fileperms($dir . '/current.enc') & 0777) === 0600, 'private snapshot mode');
    check($reader->query('vms', '', '', 2, 1)['data'][0]['id'] === 'vm-2', 'local pagination');
    check($reader->query('vms', '', 'Name vm-1')['total'] === 1, 'local list filtering');
    check($w->tick()['state'] === 'idle', 'refresh cadence independent of clicks');
    $now += 86400; $p->fault = 'network down token=DO-NOT-PERSIST';
    $digest = hash_file('sha256', $dir . '/current.enc'); $calls = $p->calls;
    for ($i = 0; $i < 100; $i++) $vm = $reader->query('vm', 'vm-1');
    check($p->calls === $calls && hash_file('sha256', $dir . '/current.enc') === $digest, 'overnight local reads do no network or file writes');
    check($vm['stale'] === true && $vm['age_seconds'] === 86400, 'honest age after no viewers');
    check($w->tick()['error'] === 'collection_failed', 'sanitized failure category');
    check($reader->query('vm', 'vm-1')['data']['id'] === 'vm-1', 'transient outage preserves last-good');
    check(!str_contains(json_encode($s->read('current')), 'DO-NOT-PERSIST'), 'no raw provider errors saved');
    check($w->tick()['state'] === 'backoff', 'bounded retry/backoff');
    $now += 2000; $p->fault = 'HTTP 403 forbidden';
    check($w->tick()['error'] === 'access_denied', 'known denied is recorded');
    fails(fn() => $reader->query('vm', 'vm-1'), 'access_denied');
    $now += 2000; $p->fault = null; $w->tick(1);
    fails(fn() => $reader->query('vm', 'vm-1'), 'access_denied');
    $w->tick(20); check($reader->query('vm', 'vm-1')['data']['id'] === 'vm-1', 'complete fresh generation clears known denial');
    $allowed = false; $calls = $p->calls;
    fails(fn() => $reader->query(), 'access_denied');
    fails(fn() => $w->tick(), 'binding_changed'); check($p->calls === $calls, 'disabled guard before calls');
    $allowed = true;
    $other = new IntegrationVmwareInventoryStore($dir, $key, str_repeat('b', 64));
    check((new IntegrationVmwareInventoryReader($other, $guard))->query()['has_inventory'] === false, 'credential/config binding invalidates old publication');
    $now += 400; $oldGeneration = $reader->query()['generation']; $p->partial = true;
    check($w->tick(20)['error'] === 'incomplete', 'partial collection rejected');
    check($reader->query()['generation'] === $oldGeneration, 'partial cannot imply deletion');
    $now += 2000; $p->partial = false; $p->optionalFails = true; $w->tick(20);
    $section = $reader->query('vm', 'vm-1')['data']['sections']['guest'];
    check($section['state'] === 'stale' && $section['observed_at'] === 1000 && $section['data']['memory_mb'] === 4096, 'optional transient error preserves dated last-good section');
    $now += 400; $p->optionalFails = false;
    $p->duringCollect = function () use ($p): void { $p->ids['vm'] = ['vm-1']; };
    check($w->tick(20)['error'] === 'membership_changed', 'concurrent inventory deletion prevents inconsistent publication');
    check($reader->query('vms')['total'] === 2, 'prior complete membership retained');
    $p->duringCollect = null;
    $now += 2000; $w->tick(20); check($reader->query('vms')['total'] === 1, 'a full new discovery allows confirmed removal');
    fails(fn() => $reader->query('vm', '../secret'), 'invalid_id');
    fails(fn() => $reader->query('vms', '', '', 1, 1000), 'invalid_query');
    fails(fn() => $s->write('current', []), 'worker_lock_required');
    fails(fn() => $s->read('../file'), 'slot_invalid');
    // A changed binding during collection cannot publish or silently unlock old data.
    $now += 400; $p->duringCollect = function () use (&$allowed): void { $allowed = false; };
    check($w->tick(20)['error'] === 'binding_changed', 'mid-unit configuration change blocks publication');
    $allowed = true; $p->duringCollect = null;
    fails(fn() => $reader->query('vms'), 'access_denied');
    $now += 2000; $w->tick(20);
    $now += 400; $w->tick(1); $now += 14401;
    check($w->tick(20)['error'] === 'generation_expired', 'abandoned checkpoint has a maximum collection window');
    check($reader->query('vms')['total'] === 1, 'expired pending generation does not delete published inventory');
    // A legitimately empty complete inventory is not initialization or a partial failure.
    $emptyProvider = new MirrorFixtureProvider(); $emptyProvider->ids = ['vm' => [], 'host' => [], 'datastore' => []];
    $emptyStore = new IntegrationVmwareInventoryStore(folder('empty-inventory'), $key, $binding);
    check((new IntegrationVmwareInventoryWorker($emptyStore, $emptyProvider, $guard, $clock))->tick(20)['state'] === 'ready', 'complete empty inventory published');
    $emptyResult = (new IntegrationVmwareInventoryReader($emptyStore, $guard, $clock))->query('vms');
    check($emptyResult['has_inventory'] === true && $emptyResult['total'] === 0, 'empty complete inventory distinct from no publication');
    // Lock contention is real flock across independently opened handles.
    $otherStore = new IntegrationVmwareInventoryStore($dir, $key, $binding);
    $busy = $s->exclusive(fn() => $otherStore->exclusive(static fn() => ['unexpected' => true]));
    check($busy['state'] === 'busy', 'concurrent workers coalesce');
    // An interrupted write cannot replace the current file; worker removes bounded orphan files.
    $orphan = $dir . '/.write-' . str_repeat('c', 24); file_put_contents($orphan, 'incomplete'); chmod($orphan, 0600);
    check($reader->query('vms')['total'] === 1, 'reader ignores interrupted temporary publication');
    $s->exclusive(static fn() => null); check(!file_exists($orphan), 'bounded orphan cleanup by worker only');
    // Real PHP child process reconstructs a reader with no provider implementation loaded.
    $readFile = __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryReader.php';
    $storeFile = __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php';
    $code = 'require ' . var_export(realpath($storeFile), true) . ';require ' . var_export(realpath($readFile), true) . ';' .
        '$s=new IntegrationVmwareInventoryStore(' . var_export($dir, true) . ',hex2bin(' . var_export(bin2hex($key), true) . '),' . var_export($binding, true) . ');' .
        'echo (new IntegrationVmwareInventoryReader($s,fn()=>true))->query("vms")["total"];';
    $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $childOut = stream_get_contents($pipes[1]); $childErr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($proc) === 0 && $childOut === '1' && $childErr === '', 'actual process restart can read persisted inventory');
    // Corruption and filesystem safety.
    $enc = $dir . '/current.enc'; $saved = file_get_contents($enc);
    file_put_contents($enc, substr($saved, 0, -1) . (substr($saved, -1) ^ "\x01"));
    fails(fn() => $s->read('current'), 'ciphertext_invalid'); file_put_contents($enc, $saved);
    chmod($enc, 0644); fails(fn() => $s->read('current'), 'file_invalid'); chmod($enc, 0600);
    $bad = folder('bad'); symlink($enc, $bad . '/current.enc');
    $bs = new IntegrationVmwareInventoryStore($bad, $key, $binding); fails(fn() => $bs->read('current'), 'path_invalid');
    $hard = folder('hard'); link($enc, $hard . '/current.enc');
    fails(fn() => $s->read('current'), 'file_invalid'); unlink($hard . '/current.enc');
    $small = new IntegrationVmwareInventoryStore(folder('small'), $key, $binding, 65536);
    fails(fn() => $small->exclusive(fn() => $small->write('current', ['x' => str_repeat('a', 70000)])), 'size_limit');
    $wrongKey = new IntegrationVmwareInventoryStore($dir, random_bytes(32), $binding); fails(fn() => $wrongKey->read('current'), 'ciphertext_invalid');
    $row = ['id' => 1, 'provider' => 'vmware_vcenter', 'enabled' => 1, 'verify_tls' => 1,
        'endpoint' => 'https://example.invalid', 'username' => 'worker', 'secret_envelope' => 'opaque-one', 'secret_key_id' => 'one'];
    $hash = IntegrationVmwareInventoryStore::binding($key, '/test', $row);
    $row['last_test_at'] = 'later'; check(IntegrationVmwareInventoryStore::binding($key, '/test', $row) === $hash, 'connection Test alone does not invalidate inventory');
    $row['secret_envelope'] = 'opaque-two'; check(IntegrationVmwareInventoryStore::binding($key, '/test', $row) !== $hash, 'credential envelope rotation invalidates binding');
    $row['verify_tls'] = 0; fails(fn() => IntegrationVmwareInventoryStore::binding($key, '/test', $row), 'connector_unavailable');
    echo 'PHASE5G_INVENTORY_ASSERTIONS=' . $n . "\nPHASE5G_LOCAL_ONLY_RESTART_ENCRYPTION_OK\n";
} finally { wipe($base); }
