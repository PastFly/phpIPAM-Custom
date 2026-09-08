<?php

declare(strict_types=1);

/**
 * Phase 4 Integrations framework regression checks.
 *
 * This suite intentionally does not connect to any external system. It locks
 * routing, authentication and the metadata-only/read-only connector contract
 * before the first concrete VMware/vCenter connector is introduced.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/integrations-framework-regression.php <phpipam-root>\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
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

function source(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("missing source: {$rel}");
    }
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException("failed to read: {$rel}");
    }
    return $data;
}

function require_text(string $name, string $source, string $needle): void
{
    if (strpos($source, $needle) !== false) {
        pass_case($name);
    } else {
        fail_case($name, "missing required text: {$needle}");
    }
}

function forbid_text(string $name, string $source, string $needle): void
{
    if (strpos($source, $needle) === false) {
        pass_case($name);
    } else {
        fail_case($name, "forbidden text present: {$needle}");
    }
}

try {
    $index = source($root, 'index.php');
    $sections = source($root, 'app/sections/index.php');
    $menuTools = source($root, 'app/sections/menu/menu-tools.php');
    $menuStatic = source($root, 'app/sections/menu/menu-static.php');
    $adminMenu = source($root, 'app/admin/admin-menu-config.php');
    $landing = source($root, 'app/integrations/index.php');
    $admin = source($root, 'app/admin/integrations/index.php');
    $registrySource = source($root, 'app/integrations/registry.php');
} catch (RuntimeException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(2);
}

require_text('route-marker', $index, 'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1');
require_text('route-page-condition', $index, '$GET->page=="integrations"');
require_text('route-landing-include', $index, 'include_once("app/integrations/index.php")');
require_text('dynamic-nav-context-marker', $sections, 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_CONTEXT_V1');
require_text('dynamic-nav-context-page', $sections, '$GET->page=="integrations"');
require_text('dynamic-nav-marker', $menuTools, 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1');
require_text('dynamic-nav-link', $menuTools, 'array("integrations", null)');
require_text('dynamic-nav-icon', $menuTools, '"icon"=>"fa-plug"');
require_text('static-nav-marker', $menuStatic, 'PHPIPAM_CUSTOM_INTEGRATIONS_STATIC_NAV_V1');
require_text('static-nav-link', $menuStatic, 'create_link("integrations")');
require_text('admin-menu-marker', $adminMenu, 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1');
require_text('admin-menu-route', $adminMenu, '"href"=>"integrations"');
require_text('admin-menu-inclusion', $adminMenu, '"integrations"           => _("integrations")');

require_text('registry-marker', $registrySource, 'PHPIPAM_CUSTOM_INTEGRATIONS_REGISTRY_V1');
require_text('landing-session-guard', $landing, '$User->check_user_session();');
require_text('landing-marker', $landing, 'PHPIPAM_CUSTOM_INTEGRATIONS_PAGE_V1');
require_text('landing-admin-settings-link', $landing, 'create_link("administration", "integrations")');
require_text('admin-session-guard', $admin, '$User->check_user_session();');
require_text('admin-admin-guard', $admin, 'if (!$User->is_admin(false)) {');
require_text('admin-marker', $admin, 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_V1');
forbid_text('landing-no-form', $landing, '<form');
forbid_text('admin-no-form', $admin, '<form');
forbid_text('landing-no-post', $landing, '$_POST');
forbid_text('admin-no-post', $admin, '$_POST');
forbid_text('landing-no-object-write', $landing, 'object_modify');
forbid_text('admin-no-object-write', $admin, 'object_modify');

$registryPath = $root . '/app/integrations/registry.php';
$registry = require $registryPath;

if (is_array($registry)) {
    pass_case('registry-is-array');
} else {
    fail_case('registry-is-array', 'registry must return an array');
    $registry = array();
}

$expectedIds = array('vmware-vcenter', 'hyper-v', 'active-directory', 'dns', 'dhcp');
if (array_keys($registry) === $expectedIds) {
    pass_case('registry-connector-set-and-order');
} else {
    fail_case('registry-connector-set-and-order', 'unexpected connector ids/order');
}

if (isset($registry['vmware-vcenter']) && $registry['vmware-vcenter']['state'] === 'next') {
    pass_case('vmware-is-next');
} else {
    fail_case('vmware-is-next', 'VMware vCenter must be the first implementation target');
}

$allReadOnly = true;
foreach ($registry as $connector) {
    if (($connector['mode'] ?? null) !== 'read-only') {
        $allReadOnly = false;
        break;
    }
}
if ($allReadOnly) {
    pass_case('registry-read-only-foundation');
} else {
    fail_case('registry-read-only-foundation', 'all foundation connectors must be read-only');
}

$forbiddenKeys = array('endpoint', 'username', 'password', 'token', 'secret', 'private_key', 'client_secret', 'bind_password', 'credential');
$secretKeyFound = null;
foreach ($registry as $id => $connector) {
    foreach ($forbiddenKeys as $key) {
        if (array_key_exists($key, $connector)) {
            $secretKeyFound = $id . '/' . $key;
            break 2;
        }
    }
}
if ($secretKeyFound === null) {
    pass_case('registry-has-no-credential-fields');
} else {
    fail_case('registry-has-no-credential-fields', 'forbidden key: ' . $secretKeyFound);
}

echo "\nIntegrations framework regression summary: {$passes} passed, {$failures} failed.\n";

exit($failures > 0 ? 1 : 0);
