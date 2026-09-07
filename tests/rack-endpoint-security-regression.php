<?php

declare(strict_types=1);

/**
 * Phase 1 source-level regression checks for rack move endpoints.
 *
 * These checks intentionally focus on server-authoritative/stale-request
 * invariants that must remain true even if a browser submits crafted data.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/rack-endpoint-security-regression.php <phpipam-root>\n");
    exit(2);
}

$root = rtrim($argv[1], '/');

$files = [
    'single' => $root . '/app/tools/racks/rack-v3-move.php',
    'cross' => $root . '/app/tools/racks/rack-v3.3-move.php',
];

$sources = [];
foreach ($files as $key => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: endpoint not found: {$path}\n");
        exit(2);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "ERROR: failed to read endpoint: {$path}\n");
        exit(2);
    }
    $sources[$key] = $content;
}

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

function require_text(string $name, string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) !== false) {
        pass_case($name);
    } else {
        fail_case($name, $message);
    }
}

function forbid_text(string $name, string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        pass_case($name);
    } else {
        fail_case($name, $message);
    }
}

function require_order(string $name, string $source, string $first, string $second, string $message): void
{
    $a = strpos($source, $first);
    $b = strpos($source, $second);
    if ($a !== false && $b !== false && $a < $b) {
        pass_case($name);
    } else {
        fail_case($name, $message);
    }
}

$single = $sources['single'];
$cross = $sources['cross'];

foreach (['single' => $single, 'cross' => $cross] as $label => $src) {
    require_text("{$label}-session-check", $src, '$User->check_user_session();', 'session validation must be present');
    require_text("{$label}-racks-write-permission", $src, '$User->get_module_permissions("racks") < User::ACCESS_RW', 'Racks RW permission must be required');
    require_text("{$label}-devices-write-permission", $src, '$User->get_module_permissions("devices") < User::ACCESS_RW', 'Devices RW permission must be required');
    require_text("{$label}-maintenance-check", $src, '$User->check_maintaneance_mode();', 'maintenance-mode guard must be present');
    require_text("{$label}-action-validation", $src, '(string)$POST->action !== "move"', 'move action must be validated');
    require_text("{$label}-device-id-validation", $src, 'is_numeric($POST->deviceid)', 'device id must be validated');
    require_text("{$label}-position-validation", $src, 'is_numeric($POST->rack_start)', 'rack_start must be validated');
    require_text("{$label}-device-read-from-db", $src, '$Admin->fetch_object(', 'device must be re-read from authoritative backend state');
    require_text("{$label}-size-from-db", $src, '(int)$device->rack_size', 'rack size must be derived from stored device data');
    require_text("{$label}-depth-from-db", $src, '!empty($device->rack_deep)', 'Full Depth flag must be derived from stored device data');
    forbid_text("{$label}-no-posted-size", $src, '$POST->rack_size', 'client-supplied rack_size must not be trusted');
    forbid_text("{$label}-no-posted-depth", $src, '$POST->rack_deep', 'client-supplied rack_deep must not be trusted');
    require_text("{$label}-overflow-check", $src, '$Racks->check_device_overflow(', 'server-side overflow validation must be present');
    require_text("{$label}-overlap-check", $src, '$Racks->check_device_overlap(', 'server-side overlap validation must be present');
    require_order("{$label}-overflow-before-write", $src, '$Racks->check_device_overflow(', '$Admin->object_modify(', 'overflow validation must happen before DB write');
    require_order("{$label}-overlap-before-write", $src, '$Racks->check_device_overlap(', '$Admin->object_modify(', 'overlap validation must happen before DB write');
    require_order("{$label}-csrf-before-write", $src, 'csrf_cookie(', '$Admin->object_modify(', 'CSRF validation must happen before DB write');
}

// Single-rack endpoint must reject a stale page if the device was moved elsewhere.
require_text(
    'single-stale-source-rack-rejected',
    $single,
    '(int)$device->rack !== $rack_id',
    'single-rack move must reject a stale source-rack assumption'
);
require_text(
    'single-csrf-scoped-to-rack',
    $single,
    '"rack_v31_move_" . $rack_id',
    'single-rack CSRF token must be scoped to rack id'
);

// Cross-rack endpoint must derive source rack from current DB state and enforce
// same-Location semantics before write.
require_text(
    'cross-source-rack-from-current-device',
    $cross,
    '$Racks->fetch_rack_details(\n        (int)$device->rack',
    'cross-rack source rack must come from the current stored device rack'
);
require_text(
    'cross-same-location-guard',
    $cross,
    '$source_location !==\n    $target_location',
    'cross-rack move must reject source/target Location mismatch'
);
require_text(
    'cross-target-location-matches-page-context',
    $cross,
    '$target_location !==\n    $location_id',
    'target rack must match the Location context used for the request'
);
require_text(
    'cross-rear-capability-guard',
    $cross,
    '(int)$target_rack->hasBack !== 1',
    'Rear-encoded placement must be rejected when target rack has no Rear side'
);
require_text(
    'cross-csrf-scoped-to-location',
    $cross,
    '"rack_v33_cross_move_" . $location_id',
    'cross-rack CSRF token must be scoped to Location context'
);
require_order(
    'cross-location-check-before-write',
    $cross,
    '$source_location !==',
    '$Admin->object_modify(',
    'Location authorization invariant must be checked before DB write'
);

// Both endpoints must write only the authoritative device id and placement
// fields; size/depth are validation inputs, never client-controlled updates.
require_text(
    'single-write-only-rack-start',
    $single,
    '"rack_start" => $new_start',
    'single-rack endpoint must persist the new rack_start'
);
require_text(
    'cross-write-target-rack',
    $cross,
    '"rack" => $target_rack_id',
    'cross-rack endpoint must persist the validated target rack'
);
require_text(
    'cross-write-rack-start',
    $cross,
    '"rack_start" => $new_start',
    'cross-rack endpoint must persist the validated rack_start'
);

echo "\nRack endpoint security regression summary: {$passes} passed, {$failures} failed.\n";

if ($failures > 0) {
    exit(1);
}

exit(0);
