<?php

/**
 * Phase 5D.4 VMware VM Tags/Categories read-only metadata regression.
 * Injected transport only; no real vCenter and no mutation.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterMetadata.php');

$passed = 0;
$failed = 0;

function meta_check($name, $condition)
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

function meta_same($name, $expected, $actual)
{
    meta_check($name, $expected === $actual);
}

function meta_expect_exception($name, $needle, $callback)
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
            print "  expected: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$source = file_get_contents($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterMetadata.php');
meta_check('source-read-vm-tags', strpos($source, 'read_vm_tags') !== false);
meta_check('source-readonly-list-attached-tags', strpos($source, 'action=list-attached-tags') !== false);
meta_check('source-no-attach-action', strpos($source, 'action=attach') === false);
meta_check('source-no-detach-action', strpos($source, 'action=detach') === false);
meta_check('source-no-patch-put', strpos($source, "'PATCH'") === false && strpos($source, "'PUT'") === false);
meta_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
meta_check('source-no-phpipam-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
meta_check('source-no-redirects', strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false);

$calls = array();
$transport = function ($method, $url, $headers, $username, $password, $verifyTls, $body) use (&$calls) {
    $calls[] = array(
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'username' => $username,
        'password' => $password,
        'verify_tls' => $verifyTls,
        'body' => $body
    );

    $path = (string)parse_url($url, PHP_URL_PATH);
    $query = (string)parse_url($url, PHP_URL_QUERY);

    if ($method === 'POST' && $path === '/api/session') {
        return array('status' => 201, 'body' => json_encode('metadata-session-token'));
    }
    if ($method === 'DELETE' && $path === '/api/session') {
        return array('status' => 204, 'body' => '');
    }
    if ($method === 'POST' && $path === '/api/cis/tagging/tag-association' && $query === 'action=list-attached-tags') {
        return array('status' => 200, 'body' => json_encode(array('tag-1', 'tag-2', 'tag-2')));
    }
    if ($method === 'GET' && $path === '/api/cis/tagging/tag/tag-1') {
        return array('status' => 200, 'body' => json_encode(array(
            'id' => 'tag-1',
            'category_id' => 'cat-env',
            'name' => 'Production',
            'description' => 'Production workload',
            'used_by' => array()
        )));
    }
    if ($method === 'GET' && $path === '/api/cis/tagging/tag/tag-2') {
        return array('status' => 200, 'body' => json_encode(array(
            'id' => 'tag-2',
            'category_id' => 'cat-env',
            'name' => 'Critical',
            'description' => '',
            'used_by' => array()
        )));
    }
    if ($method === 'GET' && $path === '/api/cis/tagging/category/cat-env') {
        return array('status' => 200, 'body' => json_encode(array(
            'id' => 'cat-env',
            'name' => 'Environment',
            'description' => 'Environment classification',
            'cardinality' => 'MULTIPLE',
            'associable_types' => array('VirtualMachine'),
            'used_by' => array()
        )));
    }
    return array('status' => 500, 'body' => '{}');
};

$client = new IntegrationVmwareVcenterMetadata($transport);
$result = $client->read_vm_tags('vcenter.example.local', 'svc-meta@example.local', 'meta-secret', 'vm-501', true);

meta_check('metadata-success', $result['ok'] === true);
meta_same('metadata-api-family', 'modern', $result['api_family']);
meta_same('metadata-vm-id', 'vm-501', $result['vm_external_id']);
meta_same('metadata-tag-count-deduplicated', 2, $result['tag_count']);
meta_same('metadata-category-count-cached', 1, $result['category_count']);
meta_same('first-tag-id', 'tag-1', $result['tags'][0]['external_id']);
meta_same('first-tag-name', 'Production', $result['tags'][0]['name']);
meta_same('first-tag-description', 'Production workload', $result['tags'][0]['description']);
meta_same('first-tag-category-id', 'cat-env', $result['tags'][0]['category_external_id']);
meta_same('category-name', 'Environment', $result['tags'][0]['category']['name']);
meta_same('category-cardinality', 'multiple', $result['tags'][0]['category']['cardinality']);
meta_same('empty-tag-description-allowed', '', $result['tags'][1]['description']);
meta_check('metadata-no-persistence', $result['persistent_inventory_written'] === false && $result['phpipam_objects_modified'] === false && $result['vcenter_objects_modified'] === false);
meta_same('metadata-call-count', 6, count($calls));
meta_check('metadata-session-cleanup-last', $calls[5]['method'] === 'DELETE');
meta_check('metadata-tls-propagated', array_reduce($calls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
meta_check('metadata-basic-auth-login-only', $calls[0]['username'] === 'svc-meta@example.local' && $calls[0]['password'] === 'meta-secret' && $calls[1]['username'] === null);

$associationPayload = json_decode($calls[1]['body'], true);
meta_same('association-method-post-readonly-action', 'POST', $calls[1]['method']);
meta_check('association-exact-endpoint', strpos($calls[1]['url'], '/api/cis/tagging/tag-association?action=list-attached-tags') !== false);
meta_same('association-object-type', 'VirtualMachine', $associationPayload['object_id']['type']);
meta_same('association-object-id', 'vm-501', $associationPayload['object_id']['id']);
meta_check('association-content-type-json', in_array('Content-Type: application/json', $calls[1]['headers'], true));

$serialized = json_encode($result);
meta_check('metadata-result-no-password', strpos($serialized, 'meta-secret') === false);
meta_check('metadata-result-no-username', strpos($serialized, 'svc-meta@example.local') === false);
meta_check('metadata-result-no-session-token', strpos($serialized, 'metadata-session-token') === false);

$permissionCalls = array();
$permissionClient = new IntegrationVmwareVcenterMetadata(function ($method, $url) use (&$permissionCalls) {
    $permissionCalls[] = $method;
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && $path === '/api/session') {
        return array('status' => 201, 'body' => json_encode('perm-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    return array('status' => 403, 'body' => '{}');
});
meta_expect_exception('tag-association-403-distinguished', 'tag associations', function () use ($permissionClient) {
    $permissionClient->read_vm_tags('vcenter.example.local', 'u', 'p', 'vm-1', true);
});
meta_check('tag-association-403-cleans-session', $permissionCalls === array('POST', 'POST', 'DELETE'));

$legacyClient = new IntegrationVmwareVcenterMetadata(function ($method, $url) {
    if ($method === 'POST' && (string)parse_url($url, PHP_URL_PATH) === '/api/session') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
});
meta_expect_exception('legacy-fails-closed', 'modern vCenter API', function () use ($legacyClient) {
    $legacyClient->read_vm_tags('legacy.example.local', 'u', 'p', 'vm-1', true);
});

$limitClient = new IntegrationVmwareVcenterMetadata(function ($method, $url) {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && $path === '/api/session') {
        return array('status' => 201, 'body' => json_encode('limit-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if ($path === '/api/cis/tagging/tag-association') {
        return array('status' => 200, 'body' => json_encode(array('tag-1', 'tag-2', 'tag-3')));
    }
    return array('status' => 500, 'body' => '{}');
}, 8, 20, 2097152, 2, 20);
meta_expect_exception('tag-limit-fails-closed', 'tag collection exceeded', function () use ($limitClient) {
    $limitClient->read_vm_tags('vcenter.example.local', 'u', 'p', 'vm-1', true);
});

$conflictClient = new IntegrationVmwareVcenterMetadata(function ($method, $url) {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($method === 'POST' && $path === '/api/session') {
        return array('status' => 201, 'body' => json_encode('conflict-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if ($path === '/api/cis/tagging/tag-association') {
        return array('status' => 200, 'body' => json_encode(array('tag-1')));
    }
    if ($path === '/api/cis/tagging/tag/tag-1') {
        return array('status' => 200, 'body' => json_encode(array('id' => 'tag-other', 'category_id' => 'cat', 'name' => 'X', 'description' => '')));
    }
    return array('status' => 500, 'body' => '{}');
});
meta_expect_exception('tag-id-conflict-rejected', 'conflicting tag identifier', function () use ($conflictClient) {
    $conflictClient->read_vm_tags('vcenter.example.local', 'u', 'p', 'vm-1', true);
});

print "\nPhase 5D.4 VM metadata regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
