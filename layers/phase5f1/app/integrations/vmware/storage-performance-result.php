<?php

/** phpIPAM Custom — Phase 5F.1 optional storage I/O chart fragment. */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();
if (!$User->is_admin(false)) { http_response_code(403); print '<div class="alert alert-danger">Administrative privileges required.</div>'; exit; }

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) { http_response_code(403); print '<div class="alert alert-danger">Invalid CSRF cookie.</div>'; exit; }

// PHPIPAM_CUSTOM_VMWARE_PHASE5F1_STORAGE_PERFORMANCE_V1

function phase5f1_storage_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function phase5f1_storage_zero(&$v) { if (!is_string($v) || $v === '') return; if (function_exists('sodium_memzero')) @sodium_memzero($v); else $v = str_repeat("\0", strlen($v)); }
function phase5f1_storage_err($e) {
    $m = strtolower((string)$e->getMessage());
    if (strpos($m, 'certificate') !== false || strpos($m, 'ssl') !== false || strpos($m, 'tls') !== false) return 'tls';
    if (strpos($m, 'timeout') !== false) return 'timeout';
    if (strpos($m, '401') !== false || strpos($m, 'authentication') !== false) return 'authentication';
    if (strpos($m, '403') !== false || strpos($m, 'forbidden') !== false || strpos($m, 'permission') !== false) return 'authorization';
    if (strpos($m, 'soap') !== false || strpos($m, 'performance') !== false || strpos($m, 'response') !== false) return 'invalid_response';
    return 'unknown';
}
function phase5f1_storage_rate($v) { return $v === null ? '—' : number_format((float)$v, 2, '.', ' ') . ' MiB/s'; }

function phase5f1_storage_graph($samples)
{
    $values = array();
    foreach ((array)$samples as $sample) if (isset($sample['disk_mib_per_second']) && is_numeric($sample['disk_mib_per_second'])) $values[] = max(0.0, (float)$sample['disk_mib_per_second']);
    if (count($values) < 2) return '<div class="text-muted small phase5f1-storage-performance">Disk I/O: недостаточно real-time samples.</div>';

    $width = 640; $height = 170; $left = 48; $right = 12; $top = 12; $bottom = 28;
    $plotWidth = $width - $left - $right; $plotHeight = $height - $top - $bottom;
    $maxValue = max($values); $axisMax = max(1.0, $maxValue * 1.10);
    $points = array(); $count = count($values);
    foreach ($values as $i => $value) {
        $x = $left + ($i / max(1, $count - 1)) * $plotWidth;
        $y = $top + (($axisMax - $value) / $axisMax) * $plotHeight;
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }
    $latest = $values[$count - 1];
    $html = '<div class="phase5f1-storage-performance phase5f-chart text-success"><hr><h5><strong><i class="fa fa-hdd-o"></i> Disk I/O</strong> <span class="label label-default">' . phase5f1_storage_h(phase5f1_storage_rate($latest)) . '</span></h5>';
    $html .= '<svg role="img" aria-label="Disk I/O throughput" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;max-height:190px;">';
    foreach (array(0, 0.5, 1.0) as $fraction) {
        $value = $axisMax * $fraction; $y = $top + (1 - $fraction) * $plotHeight;
        $html .= '<line x1="' . $left . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($width - $right) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="currentColor" opacity="0.16" />';
        $html .= '<text x="2" y="' . number_format($y + 4, 2, '.', '') . '" fill="currentColor" opacity="0.75" font-size="11">' . phase5f1_storage_h(number_format($value, 1, '.', '')) . '</text>';
    }
    $html .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />';
    $html .= '<text x="' . $left . '" y="' . ($height - 6) . '" fill="currentColor" opacity="0.65" font-size="11">старые</text><text x="' . ($width - 52) . '" y="' . ($height - 6) . '" fill="currentColor" opacity="0.65" font-size="11">сейчас</text></svg>';
    $html .= '<div class="small text-muted">Aggregate disk.usage.average · данные не сохраняются.</div></div>';
    return $html;
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) { print '<div class="alert alert-danger">Invalid VMware connector id.</div>'; exit; }
$configId = (int)$configId;
$entityType = isset($POST->entity_type) ? trim((string)$POST->entity_type) : '';
$entityId = isset($POST->entity_id) ? trim((string)$POST->entity_id) : '';
if ($entityType === 'host') { if (!preg_match('/^host-[0-9]+$/', $entityId)) { print '<div class="alert alert-danger">Invalid HostSystem id.</div>'; exit; } }
elseif ($entityType === 'vm') { if (!preg_match('/^vm-[0-9]+$/', $entityId)) { print '<div class="alert alert-danger">Invalid VM id.</div>'; exit; } }
else { print '<div class="alert alert-danger">Invalid performance entity type.</div>'; exit; }
$maxSamples = isset($POST->max_samples) ? (string)$POST->max_samples : '60';
if (!preg_match('/^[0-9]+$/', $maxSamples) || (int)$maxSamples < 12 || (int)$maxSamples > 180) { print '<div class="alert alert-danger">Invalid performance sample bound.</div>'; exit; }
$maxSamples = (int)$maxSamples;

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);
    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);
    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Storage = new IntegrationVmwareVcenterStoragePerformance($FaultAwareTransport, 8, 25);
    $result = $Storage->read_realtime_series($endpoint, $runtime['username'], $runtime['password'], $entityType, $entityId, $maxSamples, $runtime['verify_tls']);
    if (empty($result['supported'])) {
        print '<div class="text-muted small phase5f1-storage-performance"><i class="fa fa-hdd-o"></i> Disk I/O graph недоступен для этого объекта (' . phase5f1_storage_h(isset($result['reason']) ? $result['reason'] : 'unsupported') . '). CPU/RAM observability не затронут.</div>';
    } else {
        print phase5f1_storage_graph($result['samples']);
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(502);
    print '<div class="text-muted small phase5f1-storage-performance"><i class="fa fa-hdd-o"></i> Disk I/O graph недоступен (' . phase5f1_storage_h(phase5f1_storage_err($e)) . '). CPU/RAM observability не затронут.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f1_storage_zero($runtime['password']);
}
print ob_get_clean();
