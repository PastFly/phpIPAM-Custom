<?php

/**
 * phpIPAM Custom — Phase 5C live read-only inventory preview.
 *
 * Runs from the repository checkout and uses the already-deployed Phase 5B
 * encrypted connector configuration from a live phpIPAM tree. It performs no
 * database writes and persists no inventory. Output is summary-only by default.
 *
 * Usage:
 *   php tools/live-vmware-phase5c-preview.php /var/www/html [connector-id]
 *       [--guest-limit=N] [--allow-insecure-tls]
 *
 * If connector-id is omitted, exactly one enabled VMware connector must exist;
 * otherwise the preview fails closed and requires an explicit id.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5C preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$guestLimit = 0;
$allowInsecureTls = false;

for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if ($arg === '--allow-insecure-tls') {
        $allowInsecureTls = true;
        continue;
    }
    if (strpos($arg, '--guest-limit=') === 0) {
        $raw = substr($arg, strlen('--guest-limit='));
        if (!preg_match('/^[0-9]+$/', $raw)) {
            fwrite(STDERR, "ERROR: --guest-limit must be a non-negative integer.\n");
            exit(2);
        }
        $guestLimit = (int)$raw;
        if ($guestLimit > 250) {
            fwrite(STDERR, "ERROR: --guest-limit may not exceed 250 in live preview.\n");
            exit(2);
        }
        continue;
    }
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) {
        $connectorId = (int)$arg;
        continue;
    }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: " . $arg . "\n");
    exit(2);
}

if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n");
    exit(2);
}

function phase5c_preview_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') {
        return;
    }
    if (function_exists('sodium_memzero')) {
        @sodium_memzero($value);
    } else {
        $value = str_repeat("\0", strlen($value));
    }
}

function phase5c_preview_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) {
        return 'tls';
    }
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
        return 'timeout';
    }
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) {
        return 'authentication';
    }
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) {
        return 'authorization';
    }
    if (strpos($message, 'network') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) {
        return 'network';
    }
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false) {
        return 'invalid_response';
    }
    if (strpos($message, 'connector') !== false || strpos($message, 'credential') !== false || $error instanceof InvalidArgumentException) {
        return 'configuration';
    }
    return 'unknown';
}

function phase5c_preview_count_vm_statuses(array $vms)
{
    $counts = array();
    foreach ($vms as $vm) {
        $status = isset($vm['status']) ? (string)$vm['status'] : 'unknown';
        if (!isset($counts[$status])) {
            $counts[$status] = 0;
        }
        $counts[$status]++;
    }
    ksort($counts);
    return $counts;
}

function phase5c_preview_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) {
        if (!empty($config['enabled'])) {
            $enabled[] = $config;
        }
    }

    if (count($enabled) === 0) {
        fwrite(STDERR, "PREVIEW_BLOCKED=NO_ENABLED_CONNECTOR\n");
        fwrite(STDERR, "No enabled VMware connector exists. Enable one connector or provide an explicit connector id after reviewing its state.\n");
        exit(3);
    }
    if (count($enabled) > 1) {
        fwrite(STDERR, "PREVIEW_BLOCKED=AMBIGUOUS_CONNECTOR_SELECTION\n");
        fwrite(STDERR, "Multiple enabled VMware connectors exist. Re-run with an explicit connector id.\n");
        exit(3);
    }

    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
        throw new RuntimeException('The enabled VMware connector has an invalid id');
    }

    return (int)$id;
}

$runtime = null;
$originalCwd = getcwd();
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($originalCwd !== false) {
        chdir($originalCwd);
    }

    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');
    require_once($repoRoot . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php');
    require_once($repoRoot . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);

    if ($connectorId === null) {
        $connectorId = phase5c_preview_autoselect_connector($Store);
        fwrite(STDERR, "PREVIEW_CONNECTOR_AUTOSELECTED=" . $connectorId . "\n");
    }

    $runtime = $Store->load_runtime_config($connectorId, true);

    if (empty($runtime['verify_tls']) && !$allowInsecureTls) {
        fwrite(STDERR, "PREVIEW_BLOCKED=INSECURE_TLS\n");
        fwrite(STDERR, "The saved connector has TLS verification disabled. Re-run only with explicit --allow-insecure-tls for a manual preview. Scheduled sync remains forbidden.\n");
        phase5c_preview_zero_secret($runtime['password']);
        exit(3);
    }

    $Inventory = new IntegrationVmwareVcenterInventory();
    $core = $Inventory->read_core_inventory(
        $runtime['endpoint'],
        $runtime['username'],
        $runtime['password'],
        $runtime['verify_tls']
    );

    $summary = array(
        'result' => 'PHASE5C_LIVE_PREVIEW_OK',
        'connector_id' => $connectorId,
        'api_family' => (string)$core['api_family'],
        'verify_tls' => !empty($core['verify_tls']),
        'datacenter_count' => (int)$core['datacenter_count'],
        'cluster_count' => (int)$core['cluster_count'],
        'host_count' => (int)$core['host_count'],
        'vm_count' => (int)$core['vm_count'],
        'guest_vm_requested' => 0,
        'guest_interface_count' => 0,
        'guest_ip_count' => 0,
        'guest_status_counts' => array(),
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );

    if ($guestLimit > 0 && count($core['vms']) > 0) {
        $vmIds = array();
        foreach ($core['vms'] as $vm) {
            if (count($vmIds) >= $guestLimit) {
                break;
            }
            $vmIds[] = $vm['external_id'];
        }
        if (count($vmIds) > 0) {
            $Guest = new IntegrationVmwareVcenterGuestNetwork();
            $guest = $Guest->read_vm_interfaces(
                $runtime['endpoint'],
                $runtime['username'],
                $runtime['password'],
                $vmIds,
                $runtime['verify_tls']
            );
            $summary['guest_vm_requested'] = count($vmIds);
            $summary['guest_interface_count'] = (int)$guest['interface_count'];
            $summary['guest_ip_count'] = (int)$guest['ip_count'];
            $summary['guest_status_counts'] = phase5c_preview_count_vm_statuses($guest['vms']);
        }
    }

    phase5c_preview_zero_secret($runtime['password']);
    print json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Exception $e) {
    if (is_array($runtime) && isset($runtime['password'])) {
        phase5c_preview_zero_secret($runtime['password']);
    }
    if ($originalCwd !== false) {
        @chdir($originalCwd);
    }
    fwrite(STDERR, "PHASE5C_LIVE_PREVIEW_FAILED=" . phase5c_preview_error_category($e) . "\n");
    fwrite(STDERR, "Sensitive exception details were suppressed.\n");
    exit(1);
}
