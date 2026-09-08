<?php

/**
 * phpIPAM Custom — Phase 5D administrator-only on-demand VM card.
 * Reads one VM, its guest networking, datastore correlation and optional tags.
 * No inventory or metadata persistence; no vCenter/phpIPAM mutation.
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
if ($User->Crypto->csrf_cookie("validate", "integration_vmware_vcenter_explorer", $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5D_VM_CARD_RESULT_V1

function phase5d_vmcard_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5d_vmcard_zero_secret(&$value)
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

function phase5d_vmcard_post_bool($post, $field)
{
    $value = isset($post->{$field}) ? (string)$post->{$field} : '0';
    if ($value !== '0' && $value !== '1') {
        throw new InvalidArgumentException('Invalid boolean field');
    }
    return $value === '1';
}

function phase5d_vmcard_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false || strpos($message, 'not found') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

function phase5d_vmcard_gib($bytes)
{
    if ($bytes === null) return '—';
    return number_format(((float)$bytes) / 1073741824, 2, '.', ' ') . ' GiB';
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$vmId = isset($POST->vm_id) ? trim((string)$POST->vm_id) : '';
if ($vmId === '' || strlen($vmId) > 1024 || preg_match('/[\x00-\x20\x7F\/?#]/', $vmId)) {
    print '<div class="alert alert-danger">Invalid VMware VM external id.</div>';
    exit;
}

try {
    $allowInsecureTls = phase5d_vmcard_post_bool($POST, 'allow_insecure_tls');
    $includeTags = phase5d_vmcard_post_bool($POST, 'include_tags');
} catch (Exception $e) {
    print '<div class="alert alert-danger">Invalid VM card options.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterTopology.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDatastore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmDetail.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterMetadata.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterExplorer.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    if (empty($runtime['verify_tls']) && !$allowInsecureTls) {
        print '<div class="alert alert-warning"><strong>VM card blocked:</strong> INSECURE connector requires explicit acknowledgement.</div>';
    } else {
        $Topology = new IntegrationVmwareVcenterTopology();
        $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);
        $dcIds = array();
        foreach ($topology['datacenters'] as $dc) $dcIds[] = $dc['external_id'];

        $Datastore = new IntegrationVmwareVcenterDatastore();
        $datastoreInventory = $Datastore->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], $dcIds, $runtime['verify_tls']);
        $Explorer = new IntegrationVmwareVcenterExplorer(100);
        $projection = $Explorer->build($topology, $datastoreInventory);
        $context = $Explorer->vm_context($projection, $vmId);

        $VmDetail = new IntegrationVmwareVcenterVmDetail();
        $detail = $VmDetail->read_detail($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
        $Resolver = new IntegrationVmwareVcenterDatastoreResolver();
        $detail = $Resolver->resolve_vm_detail($detail, $datastoreInventory['datastores'], $context['datacenter_external_id']);

        $Guest = new IntegrationVmwareVcenterGuestNetwork();
        $guest = $Guest->read_vm_interfaces($runtime['endpoint'], $runtime['username'], $runtime['password'], array($vmId), $runtime['verify_tls']);
        $guestVm = count($guest['vms']) > 0 ? $guest['vms'][0] : array('status' => 'unknown', 'interfaces' => array(), 'ip_count' => 0);

        $metadata = array('tag_count' => 0, 'category_count' => 0, 'tags' => array());
        if ($includeTags) {
            $Metadata = new IntegrationVmwareVcenterMetadata();
            $metadataResult = $Metadata->read_vm_tags($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
            $metadata = $metadataResult['metadata'];
        }

        $vm = $detail['vm'];
        print '<div class="panel panel-primary"><div class="panel-heading"><strong><i class="fa fa-server"></i> VM card — ' . phase5d_vmcard_h($vm['name']) . '</strong><span class="label label-info pull-right">' . phase5d_vmcard_h($vmId) . '</span></div><div class="panel-body">';

        print '<ol class="breadcrumb" style="margin-bottom:12px;">';
        print '<li>' . phase5d_vmcard_h($context['datacenter_name'] === null ? 'Unknown Datacenter' : $context['datacenter_name']) . '</li>';
        if ($context['cluster_name'] !== null) print '<li>' . phase5d_vmcard_h($context['cluster_name']) . '</li>';
        print '<li>' . phase5d_vmcard_h($context['host_name'] === null ? 'Unknown Host' : $context['host_name']) . '</li>';
        print '<li class="active">' . phase5d_vmcard_h($vm['name']) . '</li></ol>';

        print '<div class="row"><div class="col-md-6">';
        print '<h4>Identity / Compute</h4><table class="table table-condensed">';
        $identity = isset($vm['identity']) && is_array($vm['identity']) ? $vm['identity'] : array();
        $identityRows = array(
            'VMware ID' => $vmId,
            'BIOS UUID' => isset($identity['bios_uuid']) ? $identity['bios_uuid'] : null,
            'Instance UUID' => isset($identity['instance_uuid']) ? $identity['instance_uuid'] : null,
            'Power' => isset($vm['power_state']) ? $vm['power_state'] : null,
            'vCPU' => isset($vm['cpu_count']) ? $vm['cpu_count'] : null,
            'Cores/socket' => isset($vm['cores_per_socket']) ? $vm['cores_per_socket'] : null,
            'RAM MiB' => isset($vm['memory_size_mib']) ? $vm['memory_size_mib'] : null,
            'Hardware' => isset($vm['hardware_version']) ? $vm['hardware_version'] : null
        );
        foreach ($identityRows as $label => $value) print '<tr><th style="width:35%;">' . phase5d_vmcard_h($label) . '</th><td>' . phase5d_vmcard_h($value === null ? '—' : $value) . '</td></tr>';
        print '</table></div><div class="col-md-6">';

        print '<h4>Guest / Placement</h4><table class="table table-condensed">';
        $guestIdentity = isset($detail['guest_identity']) && is_array($detail['guest_identity']) ? $detail['guest_identity'] : array();
        $tools = isset($detail['tools']) && is_array($detail['tools']) ? $detail['tools'] : array();
        $guestRows = array(
            'Datacenter' => $context['datacenter_name'],
            'Cluster' => $context['cluster_name'] === null ? 'Standalone / unknown' : $context['cluster_name'],
            'ESXi Host' => $context['host_name'],
            'Guest OS' => isset($vm['guest_os']) ? $vm['guest_os'] : null,
            'Guest hostname' => isset($guestIdentity['host_name']) ? $guestIdentity['host_name'] : null,
            'Primary IP' => isset($guestIdentity['primary_ip']) ? $guestIdentity['primary_ip'] : null,
            'VMware Tools' => isset($tools['status']) ? $tools['status'] : null,
            'Tools version' => isset($tools['version']) ? $tools['version'] : null
        );
        foreach ($guestRows as $label => $value) print '<tr><th style="width:35%;">' . phase5d_vmcard_h($label) . '</th><td>' . phase5d_vmcard_h($value === null ? '—' : $value) . '</td></tr>';
        print '</table></div></div>';

        print '<h4>Virtual disks / Datastores</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Disk</th><th>Capacity</th><th>Datastore</th><th>Datastore ID</th><th>Relation</th></tr></thead><tbody>';
        foreach ($vm['disks'] as $disk) {
            print '<tr><td>' . phase5d_vmcard_h(isset($disk['label']) ? $disk['label'] : $disk['external_id']) . '<br><code>' . phase5d_vmcard_h($disk['external_id']) . '</code></td>';
            print '<td>' . phase5d_vmcard_h(phase5d_vmcard_gib(isset($disk['capacity_bytes']) ? $disk['capacity_bytes'] : null)) . '</td>';
            print '<td>' . phase5d_vmcard_h(isset($disk['datastore_name']) && $disk['datastore_name'] !== null ? $disk['datastore_name'] : (isset($disk['observed_datastore_name']) ? $disk['observed_datastore_name'] : '—')) . '</td>';
            print '<td>' . phase5d_vmcard_h(isset($disk['datastore_external_id']) && $disk['datastore_external_id'] !== null ? $disk['datastore_external_id'] : '—') . '</td>';
            print '<td><span class="label label-' . ((isset($disk['datastore_relation_status']) && $disk['datastore_relation_status'] === 'correlated') ? 'success' : 'warning') . '">' . phase5d_vmcard_h(isset($disk['datastore_relation_status']) ? $disk['datastore_relation_status'] : 'unknown') . '</span><br><small class="text-muted">' . phase5d_vmcard_h(isset($disk['datastore_relation_evidence']) ? $disk['datastore_relation_evidence'] : '') . '</small></td></tr>';
        }
        if (count($vm['disks']) === 0) print '<tr><td colspan="5" class="text-muted">No virtual disks returned.</td></tr>';
        print '</tbody></table></div>';

        print '<h4>Guest networking</h4><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>NIC</th><th>MAC</th><th>IP</th><th>Prefix</th><th>Origin / state</th></tr></thead><tbody>';
        foreach ($guestVm['interfaces'] as $iface) {
            if (count($iface['ip_addresses']) === 0) {
                print '<tr><td>' . phase5d_vmcard_h($iface['nic_external_id']) . '</td><td>' . phase5d_vmcard_h($iface['mac_address']) . '</td><td>—</td><td>—</td><td>—</td></tr>';
                continue;
            }
            foreach ($iface['ip_addresses'] as $ip) {
                print '<tr><td>' . phase5d_vmcard_h($iface['nic_external_id']) . '</td><td>' . phase5d_vmcard_h($iface['mac_address']) . '</td><td><code>' . phase5d_vmcard_h($ip['ip_address']) . '</code></td><td>' . phase5d_vmcard_h($ip['prefix_length'] === null ? '—' : $ip['prefix_length']) . '</td><td>' . phase5d_vmcard_h(($ip['origin'] === null ? '—' : $ip['origin']) . ' / ' . ($ip['state'] === null ? '—' : $ip['state'])) . '</td></tr>';
            }
        }
        if (count($guestVm['interfaces']) === 0) print '<tr><td colspan="5" class="text-muted">Guest networking status: ' . phase5d_vmcard_h($guestVm['status']) . '</td></tr>';
        print '</tbody></table></div>';

        print '<h4>VMware Tags / Categories</h4>';
        if (!$includeTags) {
            print '<div class="text-muted">Tag lookup was not requested.</div>';
        } elseif ((int)$metadata['tag_count'] === 0) {
            print '<div class="text-muted">No attached VMware tags were returned for this VM.</div>';
        } else {
            print '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Tag</th><th>Category</th><th>Description</th></tr></thead><tbody>';
            foreach ($metadata['tags'] as $tag) {
                print '<tr><td>' . phase5d_vmcard_h($tag['name']) . '</td><td>' . phase5d_vmcard_h(isset($tag['category']['name']) ? $tag['category']['name'] : '—') . '</td><td>' . phase5d_vmcard_h(isset($tag['description']) && $tag['description'] !== null ? $tag['description'] : '—') . '</td></tr>';
            }
            print '</tbody></table></div>';
        }

        print '<div class="alert alert-warning" style="margin-bottom:0;"><strong>Relation provenance:</strong> VM→Datastore link is correlation-only when based on exact VMDK datastore name + Datacenter scope. It is not presented as a native VMware stable-ID relation.</div>';
        print '<div class="text-muted" style="margin-top:8px;">persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
        print '</div></div>';
    }
} catch (Exception $e) {
    http_response_code(500);
    print '<div class="alert alert-danger"><strong>VM card failed:</strong> ' . phase5d_vmcard_h(phase5d_vmcard_error_category($e)) . '. Sensitive exception details were suppressed.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5d_vmcard_zero_secret($runtime['password']);
}

$output = ob_get_clean();
print $output;
