<?php
require __DIR__ . '/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotCache.php';
function check($ok, $why) { if (!$ok) throw new RuntimeException('TEST: ' . $why); }
function fails(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { check($e->getMessage() === $message, 'expected ' . $message . ', got ' . $e->getMessage()); return; }
    throw new RuntimeException('TEST: missing failure ' . $message);
}
$base = sys_get_temp_dir() . '/phase5f34-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$key = str_repeat('k', 32); $time = 10000; $calls = 0;
$bind = fn($password = 'fixture-only', $tls = false, $app = '/test-app', $id = 1) => IntegrationVmwareSnapshotCache::binding($key, $app, $id, 'https://fixture.invalid', 'test-user', $password, $tls);
$make = function ($binding = null, $root = null) use ($base, $key, $bind, &$time) {
    return new IntegrationVmwareSnapshotCache($root ?? $base, $key, $binding ?? $bind(), function () use (&$time) { return $time; });
};
$load = function () use (&$calls) { $calls++; return ['ok' => true, 'rows' => [['name' => 'fixture VM', 'annotation' => 'Sensitive fixture notes']]]; };
try {
    $cache = $make();
    check(glob($base . '/*') === [], 'constructor has no writes');
    check($cache->inspect('core')['state'] === 'miss', 'empty');
    $first = $cache->get('core', $load);
    check($calls === 1 && $first['cache']['state'] === 'refreshed' && $first['cache']['cache_snapshot_written'], 'cold single read');
    $raw = file_get_contents($base . '/core.bin');
    check(strpos($raw, 'fixture VM') === false && strpos($raw, 'Sensitive fixture') === false && strpos($raw, 'fixture-only') === false, 'encrypted data');
    check((fileperms($base . '/core.bin') & 0777) === 0600, 'private snapshot');
    check((fileperms($base . '/refresh.lock') & 0777) === 0600, 'private lock');
    check($make()->get('core', $load)['payload'] === $first['payload'] && $calls === 1, 'cross-instance sharing');
    $time += 59; check($cache->get('core', $load)['cache']['state'] === 'fresh' && $calls === 1, 'fresh');
    $time++; $stale = $cache->get('core', $load);
    check($stale['cache']['state'] === 'stale' && $stale['cache']['refresh_required'] && $calls === 1, 'SWR no synchronous call');
    $cache->refresh('core', $load); check($calls === 2, 'explicit revalidation');
    $cache->refresh('core', $load, true); check($calls === 2, 'force coalesced in floor');
    $time += 5; $cache->refresh('core', $load, true); check($calls === 3, 'force beyond floor');
    $before = $calls; $time += 600; $cache->get('core', $load); check($calls === $before + 1, 'hard TTL reread');
    $time++; check($make($bind('rotated'))->inspect('core')['state'] === 'miss', 'credential invalidation');
    check($make($bind('fixture-only', true))->inspect('core')['state'] === 'miss', 'TLS invalidation');
    check($make($bind('fixture-only', false, '/other-app'))->inspect('core')['state'] === 'miss', 'app isolation');
    check($make($bind('fixture-only', false, '/test-app', 2))->inspect('core')['state'] === 'miss', 'connector isolation');
    check($cache->inspect('search')['state'] === 'miss', 'domain isolation');
    $time += 60;
    $bad = fn() => throw new RuntimeException('do-not-render: secret=TOKEN');
    $failed = $cache->refresh('core', $bad);
    check($failed['cache']['state'] === 'refresh_failed' && $failed['cache']['retry_after_seconds'] === 30, 'stale preserved with cooldown');
    $before = $calls; $cache->refresh('core', $load, true); check($calls === $before, 'force cannot bypass backoff');
    $time += 601; fails(fn() => $cache->get('core', $bad), 'snapshot_refresh_failed');
    fails(fn() => $cache->get('core', $load), 'snapshot_backoff');
    $time += 30; $cache->get('core', $load);
    $bytes = file_get_contents($base . '/core.bin');
    $time += 60; $partial = $cache->refresh('core', fn() => ['ok' => true, 'partial' => true]);
    check(file_get_contents($base . '/core.bin') === $bytes && $partial['cache']['state'] === 'refresh_failed', 'partial never replaces good');
    $time += 30;
    $secret = $cache->refresh('core', fn() => ['ok' => true, 'rows' => [['password' => 'do-not-store']]]);
    check(file_get_contents($base . '/core.bin') === $bytes && $secret['cache']['state'] === 'refresh_failed', 'structured credential rejection');
    $time += 30;
    $cache->get('search', $load);
    $denied = fn() => throw new IntegrationVmwareSnapshotAccessDenied('HTTP403 with sensitive upstream body');
    fails(fn() => $cache->refresh('core', $denied), 'snapshot_access_denied');
    check(!file_exists($base . '/core.bin') && !file_exists($base . '/search.bin'), 'revocation invalidates every domain');
    check($cache->inspect('search')['state'] === 'denied', 'denial marker');
    $saved = file_get_contents($base . '/blocked.bin');
    file_put_contents($base . '/blocked.bin', 'broken');
    fails(fn() => $cache->get('core', $load), 'snapshot_control_corrupt');
    file_put_contents($base . '/blocked.bin', $saved);
    fails(fn() => $cache->get('search', $load), 'snapshot_access_denied');
    $time += 30; $cache->get('core', $load); check($cache->inspect('core')['state'] === 'fresh', 'reauthentication recovers');
    check($cache->inspect('search')['state'] === 'miss', 'other domain not resurrected');
    $raw = file_get_contents($base . '/core.bin'); $raw[30] = chr(ord($raw[30]) ^ 1); file_put_contents($base . '/core.bin', $raw);
    check($cache->inspect('core')['state'] === 'miss', 'tamper detected'); $cache->get('core', $load);
    copy($base . '/core.bin', $base . '/search.bin'); chmod($base . '/search.bin', 0600);
    check($cache->inspect('search')['state'] === 'miss', 'encrypted domain binding');
    file_put_contents($base . '/search.bin', 'short'); check($cache->inspect('search')['state'] === 'miss', 'truncation');
    $now = $time; $time -= 100; check($cache->inspect('core')['state'] === 'miss', 'backward clock rejects future snapshot'); $time = $now;
    fails(fn() => $cache->get('../escape', $load), 'snapshot_domain_invalid');
    $alias = $base . '-alias'; symlink($base, $alias);
    fails(fn() => $make(null, $alias), 'snapshot_private_root_required'); unlink($alias);
    unlink($base . '/search.bin'); symlink($base . '/core.bin', $base . '/search.bin');
    fails(fn() => $cache->get('search', $load), 'snapshot_unsafe_file'); unlink($base . '/search.bin');
    link($base . '/core.bin', $base . '/search.bin');
    fails(fn() => $cache->get('search', $load), 'snapshot_unsafe_file'); unlink($base . '/search.bin');
    chmod($base . '/core.bin', 0644); fails(fn() => $cache->get('core', $load), 'snapshot_unsafe_file'); chmod($base . '/core.bin', 0600);
    chmod($base, 0755); fails(fn() => $make(), 'snapshot_private_root_required'); chmod($base, 0700);
    $lock = fopen($base . '/refresh.lock', 'r+b'); flock($lock, LOCK_EX | LOCK_NB);
    check($cache->get('core', $bad)['cache']['state'] === 'fresh', 'fresh reads during writer lock');
    $time += 60; check($cache->refresh('core', $load)['cache']['state'] === 'refreshing', 'stale lock nonblocking');
    fails(fn() => $cache->get('host_folders', $load), 'snapshot_refresh_busy');
    flock($lock, LOCK_UN); fclose($lock);
    $time += 1000;
    $slow = function () use (&$time) { $time += 601; throw new RuntimeException('slow secret'); };
    fails(fn() => $cache->get('core', $slow), 'snapshot_refresh_failed');
    $time += 30;
    $slowSuccess = function () use (&$time) { $time += 121; return ['ok' => true]; };
    fails(fn() => $cache->get('core', $slowSuccess), 'snapshot_refresh_deadline_exceeded');
    $oversize = fn() => ['ok' => true, 'text' => str_repeat('x', 8388608)];
    fails(fn() => $cache->get('core', $oversize), 'snapshot_refresh_failed');
    check(glob($base . '/tmp-*') === [], 'temporary files cleaned');
    echo "PHASE5F34_CACHE_UNIT_OK\n";

    if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl required for race tests');
    $race = $base . '/race'; mkdir($race, 0700);
    $worker = function () use ($race, $key, $bind) {
        $engine = new IntegrationVmwareSnapshotCache($race, $key, $bind());
        $loader = function () use ($race) {
            file_put_contents($race . '/factory-count', "1\n", FILE_APPEND | LOCK_EX);
            usleep(180000);
            return ['ok' => true, 'value' => 7];
        };
        for ($i = 0; $i < 80; $i++) {
            try { $r = $engine->get('core', $loader); check($r['payload']['value'] === 7, 'race result'); return; }
            catch (RuntimeException $e) { if ($e->getMessage() !== 'snapshot_refresh_busy') throw $e; usleep(20000); }
        }
        throw new RuntimeException('race timeout');
    };
    $pids = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) { try { $worker(); exit(0); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); } }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'race child success'); }
    check(count(file($race . '/factory-count')) === 1, 'eight concurrent readers one loader');
    echo "PHASE5F34_CACHE_CONCURRENT_READERS=8\nPHASE5F34_CACHE_FACTORY_CALLS=1\nPHASE5F34_CACHE_RACE_OK\n";
} finally {
    $delete = function ($path) use (&$delete) { if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $n) if ($n !== '.' && $n !== '..') $delete($path . '/' . $n); rmdir($path); } else unlink($path); };
    $delete($base);
}
