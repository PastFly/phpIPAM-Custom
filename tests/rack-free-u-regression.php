<?php

declare(strict_types=1);

/**
 * Phase 1 rack placement regression tests.
 *
 * This test executes the actual normalized phpIPAM `free_u()` method body
 * from the supplied application tree without bootstrapping the database.
 * It builds a minimal harness around that exact method so the placement
 * semantics can be tested deterministically in CI and in disposable trees.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/rack-free-u-regression.php <phpipam-root>\n");
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

$needle = "public function free_u(";
$start = strpos($source, $needle);
if ($start === false) {
    fwrite(STDERR, "ERROR: free_u() method not found\n");
    exit(2);
}

$braceStart = strpos($source, "{", $start);
if ($braceStart === false) {
    fwrite(STDERR, "ERROR: free_u() opening brace not found\n");
    exit(2);
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
    fwrite(STDERR, "ERROR: free_u() closing brace not found\n");
    exit(2);
}

$method = substr($source, $start, $end - $start + 1);

if (strpos($method, '$force_no_overlap = false') === false) {
    fwrite(STDERR, "ERROR: normalized force_no_overlap parameter is missing\n");
    exit(2);
}

#[AllowDynamicProperties]
class Params
{
    public function __construct($value = null)
    {
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $this->{$key} = $item;
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->{$key} = $item;
            }
        }
    }

    public function __get(string $name)
    {
        return null;
    }

    public function __isset(string $name): bool
    {
        return property_exists($this, $name) && isset($this->{$name});
    }
}

$harnessSource = <<<'PHP'
class RackFreeUTestHarness
{
    public $settings;

    __METHOD__
}
PHP;
$harnessSource = str_replace('__METHOD__', $method, $harnessSource);

eval($harnessSource);

$failures = 0;
$passes = 0;

function pass(string $name): void
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

function assert_true(string $name, bool $condition, string $message): void
{
    if ($condition) {
        pass($name);
    } else {
        fail_case($name, $message);
    }
}

function rack(bool $hasBack = true, int $size = 10): object
{
    return (object) [
        'size' => $size,
        'hasBack' => $hasBack ? 1 : 0,
    ];
}

function device(
    int $id,
    string $hostname,
    int $rackStart,
    int $rackSize = 1,
    int $rackDeep = 0
): object {
    return (object) [
        'id' => $id,
        'hostname' => $hostname,
        'rack_start' => $rackStart,
        'rack_size' => $rackSize,
        'rack_deep' => $rackDeep,
    ];
}

function content_item(
    int $id,
    string $name,
    int $rackStart,
    int $rackSize = 1,
    int $rackDeep = 0
): object {
    return (object) [
        'id' => $id,
        'name' => $name,
        'rack_start' => $rackStart,
        'rack_size' => $rackSize,
        'rack_deep' => $rackDeep,
    ];
}

function harness(string $rackAllowOverlap = '1'): RackFreeUTestHarness
{
    $h = new RackFreeUTestHarness();
    $h->settings = (object) ['rackAllowOverlap' => $rackAllowOverlap];
    return $h;
}

// 1. Legacy overlap setting remains respected when strict mode is not requested.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(1, 'srv-a', 4)],
    [],
    null,
    false
);
assert_true(
    'legacy-overlap-setting-without-force',
    isset($front[4]),
    'U4 should remain selectable when rackAllowOverlap=1 and force_no_overlap=false'
);

// 2. Strict mode must reserve occupied 1U positions regardless of global setting.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(1, 'srv-a', 4)],
    [],
    null,
    true
);
assert_true(
    'strict-front-1u-blocked',
    !isset($front[4]) && isset($front[3]) && isset($back[14]),
    'strict mode must block Front U4 only for a non-deep Front 1U device'
);

// 3. Existing multi-U device must reserve its entire occupied range.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(2, 'srv-b', 4, 2)],
    [],
    null,
    true
);
assert_true(
    'existing-multi-u-range',
    !isset($front[4]) && !isset($front[5]) && isset($front[3]) && isset($front[6]),
    'existing 2U device at U4-U5 must block both units'
);

// 4. A proposed 2U device must also exclude starts immediately before an occupied range.
$candidate = (object) [
    'rack_size' => 2,
    'rack_deep' => 0,
];
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(3, 'srv-c', 4, 2)],
    [],
    $candidate,
    true
);
assert_true(
    'proposed-multi-u-preceding-starts',
    !isset($front[3]) && !isset($front[4]) && !isset($front[5]) && isset($front[2]) && !isset($front[10]),
    '2U candidate must reject starts 3,4,5 around existing U4-U5 and must not start at rack U10'
);

// 5. Editing a device must not collide with itself.
$current = (object) [
    'id' => 7,
    'hostname' => 'srv-edit',
    'rack_start' => 4,
    'rack_size' => 2,
    'rack_deep' => 0,
];
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(7, 'srv-edit', 4, 2)],
    [],
    $current,
    true
);
assert_true(
    'self-exclusion-device-edit',
    isset($front[4]),
    'current device start U4 must remain available while editing itself'
);

// 6. Rear occupancy must not block Front when neither side is Full Depth.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(8, 'rear-only', 14, 1, 0)],
    [],
    null,
    true
);
assert_true(
    'front-rear-independent-occupancy',
    isset($front[4]) && !isset($back[14]),
    'Rear U4 must be blocked while Front U4 stays available'
);

// 7. Existing Full Depth item on Rear must reserve the same physical U on Front.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(9, 'rear-deep', 14, 1, 1)],
    [],
    null,
    true
);
assert_true(
    'existing-full-depth-blocks-both-sides',
    !isset($front[4]) && !isset($back[14]),
    'Full Depth Rear U4 must block both Front U4 and Rear U4'
);

// 8. Full Depth candidate on Front must treat Rear occupancy as a collision.
$deepCandidate = (object) [
    'rack_size' => 1,
    'rack_deep' => 1,
];
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(10, 'rear-normal', 14, 1, 0)],
    [],
    $deepCandidate,
    true
);
assert_true(
    'candidate-full-depth-checks-opposite-side',
    !isset($front[4]) && !isset($back[14]),
    'Full Depth candidate must not be placeable opposite an occupied Rear U4'
);

// 9. Normal Front device must leave corresponding Rear position available.
[$front, $back] = harness('1')->free_u(
    rack(),
    [device(11, 'front-normal', 4, 1, 0)],
    [],
    null,
    true
);
assert_true(
    'normal-front-does-not-block-rear',
    !isset($front[4]) && isset($back[14]),
    'non-deep Front U4 must not reserve Rear U4'
);

// 10. Rack without Rear side must never return Rear positions.
[$front, $back] = harness('1')->free_u(
    rack(false),
    [device(12, 'single-sided', 4, 1, 0)],
    [],
    null,
    true
);
assert_true(
    'single-sided-rack',
    !isset($front[4]) && count($back) === 0,
    'single-sided rack must block Front U4 and expose no Rear positions'
);

// 11. Rack content must participate in strict collision calculation.
[$front, $back] = harness('1')->free_u(
    rack(),
    [],
    [content_item(20, 'patch-panel', 6, 2, 0)],
    null,
    true
);
assert_true(
    'rack-content-blocks-space',
    !isset($front[6]) && !isset($front[7]) && isset($front[5]) && isset($front[8]),
    'rack content at U6-U7 must reserve its range'
);

// 12. Editing rack content must exclude the current content object itself.
$currentContent = (object) [
    'id' => 21,
    'name' => 'current-panel',
    'rack_start' => 6,
    'rack_size' => 2,
    'rack_deep' => 0,
];
[$front, $back] = harness('1')->free_u(
    rack(),
    [],
    [content_item(21, 'current-panel', 6, 2, 0)],
    $currentContent,
    true
);
assert_true(
    'self-exclusion-rack-content-edit',
    isset($front[6]),
    'current rack content start U6 must remain available while editing itself'
);

// 13. Legacy rackAllowOverlap=0 must still block positions without strict flag.
[$front, $back] = harness('0')->free_u(
    rack(),
    [device(30, 'legacy-strict', 8, 1, 0)],
    [],
    null,
    false
);
assert_true(
    'legacy-global-no-overlap-still-works',
    !isset($front[8]),
    'rackAllowOverlap=0 must preserve original non-overlap behavior'
);

// 14. Existing zero/invalid rack size is normalized to at least 1U by phpIPAM logic.
$badSize = device(31, 'bad-size', 9, 1, 0);
$badSize->rack_size = 0;
[$front, $back] = harness('1')->free_u(
    rack(),
    [$badSize],
    [],
    null,
    true
);
assert_true(
    'zero-size-existing-item-normalized-to-1u',
    !isset($front[9]),
    'zero rack_size existing item must still reserve one unit'
);

echo "\nRack free-U regression summary: {$passes} passed, {$failures} failed.\n";

if ($failures > 0) {
    exit(1);
}

exit(0);
