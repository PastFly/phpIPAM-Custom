<?php

/**
 * phpIPAM Custom — Phase 5C administrator-only read-only inventory view.
 *
 * Uses an existing encrypted Phase 5B connector. Results are rendered in-memory
 * only; no inventory, reconciliation result or health state is persisted.
 */

require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

if (!$User->is_admin(false)) {
    http_response_code(403);
    print '<div class="alert alert-danger">Administrative privileges required.</div>';
    exit;
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie("validate", "integration_vmware_vcenter_inventory", $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_UI_RESULT_V1

function phase5c_inventory_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5c_inventory_zero_secret(&$value)
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

function phase5c_inventory_error_category($error)
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
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) {
        return 'network';
    }
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false) {
        return 'invalid_response';
    }
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false || strpos($message, 'projection') !== false) {
        return 'configuration';
    }
    return 'unknown';
}

function phase5c_inventory_status_label($status)
{
    $status = (string)$status;
    $classes = array(
        'matched' => 'success',
        'vmware_only' => 'info',
        'phpipam_only' => 'default',
        'ambiguous' => 'warning',
        'metadata_mismatch' => 'warning',
        'ip_mac_mismatch' => 'danger',
        'ok' => 'success',
        'tools_unavailable' => 'warning',
        'vm_not_found' => 'default'
    );
    $class = isset($classes[$status]) ? $classes[$status] : 'default';
    return '<span class="label label-' . $class . '">' . phase5c_inventory_h($status) . '</span>';
}

function phase5c_inventory_render_counts(array $counts)
{
    if (count($counts) === 0) {
        print '<span class="text-muted">нет данных</span>';
        return;
    }
    foreach ($counts as $status => $count) {
        print '<span style="margin-right:10px;">' . phase5c_inventory_status_label($status) . ' ' . (int)$count . '</span>';
    }
}

