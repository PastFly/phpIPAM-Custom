<?php

declare(strict_types=1);

/**
 * Phase 1 lightweight drag/drop source regressions.
 *
 * No browser stack is introduced here. The goal is to lock the architectural
 * invariants that fixed the observed embedded-SVG bugs: parent-page drag
 * capture, U-based source selection, explicit Front/Rear contexts, live rack
 * data for cross-rack moves, and server-side save endpoints.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/rack-dnd-source-regression.php <phpipam-root>\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
$paths = [
    'singleController' => $root . '/app/tools/racks/rack-v3.1-controller.php',
    'singlePage' => $root . '/app/tools/racks/print-single-rack.php',
    'crossController' => $root . '/app/tools/racks/rack-v3.3-map-controller.php',
    'crossPage' => $root . '/app/tools/racks/print-racks-map.php',
];

$src = [];
foreach ($paths as $key => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "ERROR: required source not found: {$path}\n");
        exit(2);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "ERROR: failed to read: {$path}\n");
        exit(2);
    }
    $src[$key] = $content;
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

function compact_source(string $source): string
{
    return preg_replace('/\s+/', '', $source) ?? $source;
}

function require_text(string $name, string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) !== false) {
        pass_case($name);
    } else {
        fail_case($name, $message);
    }
}

function require_compact(string $name, string $source, string $needle, string $message): void
{
    if (strpos(compact_source($source), compact_source($needle)) !== false) {
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

$single = $src['singleController'];
$singlePage = $src['singlePage'];
$cross = $src['crossController'];
$crossPage = $src['crossPage'];

// Single-rack: both embedded SVGs must be exposed to one parent-page drag layer.
require_text('single-v314-marker', $single, 'RACK_V314_PARENT_FRONT_REAR', 'V3.1.4 parent Front/Rear controller marker missing');
require_text('single-native-svg-selector', $single, 'object.rack-v31-svg', 'controller must bind native rack SVG objects');
require_text('single-parent-drag-layer', $single, 'rackV31DragLayer', 'parent drag layer missing');
require_compact('single-drag-layer-fixed', $single, 'dragLayer.style.position = "fixed"', 'drag layer must cover parent viewport');
require_compact('single-drag-layer-on-parent-body', $single, 'document.body.appendChild(dragLayer)', 'drag layer must live in the parent page, not inside one SVG');
require_text('single-u-source-hit-test', $single, 'function deviceAtUnit(', 'source device must be resolved from occupied U data');
require_text('single-nearest-u-hit-test', $single, 'function nearestUnit(', 'U label hit-testing helper missing');
require_text('single-cross-object-target-detection', $single, 'function contextFromParentPoint(', 'target Front/Rear object must be resolved in parent coordinates');
require_compact('single-rear-db-encoding', $single, 'context.side === "back" ? rackSize + start : start', 'Rear target must be encoded as rackSize + physical U');
require_text('single-save-endpoint', $single, 'app/tools/racks/rack-v3-move.php', 'single-rack save endpoint wiring missing');
require_compact('single-save-device-id', $single, 'deviceid: item.id', 'single-rack save must submit selected device id');
require_compact('single-save-db-start', $single, 'rack_start: candidate.dbStart', 'single-rack save must submit encoded validated target U');
require_compact('single-save-csrf', $single, 'csrf_cookie: csrf', 'single-rack save must submit CSRF token');
forbid_text('single-no-href-prefix-source-selection', $single, 'href.indexOf("/devices/"', 'fragile URL-prefix source selection must not return');
forbid_text('single-no-svg-device-id-dependency', $single, 'data-device-id', 'single-rack source selection must not depend on injected SVG device ids');

require_text('single-page-front-object', $singlePage, 'data-rack-side="front"', 'single rack page must label Front SVG object');
require_text('single-page-back-object', $singlePage, 'data-rack-side="back"', 'single rack page must label Rear SVG object');
require_text('single-page-controller-include', $singlePage, 'include("rack-v3.1-controller.php")', 'single rack controller include missing');

// Cross-rack: visible rack objects provide ids; authoritative rack/device data is
// fetched live before drag starts; a parent-page layer bridges embedded SVGs.
require_text('cross-v335-marker', $cross, 'RACK_V335_LIVE_RACK_DATA', 'V3.3.5 live-data controller marker missing');
require_text('cross-native-svg-selector', $cross, 'object.rack-v33-svg', 'cross-rack controller must bind native rack SVG objects');
require_text('cross-visible-rack-id-read', $cross, 'getAttribute("data-rack-id")', 'visible rack ids must come from actual DOM objects');
require_text('cross-live-data-endpoint', $cross, 'app/tools/racks/rack-v3.3-data.php', 'cross-rack controller must fetch authoritative live rack data');
require_compact('cross-visible-rack-id-request', $cross, 'rack_ids: visibleRackIds.join(",")', 'live-data request must contain exact visible rack ids');
require_text('cross-parent-drag-layer', $cross, 'rackV33DragLayer', 'cross-rack parent drag layer missing');
require_compact('cross-drag-layer-fixed', $cross, 'dragLayer.style.position = "fixed"', 'cross-rack drag layer must cover parent viewport');
require_compact('cross-drag-layer-on-parent-body', $cross, 'document.body.appendChild(dragLayer)', 'cross-rack drag layer must live in parent page');
require_text('cross-u-source-hit-test', $cross, 'function deviceAtUnit(', 'cross-rack source device must be resolved from occupied U data');
require_text('cross-target-context-parent-hit-test', $cross, 'function contextFromParentPoint(', 'cross-rack target must be resolved from parent coordinates');
require_compact('cross-rear-db-encoding', $cross, 'context.side === "back" ? rack.size + start : start', 'cross-rack Rear target must be encoded as rack size + physical U');
require_text('cross-save-endpoint', $cross, 'app/tools/racks/rack-v3.3-move.php', 'cross-rack save endpoint wiring missing');
require_compact('cross-save-location', $cross, 'location_id: locationId', 'cross-rack save must include validated Location context');
require_compact('cross-save-target-rack', $cross, 'target_rackid: targetRack.id', 'cross-rack save must include selected target rack');
require_compact('cross-save-device-id', $cross, 'deviceid: item.id', 'cross-rack save must include selected device id');
require_compact('cross-save-db-start', $cross, 'rack_start: candidate.dbStart', 'cross-rack save must submit encoded validated target U');
require_compact('cross-save-csrf', $cross, 'csrf_cookie: csrf', 'cross-rack save must submit server-provided CSRF token');
forbid_text('cross-no-href-prefix-source-selection', $cross, 'href.indexOf("/devices/"', 'fragile URL-prefix source selection must not return');

require_text('cross-page-front-rack-id', $crossPage, "class='rack-v33-svg' data-rack-id='", 'rack scheme Front SVGs must expose rack ids');
require_text('cross-page-front-side', $crossPage, "data-rack-side='front'", 'rack scheme must label Front objects');
require_text('cross-page-back-side', $crossPage, "data-rack-side='back'", 'rack scheme must label Rear objects');
require_text('cross-page-controller-include', $crossPage, 'include("rack-v3.3-map-controller.php")', 'cross-rack controller include missing');

echo "\nRack DnD source regression summary: {$passes} passed, {$failures} failed.\n";

if ($failures > 0) {
    exit(1);
}

exit(0);
