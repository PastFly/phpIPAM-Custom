<?php

/**
 * phpIPAM Custom — Phase 5E compact VMware VM detail.
 * Uses the Phase 5D read-only collectors; no persistence or mutation.
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
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5E_VM_CARD_RESULT_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5E1_GUEST_OS_IP_LINKS_TLS_SETTINGS_V1

function phase5e_vmcard_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5e_vmcard_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5e_vmcard_post_bool($post, $field)
{
    $value = isset($post->{$field}) ? (string)$post->{$field} : '0';
    if ($value !== '0' && $value !== '1') throw new InvalidArgumentException('Invalid boolean field');
    return $value === '1';
}

function phase5e_vmcard_error_category($error)
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

function phase5e_vmcard_value($array, $key)
{
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : null;
}

function phase5e_vmcard_gib($bytes)
{
    if ($bytes === null) return '—';
    return number_format(((float)$bytes) / 1073741824, 2, '.', ' ') . ' GiB';
}

function phase5e_vmcard_mib_to_gib($mib)
{
    if ($mib === null) return '—';
    return number_format(((float)$mib) / 1024, 2, '.', ' ') . ' GiB';
}

function phase5e_vmcard_scope_link($scope, $id, $name)
{
    if ($id === null || $name === null) return phase5e_vmcard_h($name === null ? '—' : $name);
    return '<a href="#" class="phase5e-scope-link" data-scope="' . phase5e_vmcard_h($scope) . '" data-scope-id="' . phase5e_vmcard_h($id) . '">' . phase5e_vmcard_h($name) . '</a>';
}

function phase5e_vmcard_ip_html($resolver, $ip, &$cache)
{
    $ip = trim((string)$ip);
    if ($ip === '') return '—';
    if (!array_key_exists($ip, $cache)) {
        $cache[$ip] = null;
        if ($resolver !== null) {
            try {
                $cache[$ip] = $resolver->resolve($ip);
            } catch (Exception $ignored) {
                $cache[$ip] = null;
            }
        }
    }
    $escaped = phase5e_vmcard_h($ip);
    $match = $cache[$ip];
    if (is_array($match) && isset($match['url'])) {
        return '<a href="' . phase5e_vmcard_h($match['url']) . '" title="Открыть адрес в phpIPAM"><code>' . $escaped . '</code></a>';
    }
    return '<code>' . $escaped . '</code>';
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
    $includeTags = phase5e_vmcard_post_bool($POST, 'include_tags');
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
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    if (empty($runtime['verify_tls'])) {
        print '<div class="alert alert-warning"><i class="fa fa-warning"></i> TLS verification отключена в настройках этого connector. Ручной read-only Explorer использует сохранённую настройку; scheduler остаётся запрещённым.</div>';
    }

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
        $metadata = $Metadata->read_vm_tags($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
    }

    $AddressLinkResolver = null;
    try {
        $AddressLinkResolver = new IntegrationPhpIpamAddressLinkResolver($Database);
    } catch (Exception $ignored) {
        $AddressLinkResolver = null;
    }
    $ipLinkCache = array();

    $vm = $detail['vm'];
    $identity = phase5e_vmcard_value($vm, 'identity');
    $guestIdentity = phase5e_vmcard_value($detail, 'guest_identity');
    $tools = phase5e_vmcard_value($detail, 'tools');
    $power = phase5e_vmcard_value($vm, 'power_state');
    $powerClass = $power === 'powered_on' ? 'label label-success' : 'label label-default';

    $guestOs = phase5e_vmcard_value($guestIdentity, 'guest_os');
    if ($guestOs === null || trim((string)$guestOs) === '') $guestOs = phase5e_vmcard_value($vm, 'guest_os');
    $primaryIp = phase5e_vmcard_value($guestIdentity, 'primary_ip');

    print '<div class="phase5e-detail-section"><h4 style="margin-top:0;"><i class="fa fa-cube"></i> ' . phase5e_vmcard_h($vm['name']) . ' <span class="' . $powerClass . '">' . phase5e_vmcard_h($power === null ? 'unknown' : $power) . '</span></h4>';
    print '<div><code class="phase5e-id">' . phase5e_vmcard_h($vmId) . '</code></div></div>';

    print '<div class="phase5e-detail-section"><h5><strong>Identity / Compute</strong></h5><table class="table table-condensed phase5e-detail-table">';
    $rows = array(
        'BIOS UUID' => phase5e_vmcard_value($identity, 'bios_uuid'),
        'Instance UUID' => phase5e_vmcard_value($identity, 'instance_uuid'),
        'vCPU' => phase5e_vmcard_value($vm, 'cpu_count'),
        'Cores/socket' => phase5e_vmcard_value($vm, 'cores_per_socket'),
        'RAM GiB' => phase5e_vmcard_mib_to_gib(phase5e_vmcard_value($vm, 'memory_size_mib')),
        'Hardware' => phase5e_vmcard_value($vm, 'hardware_version')
    );
    foreach ($rows as $label => $value) print '<tr><th>' . phase5e_vmcard_h($label) . '</th><td>' . phase5e_vmcard_h($value === null ? '—' : $value) . '</td></tr>';
    print '</table></div>';

    print '<div class="phase5e-detail-section"><h5><strong>Placement / Guest</strong></h5><table class="table table-condensed phase5e-detail-table">';
    print '<tr><th>Datacenter</th><td>' . phase5e_vmcard_scope_link('datacenter', $context['datacenter_external_id'], $context['datacenter_name']) . '</td></tr>';
    if ($context['cluster_external_id'] === null) print '<tr><th>Cluster</th><td><span class="text-muted">Standalone / unknown</span></td></tr>';
    else print '<tr><th>Cluster</th><td>' . phase5e_vmcard_scope_link('cluster', $context['cluster_external_id'], $context['cluster_name']) . '</td></tr>';
    print '<tr><th>ESXi Host</th><td>' . phase5e_vmcard_scope_link('host', $context['host_external_id'], $context['host_name']) . '</td></tr>';
    print '<tr><th>Guest OS</th><td>' . phase5e_vmcard_h($guestOs === null ? '—' : $guestOs) . '</td></tr>';
    print '<tr><th>Guest hostname</th><td>' . phase5e_vmcard_h(phase5e_vmcard_value($guestIdentity, 'host_name') === null ? '—' : $guestIdentity['host_name']) . '</td></tr>';
    print '<tr><th>Primary IP</th><td>' . phase5e_vmcard_ip_html($AddressLinkResolver, $primaryIp, $ipLinkCache) . '</td></tr>';
    print '<tr><th>VMware Tools</th><td>' . phase5e_vmcard_h(phase5e_vmcard_value($tools, 'status') === null ? '—' : $tools['status']) . '</td></tr>';
    print '<tr><th>Tools version</th><td>' . phase5e_vmcard_h(phase5e_vmcard_value($tools, 'version') === null ? '—' : $tools['version']) . '</td></tr>';
    print '</table></div>';

    print '<div class="phase5e-detail-section"><h5><strong>Диски / Datastore</strong></h5><div class="table-responsive"><table class="table table-condensed phase5e-detail-table"><thead><tr><th>Disk</th><th>Capacity</th><th>Datastore</th><th>Relation</th></tr></thead><tbody>';
    foreach ($vm['disks'] as $disk) {
        $status = phase5e_vmcard_value($disk, 'datastore_relation_status');
        $dsName = phase5e_vmcard_value($disk, 'datastore_name');
        if ($dsName === null) $dsName = phase5e_vmcard_value($disk, 'observed_datastore_name');
        $dsId = phase5e_vmcard_value($disk, 'datastore_external_id');
        print '<tr><td>' . phase5e_vmcard_h(phase5e_vmcard_value($disk, 'label') !== null ? $disk['label'] : $disk['external_id']) . '</td>';
        print '<td>' . phase5e_vmcard_h(phase5e_vmcard_gib(phase5e_vmcard_value($disk, 'capacity_bytes'))) . '</td><td>';
        if ($dsId !== null) print '<a href="#" class="phase5e-datastore-link" data-datastore-id="' . phase5e_vmcard_h($dsId) . '">' . phase5e_vmcard_h($dsName === null ? $dsId : $dsName) . '</a><br><code class="phase5e-id">' . phase5e_vmcard_h($dsId) . '</code>';
        else print phase5e_vmcard_h($dsName === null ? '—' : $dsName);
        print '</td><td><span class="label label-' . ($status === 'correlated' ? 'success' : 'warning') . '">' . phase5e_vmcard_h($status === null ? 'unknown' : $status) . '</span><br><small class="text-muted">' . phase5e_vmcard_h(phase5e_vmcard_value($disk, 'datastore_relation_evidence')) . '</small></td></tr>';
    }
    if (count($vm['disks']) === 0) print '<tr><td colspan="4" class="text-muted">No virtual disks returned.</td></tr>';
    print '</tbody></table></div></div>';

    print '<div class="phase5e-detail-section"><h5><strong>Guest networking</strong></h5><div class="table-responsive"><table class="table table-condensed phase5e-detail-table"><thead><tr><th>NIC</th><th>MAC</th><th>IP</th></tr></thead><tbody>';
    foreach ($guestVm['interfaces'] as $iface) {
        if (count($iface['ip_addresses']) === 0) {
            print '<tr><td>' . phase5e_vmcard_h($iface['nic_external_id']) . '</td><td>' . phase5e_vmcard_h($iface['mac_address']) . '</td><td>—</td></tr>';
            continue;
        }
        foreach ($iface['ip_addresses'] as $ip) {
            print '<tr><td>' . phase5e_vmcard_h($iface['nic_external_id']) . '</td><td>' . phase5e_vmcard_h($iface['mac_address']) . '</td><td>' . phase5e_vmcard_ip_html($AddressLinkResolver, $ip['ip_address'], $ipLinkCache) . '</td></tr>';
        }
    }
    if (count($guestVm['interfaces']) === 0) print '<tr><td colspan="3" class="text-muted">Guest networking status: ' . phase5e_vmcard_h($guestVm['status']) . '</td></tr>';
    print '</tbody></table></div><div class="small text-muted">IP открывает штатную карточку phpIPAM только при точном и однозначном SELECT-only совпадении. При отсутствии или неоднозначности ссылка не создаётся.</div></div>';

    print '<div class="phase5e-detail-section"><h5><strong>Tags / Categories</strong></h5>';
    if (!$includeTags) print '<div class="text-muted">Tag lookup was not requested.</div>';
    elseif ((int)$metadata['tag_count'] === 0) print '<div class="text-muted">No attached VMware tags were returned.</div>';
    else {
        print '<div class="table-responsive"><table class="table table-condensed phase5e-detail-table"><thead><tr><th>Tag</th><th>Category</th></tr></thead><tbody>';
        foreach ($metadata['tags'] as $tag) {
            $category = isset($tag['category']) && is_array($tag['category']) ? $tag['category'] : array();
            print '<tr><td>' . phase5e_vmcard_h($tag['name']) . '</td><td>' . phase5e_vmcard_h(phase5e_vmcard_value($category, 'name') === null ? '—' : $category['name']) . '</td></tr>';
        }
        print '</tbody></table></div>';
    }
    print '</div>';

    print '<div class="text-muted phase5e-safety-note">VM→Datastore name correlation remains correlation-only, not native VMware stable-ID authority.<br>persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
} catch (Exception $e) {
    http_response_code(500);
    print '<div class="alert alert-danger"><strong>VM card failed:</strong> ' . phase5e_vmcard_h(phase5e_vmcard_error_category($e)) . '. Sensitive exception details were suppressed.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5e_vmcard_zero_secret($runtime['password']);
}

$output = ob_get_clean();
print $output;
