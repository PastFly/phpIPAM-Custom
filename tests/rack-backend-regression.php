<?php

declare(strict_types=1);

/**
 * Phase 1 backend rack-placement regression tests.
 *
 * Executes the actual check_device_overflow() and check_device_overlap()
 * methods from the supplied phpIPAM tree inside a minimal deterministic
 * harness. No DB bootstrap is required.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/rack-backend-regression.php <phpipam-root>\n");
    exit(2);
}

$root = rtrim($argv[1], "/");
$rackspacePath = $root . "/functions/classes/class.Rackspace.php";

if (!is_file($rackspacePath)) {
    fwrite(STDERR, "ERROR: Rackspace source not found: {$rackspacePath}\n");
    exit(2);
}

$source = file_get_contents($rackspacePath);
if ($source === false) {
    fwrite(STDERR, "ERROR: failed to read Rackspace source\n");
    exit(2);
}

function extract_method(string $source, string $name): string
{
    $needle = "public function {$name}(";
    $start = strpos($source, $needle);
    if ($start === false) {
        $needle = "public function {$name} (";
        $start = strpos($source, $needle);
    }
    if ($start === false) {
        throw new RuntimeException("{$name}() method not found");
    }

    $braceStart = strpos($source, "{", $start);
    if ($braceStart === false) {
        throw new RuntimeException("{$name}() opening brace not found");
    }

    $depth = 0;
    $end = null;
    $len = strlen($source);
    for ($i = $braceStart; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === "{") {
            $depth++;
        } elseif ($ch === "}") {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    if ($end === null) {
        throw new RuntimeException("{$name}() closing brace not found");
    }

    return substr($source, $start, $end - $start + 1);
}

try {
    $overflowMethod = extract_method($source, 'check_device_overflow');
    $overlapMethod = extract_method($source, 'check_device_overlap');
} catch (RuntimeException $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(2);
}

$harnessSource = <<<'PHP'
class RackBackendTestHarness
{
    public array $racks = [];
    public array $devices = [];
    public array $contents = [];

    public function fetch_rack_details($id)
    {
        return $this->racks[(int)$id] ?? false;
    }

    public function fetch_rack_devices($id)
    {
        return $this->devices[(int)$id] ?? false;
    }

    public function fetch_rack_contents($id)
    {
        return $this->contents[(int)$id] ?? false;
    }

    __OVERFLOW_METHOD__

    __OVERLAP_METHOD__
}
PHP;

$harnessSource = str_replace('__OVERFLOW_METHOD__', $overflowMethod, $harnessSource);
$harnessSource = str_replace('__OVERLAP_METHOD__', $overlapMethod, $harnessSource);

eval($harnessSource);

$passes = 0;
$failures = 0;

function pass_case(string $name): void
{
    global $passes;
    $passes++;
    echo "PASS {$name}\n";
}

function fail_case(string $name, string $message): void
{
    global $failures;
    $failures++;
    echo "FAIL {$name}: {$message}\n";
}

function assert_same_bool(string $name, bool $expected, $actual, string $message): void
{
    if ((bool)$actual === $expected) {
        pass_case($name);
    } else {
        fail_case($name, $message . ' expected=' . ($expected ? 'true' : 'false') . ' actual=' . ((bool)$actual ? 'true' : 'false'));
    }
}

function rack_obj(int $id = 1, int $size = 10, bool $hasBack = true): object
{
    return (object) [
        'id' => $id,
        'size' => $size,
        'hasBack' => $hasBack ? 1 : 0,
    ];
}

function device_obj(int $id, int $rackStart, int $rackSize = 1, int $rackDeep = 0): object
{
    return (object) [
        'id' => $id,
        'rack_start' => $rackStart,
        'rack_size' => $rackSize,
        'rack_deep' => $rackDeep,
    ];
}

function content_obj(int $id, int $rackStart, int $rackSize = 1, int $rackDeep = 0): object
{
    return (object) [
        'id' => $id,
        'rack_start' => $rackStart,
        'rack_size' => $rackSize,
        'rack_deep' => $rackDeep,
    ];
}

function backend_harness(array $devices = [], array $contents = [], bool $hasBack = true, int $size = 10): RackBackendTestHarness
{
    $h = new RackBackendTestHarness();
    $h->racks[1] = rack_obj(1, $size, $hasBack);
    $h->devices[1] = $devices;
    $h->contents[1] = $contents;
    return $h;
}

// Overflow checks.
$h = backend_harness();
assert_same_bool('overflow-invalid-rack', true, $h->check_device_overflow(999, 1, 1), 'unknown rack must fail closed');
assert_same_bool('overflow-front-last-u-valid', false, $h->check_device_overflow(1, 10, 1), 'Front U10 1U must fit');
assert_same_bool('overflow-front-multi-u-edge', true, $h->check_device_overflow(1, 10, 2), '2U at Front U10 must overflow');
assert_same_bool('overflow-rear-first-u-valid', false, $h->check_device_overflow(1, 11, 1), 'Rear U1 must fit');
assert_same_bool('overflow-rear-last-u-valid', false, $h->check_device_overflow(1, 20, 1), 'Rear U10 must fit');
assert_same_bool('overflow-rear-multi-u-edge', true, $h->check_device_overflow(1, 20, 2), '2U at Rear U10 must overflow');
assert_same_bool('overflow-zero-start-rejected', true, $h->check_device_overflow(1, 0, 1), 'rack_start < 1 must fail');
$single = backend_harness([], [], false);
assert_same_bool('overflow-rear-on-single-sided-rejected', true, $single->check_device_overflow(1, 11, 1), 'Rear-encoded position must fail on a rack without Rear');

// Overlap checks.
$h = backend_harness();
assert_same_bool('overlap-invalid-rack', true, $h->check_device_overlap(999, 1, 1), 'unknown rack must fail closed');
assert_same_bool('overlap-empty-rack', false, $h->check_device_overlap(1, 4, 1), 'empty rack must accept a valid non-overlapping position');

$h = backend_harness([device_obj(10, 4, 1, 0)]);
assert_same_bool('overlap-front-1u', true, $h->check_device_overlap(1, 4, 1), 'same Front U must collide');
assert_same_bool('overlap-front-adjacent', false, $h->check_device_overlap(1, 5, 1), 'adjacent Front U must not collide');
assert_same_bool('overlap-candidate-multi-u', true, $h->check_device_overlap(1, 3, 2), '2U candidate at U3-U4 must collide with occupied U4');
assert_same_bool('overlap-front-rear-independent', false, $h->check_device_overlap(1, 14, 1), 'Rear U4 must remain independent from non-deep Front U4');
assert_same_bool('overlap-current-device-self-excluded', false, $h->check_device_overlap(1, 4, 1, 0, 10), 'editing current device must exclude itself');

$h = backend_harness([device_obj(11, 4, 1, 1)]);
assert_same_bool('overlap-existing-deep-front-blocks-rear', true, $h->check_device_overlap(1, 14, 1), 'Full Depth Front U4 must block Rear U4');

$h = backend_harness([device_obj(12, 14, 1, 1)]);
assert_same_bool('overlap-existing-deep-rear-blocks-front', true, $h->check_device_overlap(1, 4, 1), 'Full Depth Rear U4 must block Front U4');

$h = backend_harness([device_obj(13, 14, 1, 0)]);
assert_same_bool('overlap-deep-candidate-checks-opposite-side', true, $h->check_device_overlap(1, 4, 1, 1), 'Full Depth candidate on Front U4 must collide with Rear U4');

$h = backend_harness([], [content_obj(20, 6, 2, 0)]);
assert_same_bool('overlap-rack-content', true, $h->check_device_overlap(1, 7, 1), 'rack content U6-U7 must collide');
assert_same_bool('overlap-current-content-self-excluded', false, $h->check_device_overlap(1, 6, 2, 0, null, 20), 'editing current rack content must exclude itself');

// Security invariant: existing Full Depth rack content must reserve both physical sides.
// Upstream phpIPAM 1.8.2 checks rack_deep for devices but not rackContents here;
// phpIPAM Custom deliberately closes that backend/UI mismatch.
$h = backend_harness([], [content_obj(21, 6, 1, 1)]);
assert_same_bool('overlap-existing-deep-content-front-blocks-rear', true, $h->check_device_overlap(1, 16, 1), 'Full Depth rack content Front U6 must block Rear U6');

$h = backend_harness([], [content_obj(22, 16, 1, 1)]);
assert_same_bool('overlap-existing-deep-content-rear-blocks-front', true, $h->check_device_overlap(1, 6, 1), 'Full Depth rack content Rear U6 must block Front U6');

echo "\nRack backend regression summary: {$passes} passed, {$failures} failed.\n";

if ($failures > 0) {
    exit(1);
}

exit(0);