function phase5c_inventory_post_bool($post, $field)
{
    $value = isset($post->{$field}) ? (string)$post->{$field} : '0';
    if ($value !== '0' && $value !== '1') {
        throw new InvalidArgumentException('Invalid boolean field');
    }
    return $value === '1';
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$mode = isset($POST->mode) ? trim((string)$POST->mode) : 'core';
if (!in_array($mode, array('core', 'reconcile'), true)) {
    print '<div class="alert alert-danger">Unsupported inventory view mode.</div>';
    exit;
}

$guestLimitRaw = isset($POST->guest_limit) ? trim((string)$POST->guest_limit) : '0';
if (!preg_match('/^[0-9]+$/', $guestLimitRaw)) {
    print '<div class="alert alert-danger">Guest VM limit must be a non-negative integer.</div>';
    exit;
}
$guestLimit = (int)$guestLimitRaw;
if ($guestLimit > 250) {
    print '<div class="alert alert-danger">Guest VM limit may not exceed 250 in the interactive view.</div>';
    exit;
}

try {
    $allowInsecureTls = phase5c_inventory_post_bool($POST, 'allow_insecure_tls');
} catch (Exception $e) {
    print '<div class="alert alert-danger">Invalid TLS acknowledgement field.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterInventory.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterReconciler.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationPhpIpamInventorySnapshot.php");

$runtime = null;
ob_start();

try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    if (empty($runtime['verify_tls']) && !$allowInsecureTls) {
        print '<div class="alert alert-warning">';
        print '<strong>Inventory blocked:</strong> сохранённый connector использует отключённую TLS verification. ';
        print 'Для ручного read-only запуска требуется явное подтверждение insecure TLS. Scheduled sync остаётся запрещён.';
        print '</div>';
    } else {
        $Inventory = new IntegrationVmwareVcenterInventory();
        $core = $Inventory->read_core_inventory(
            $runtime['endpoint'],
            $runtime['username'],
            $runtime['password'],
            $runtime['verify_tls']
        );

        print '<div class="alert alert-info">';
        print '<strong>Read-only inventory:</strong> connector #' . (int)$configId . '; API=' . phase5c_inventory_h($core['api_family']) . '; TLS=' . (!empty($core['verify_tls']) ? 'VERIFY' : 'INSECURE') . '. ';
        print 'Результат не сохраняется и не изменяет объекты phpIPAM/vCenter.';
        print '</div>';

        print '<div class="row">';
        $metrics = array(
            'Datacenters' => (int)$core['datacenter_count'],
            'Clusters' => (int)$core['cluster_count'],
            'ESXi Hosts' => (int)$core['host_count'],
            'VMs' => (int)$core['vm_count']
        );
        foreach ($metrics as $label => $value) {
            print '<div class="col-sm-3"><div class="well well-sm" style="margin-bottom:10px;"><strong>' . phase5c_inventory_h($label) . '</strong><br><span style="font-size:22px;">' . (int)$value . '</span></div></div>';
        }
        print '</div>';

        if ($mode === 'core') {
            print '<h4>Datacenters</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>External ID</th><th>Name</th></tr></thead><tbody>';
            foreach ($core['datacenters'] as $row) {
                print '<tr><td><code>' . phase5c_inventory_h($row['external_id']) . '</code></td><td>' . phase5c_inventory_h($row['name']) . '</td></tr>';
            }
            print '</tbody></table></div>';

            print '<h4>Clusters</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>External ID</th><th>Name</th><th>HA</th><th>DRS</th></tr></thead><tbody>';
            foreach ($core['clusters'] as $row) {
                print '<tr><td><code>' . phase5c_inventory_h($row['external_id']) . '</code></td><td>' . phase5c_inventory_h($row['name']) . '</td><td>' . ($row['ha_enabled'] === null ? '—' : ($row['ha_enabled'] ? 'ON' : 'OFF')) . '</td><td>' . ($row['drs_enabled'] === null ? '—' : ($row['drs_enabled'] ? 'ON' : 'OFF')) . '</td></tr>';
            }
            print '</tbody></table></div>';

            print '<h4>ESXi Hosts</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>External ID</th><th>Name</th><th>Connection</th><th>Power</th></tr></thead><tbody>';
            foreach ($core['hosts'] as $row) {
                print '<tr><td><code>' . phase5c_inventory_h($row['external_id']) . '</code></td><td>' . phase5c_inventory_h($row['name']) . '</td><td>' . phase5c_inventory_h($row['connection_state'] === null ? '—' : $row['connection_state']) . '</td><td>' . phase5c_inventory_h($row['power_state'] === null ? '—' : $row['power_state']) . '</td></tr>';
            }
            print '</tbody></table></div>';

            print '<h4>Virtual Machines</h4><div class="table-responsive"><table class="table table-condensed table-striped phase5c-vm-table"><thead><tr><th>External ID</th><th>Name</th><th>Power</th><th>CPU</th><th>Memory MiB</th></tr></thead><tbody>';
            foreach ($core['vms'] as $row) {
                print '<tr><td><code>' . phase5c_inventory_h($row['external_id']) . '</code></td><td>' . phase5c_inventory_h($row['name']) . '</td><td>' . phase5c_inventory_h($row['power_state'] === null ? '—' : $row['power_state']) . '</td><td>' . ($row['cpu_count'] === null ? '—' : (int)$row['cpu_count']) . '</td><td>' . ($row['memory_size_mib'] === null ? '—' : (int)$row['memory_size_mib']) . '</td></tr>';
            }
            print '</tbody></table></div>';
        } else {
            $Snapshot = new IntegrationPhpIpamInventorySnapshot($Database);
            $phpipam = $Snapshot->read_projection();

            $ipObservations = array();
            $guestStatusCounts = array();
            $guestRequested = 0;
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
                    $guestRequested = count($vmIds);
                    $ipObservations = $guest['ip_observations'];
                    foreach ($guest['vms'] as $guestVm) {
                        $status = isset($guestVm['status']) ? (string)$guestVm['status'] : 'unknown';
                        if (!isset($guestStatusCounts[$status])) {
                            $guestStatusCounts[$status] = 0;
                        }
                        $guestStatusCounts[$status]++;
                    }
                    ksort($guestStatusCounts);
                }
            }

            $Reconciler = new IntegrationVmwareVcenterReconciler();
            $reconciliation = $Reconciler->reconcile(
                $core['vms'],
                $ipObservations,
                $phpipam['devices'],
                $phpipam['addresses']
            );

            print '<div class="panel panel-default"><div class="panel-heading"><strong>Advisory reconciliation summary</strong></div><div class="panel-body">';
            print '<p><strong>phpIPAM:</strong> Devices=' . (int)$phpipam['device_count'] . '; addresses=' . (int)$phpipam['address_count'] . '; skipped invalid IP=' . (int)$phpipam['skipped_invalid_ip_count'] . '; ignored invalid MAC=' . (int)$phpipam['ignored_invalid_mac_count'] . '.</p>';
            print '<p><strong>Device states:</strong> '; phase5c_inventory_render_counts($reconciliation['summary']['device_status_counts']); print '</p>';
            print '<p><strong>Guest sample:</strong> requested=' . (int)$guestRequested . '; IP observations=' . (int)count($ipObservations) . '; statuses: '; phase5c_inventory_render_counts($guestStatusCounts); print '</p>';
            if ($guestRequested < count($core['vms'])) {
                print '<div class="alert alert-warning" style="margin-bottom:0;">IP/MAC reconciliation is a bounded sample. phpIPAM-only address totals are intentionally not presented as absence evidence until a full trusted-TLS inventory job exists.</div>';
            }
            print '</div></div>';

            print '<h4>VM ↔ phpIPAM Device</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>VM</th><th>VM external ID</th><th>Status</th><th>phpIPAM Device ID</th><th>Candidates</th></tr></thead><tbody>';
            foreach ($reconciliation['device_matches'] as $row) {
                print '<tr><td>' . phase5c_inventory_h($row['vm_name']) . '</td><td><code>' . phase5c_inventory_h($row['vm_external_id']) . '</code></td><td>' . phase5c_inventory_status_label($row['status']) . '</td><td>' . phase5c_inventory_h($row['phpipam_device_id'] === null ? '—' : $row['phpipam_device_id']) . '</td><td>' . phase5c_inventory_h(count($row['candidate_device_ids']) ? implode(', ', $row['candidate_device_ids']) : '—') . '</td></tr>';
            }
            print '</tbody></table></div>';

            if ($guestRequested > 0) {
                print '<h4>Guest IP/MAC sample ↔ phpIPAM</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>VM external ID</th><th>IP</th><th>MAC</th><th>Status</th><th>Address ID</th><th>Device ID</th></tr></thead><tbody>';
                foreach ($reconciliation['ip_matches'] as $row) {
                    print '<tr><td><code>' . phase5c_inventory_h($row['vm_external_id']) . '</code></td><td><code>' . phase5c_inventory_h($row['ip_address']) . '</code></td><td>' . phase5c_inventory_h($row['mac_address'] === null ? '—' : $row['mac_address']) . '</td><td>' . phase5c_inventory_status_label($row['status']) . '</td><td>' . phase5c_inventory_h($row['phpipam_address_id'] === null ? '—' : $row['phpipam_address_id']) . '</td><td>' . phase5c_inventory_h($row['phpipam_device_id'] === null ? '—' : $row['phpipam_device_id']) . '</td></tr>';
                }
                print '</tbody></table></div>';
            }
        }
    }
} catch (Exception $e) {
    $category = phase5c_inventory_error_category($e);
    print '<div class="alert alert-danger">Phase 5C inventory failed. Sanitized category: <strong>' . phase5c_inventory_h($category) . '</strong>. Sensitive exception details were suppressed.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) {
        phase5c_inventory_zero_secret($runtime['password']);
    }
}

$output = ob_get_clean();
print $output;
