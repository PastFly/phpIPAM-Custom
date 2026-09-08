<?php

/**
 * Phase 5A VMware vCenter connector regression suite.
 *
 * Uses an injected fake transport: CI never needs a real vCenter or credentials.
 */

$target = isset($argv[1]) ? rtrim($argv[1], "/") : null;
if ($target === null || $target === "") {
    fwrite(STDERR, "Usage: php tests/vmware-vcenter-connector-regression.php <phpipam-root>\n");
    exit(2);
}

$paths = array(
    "class" => $target . "/functions/classes/class.IntegrationVmwareVcenter.php",
    "admin" => $target . "/app/admin/integrations/index.php",
    "endpoint" => $target . "/app/admin/integrations/vmware-test-result.php",
    "registry" => $target . "/app/integrations/registry.php"
);

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Phase 5A file ($name): $path\n");
        exit(3);
    }
}

require_once($paths["class"]);

$passed = 0;
$failed = 0;

function check_true($name, $condition)
{
    global $passed, $failed;
    if ($condition) {
        print "PASS $name\n";
        $passed++;
    } else {
        print "FAIL $name\n";
        $failed++;
    }
}

function check_same($name, $expected, $actual)
{
    check_true($name, $expected === $actual);
}

function expect_exception_contains($name, $needle, $callback)
{
    global $passed, $failed;
    try {
        $callback();
        print "FAIL $name\n";
        $failed++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), $needle) !== false) {
            print "PASS $name\n";
            $passed++;
        } else {
            print "FAIL $name\n";
            print "  expected exception containing: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$admin = file_get_contents($paths["admin"]);
$endpoint = file_get_contents($paths["endpoint"]);
$registry = file_get_contents($paths["registry"]);
$classSource = file_get_contents($paths["class"]);

check_true("admin-phase5-marker", strpos($admin, "PHPIPAM_CUSTOM_VMWARE_VCENTER_TEST_UI_V1") !== false);
check_true("admin-csrf-created", strpos($admin, 'csrf_cookie("create", "integration_vmware_vcenter_test")') !== false);
check_true("admin-tls-default-checked", strpos($admin, 'name="verify_tls" value="1" checked') !== false);
check_true("admin-password-new-password-autocomplete", strpos($admin, 'autocomplete="new-password"') !== false);
check_true("admin-password-cleared-after-request", strpos($admin, "$('#vmware-vcenter-password').val('');") !== false);
check_true("admin-posts-only-to-test-endpoint", strpos($admin, "app/admin/integrations/vmware-test-result.php") !== false);
check_true("endpoint-session-bootstrap", strpos($endpoint, "functions/functions.php") !== false);
check_true("endpoint-admin-guard", strpos($endpoint, '!$User->is_admin(false)') !== false);
check_true("endpoint-csrf-validation", strpos($endpoint, 'csrf_cookie("validate", "integration_vmware_vcenter_test"') !== false);
check_true("endpoint-no-db-object-write", strpos($endpoint, "object_modify") === false);
check_true("endpoint-no-secret-logging", strpos($endpoint, "error_log") === false && strpos($endpoint, "var_dump") === false && strpos($endpoint, "print_r") === false);
check_true("registry-phase5-marker", strpos($registry, "PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1") !== false);
check_true("registry-read-only", strpos($registry, '"mode" => "read-only"') !== false);
check_true("client-no-follow-redirects", strpos($classSource, "CURLOPT_FOLLOWLOCATION => false") !== false);
check_true("client-https-protocol-guard", strpos($classSource, "CURLPROTO_HTTPS") !== false);
check_true("client-response-size-limit", strpos($classSource, "maxResponseBytes") !== false);
check_true("client-no-persistence-call", strpos($classSource, "object_modify") === false && strpos($classSource, "INSERT INTO") === false && strpos($classSource, "UPDATE ") === false);

$validationClient = new IntegrationVmwareVcenter(function () {
    throw new RuntimeException("transport should not be called in endpoint validation tests");
});

check_same("normalize-bare-host", "https://vcenter.example.local", $validationClient->normalize_endpoint("vcenter.example.local"));
check_same("normalize-explicit-port", "https://vcenter.example.local:8443", $validationClient->normalize_endpoint("https://vcenter.example.local:8443/"));
expect_exception_contains("reject-http", "must use HTTPS", function () use ($validationClient) {
    $validationClient->normalize_endpoint("http://vcenter.example.local");
});
expect_exception_contains("reject-ui-path", "base URL", function () use ($validationClient) {
    $validationClient->normalize_endpoint("https://vcenter.example.local/ui");
});
expect_exception_contains("reject-url-userinfo", "must not be embedded", function () use ($validationClient) {
    $validationClient->normalize_endpoint("https://user:pass@vcenter.example.local");
});
expect_exception_contains("reject-localhost", "localhost", function () use ($validationClient) {
    $validationClient->normalize_endpoint("https://localhost");
});
expect_exception_contains("reject-loopback-ip", "Loopback", function () use ($validationClient) {
    $validationClient->normalize_endpoint("https://127.0.0.1");
});
expect_exception_contains("reject-link-local-ip", "link-local", function () use ($validationClient) {
    $validationClient->normalize_endpoint("https://169.254.169.254");
});

$modernCalls = array();
$modernTransport = function ($method, $url, $headers, $username, $password, $verifyTls, $connectTimeout, $requestTimeout) use (&$modernCalls) {
    $modernCalls[] = array(
        "method" => $method,
        "url" => $url,
        "headers" => $headers,
        "username" => $username,
        "password" => $password,
        "verify_tls" => $verifyTls
    );

    if ($method === "POST" && substr($url, -12) === "/api/session") {
        return array("status" => 201, "body" => json_encode("token-modern"), "duration_ms" => 11);
    }
    if ($method === "GET" && substr($url, -23) === "/api/vcenter/datacenter") {
        return array("status" => 200, "body" => json_encode(array(array("datacenter" => "dc-1"), array("datacenter" => "dc-2"))), "duration_ms" => 13);
    }
    if ($method === "DELETE" && substr($url, -12) === "/api/session") {
        return array("status" => 204, "body" => "", "duration_ms" => 4);
    }
    return array("status" => 500, "body" => "", "duration_ms" => 1);
};

$modernClient = new IntegrationVmwareVcenter($modernTransport);
$modern = $modernClient->test_connection("vcenter.example.local", "svc-vcenter@example.local", "secret-modern", true);
check_true("modern-success", $modern["ok"] === true);
check_same("modern-api-family", "modern", $modern["api_family"]);
check_same("modern-datacenter-count", 2, $modern["datacenter_count"]);
check_same("modern-call-count", 3, count($modernCalls));
check_true("modern-call-order", $modernCalls[0]["method"] === "POST" && $modernCalls[1]["method"] === "GET" && $modernCalls[2]["method"] === "DELETE");
check_true("modern-basic-creds-login-only", $modernCalls[0]["username"] === "svc-vcenter@example.local" && $modernCalls[0]["password"] === "secret-modern" && $modernCalls[1]["username"] === null && $modernCalls[1]["password"] === null && $modernCalls[2]["username"] === null && $modernCalls[2]["password"] === null);
check_true("modern-session-header-inventory", in_array("vmware-api-session-id: token-modern", $modernCalls[1]["headers"], true));
check_true("modern-session-header-logout", in_array("vmware-api-session-id: token-modern", $modernCalls[2]["headers"], true));
check_true("modern-tls-propagated", $modernCalls[0]["verify_tls"] === true && $modernCalls[1]["verify_tls"] === true && $modernCalls[2]["verify_tls"] === true);
$modernSerialized = json_encode($modern);
check_true("modern-result-no-password", strpos($modernSerialized, "secret-modern") === false);
check_true("modern-result-no-username", strpos($modernSerialized, "svc-vcenter@example.local") === false);
check_true("modern-result-no-session-token", strpos($modernSerialized, "token-modern") === false);

$legacyCalls = array();
$legacyTransport = function ($method, $url, $headers, $username, $password, $verifyTls) use (&$legacyCalls) {
    $legacyCalls[] = array("method" => $method, "url" => $url, "headers" => $headers, "verify_tls" => $verifyTls);

    if ($method === "POST" && substr($url, -12) === "/api/session") {
        return array("status" => 404, "body" => "{}");
    }
    if ($method === "POST" && substr($url, -28) === "/rest/com/vmware/cis/session") {
        return array("status" => 200, "body" => json_encode(array("value" => "token-legacy")));
    }
    if ($method === "GET" && substr($url, -24) === "/rest/vcenter/datacenter") {
        return array("status" => 200, "body" => json_encode(array("value" => array(array("datacenter" => "dc-old")))));
    }
    if ($method === "DELETE" && substr($url, -28) === "/rest/com/vmware/cis/session") {
        return array("status" => 200, "body" => "{}");
    }
    return array("status" => 500, "body" => "");
};

$legacyClient = new IntegrationVmwareVcenter($legacyTransport);
$legacy = $legacyClient->test_connection("https://legacy-vcenter.example.local", "legacy-user", "legacy-secret", true);
check_same("legacy-api-family", "legacy", $legacy["api_family"]);
check_same("legacy-datacenter-count", 1, $legacy["datacenter_count"]);
check_same("legacy-call-count", 4, count($legacyCalls));
check_true("legacy-fallback-order", strpos($legacyCalls[0]["url"], "/api/session") !== false && strpos($legacyCalls[1]["url"], "/rest/com/vmware/cis/session") !== false && strpos($legacyCalls[2]["url"], "/rest/vcenter/datacenter") !== false && $legacyCalls[3]["method"] === "DELETE");
check_true("legacy-session-header", in_array("vmware-api-session-id: token-legacy", $legacyCalls[2]["headers"], true));

$authFailureClient = new IntegrationVmwareVcenter(function () {
    return array("status" => 401, "body" => "{}");
});
expect_exception_contains("auth-401-rejected", "HTTP 401", function () use ($authFailureClient) {
    $authFailureClient->test_connection("vcenter.example.local", "bad-user", "bad-pass", true);
});

$permissionCalls = array();
$permissionClient = new IntegrationVmwareVcenter(function ($method, $url, $headers) use (&$permissionCalls) {
    $permissionCalls[] = array("method" => $method, "url" => $url);
    if ($method === "POST") {
        return array("status" => 201, "body" => json_encode("token-permission"));
    }
    if ($method === "GET") {
        return array("status" => 403, "body" => "{}");
    }
    return array("status" => 204, "body" => "");
});
expect_exception_contains("inventory-403-distinguished", "lacks permission", function () use ($permissionClient) {
    $permissionClient->test_connection("vcenter.example.local", "limited-user", "limited-pass", true);
});
check_true("inventory-failure-still-logs-out", count($permissionCalls) === 3 && $permissionCalls[2]["method"] === "DELETE");

$tlsCalls = array();
$tlsOffClient = new IntegrationVmwareVcenter(function ($method, $url, $headers, $username, $password, $verifyTls) use (&$tlsCalls) {
    $tlsCalls[] = $verifyTls;
    if ($method === "POST") {
        return array("status" => 201, "body" => json_encode("token-tls-off"));
    }
    if ($method === "GET") {
        return array("status" => 200, "body" => json_encode(array()));
    }
    return array("status" => 204, "body" => "");
});
$tlsOff = $tlsOffClient->test_connection("vcenter.example.local", "user", "pass", false);
check_true("tls-off-explicit-result", $tlsOff["verify_tls"] === false);
check_true("tls-off-propagated-all-requests", $tlsCalls === array(false, false, false));

print "\nVMware vCenter connector regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
