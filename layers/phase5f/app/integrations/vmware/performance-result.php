<?php

/**
 * phpIPAM Custom — Phase 5F on-demand VM performance fragment.
 * Read-only, bounded and non-persistent.
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

// PHPIPAM_CUSTOM_VMWARE_PHASE5F_VM_PERFORMANCE_V1

function phase5f_perf_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5f_perf_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f_perf_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'soap') !== false || strpos($message, 'performance') !== false || strpos($message, 'response') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

function phase5f_perf_percent($value)
{
    if ($value === null || !is_numeric($value)) return '—';
    return number_format((float)$value, 2, '.', ' ') . '%';
}

function phase5f_perf_graph($samples, $field, $title, $textClass)
{
    $values = array();
    foreach ((array)$samples as $sample) {
        if (!isset($sample[$field]) || !is_numeric($sample[$field])) continue;
        $values[] = max(0.0, min(100.0, (float)$sample[$field]));
    }
    if (count($values) < 2) return '<div class="text-muted small">' . phase5f_perf_h($title) . ': недостаточно real-time samples.</div>';

    $width = 640;
    $height = 170;
    $left = 38;
    $right = 12;
    $top = 12;
    $bottom = 28;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($values);
    $points = array();
    foreach ($values as $index => $value) {
        $x = $left + ($count === 1 ? 0 : ($index / ($count - 1)) * $plotWidth);
        $y = $top + ((100.0 - $value) / 100.0) * $plotHeight;
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    $latest = $values[$count - 1];
    $html = '<div class="phase5f-chart ' . phase5f_perf_h($textClass) . '">';
    $html .= '<div><strong>' . phase5f_perf_h($title) . '</strong> <span class="label label-default">' . phase5f_perf_h(phase5f_perf_percent($latest)) . '</span></div>';
    $html .= '<svg role="img" aria-label="' . phase5f_perf_h($title) . '" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;max-height:190px;">';
    $html .= '<title>' . phase5f_perf_h($title) . '</title>';
    foreach (array(0, 25, 50, 75, 100) as $percent) {
        $y = $top + ((100 - $percent) / 100) * $plotHeight;
        $html .= '<line x1="' . $left . '" y1="' . number_format($y, 2, '.', '') . '" x2="' . ($width - $right) . '" y2="' . number_format($y, 2, '.', '') . '" stroke="currentColor" opacity="0.16" />';
        if ($percent === 0 || $percent === 50 || $percent === 100) {
            $html .= '<text x="2" y="' . number_format($y + 4, 2, '.', '') . '" fill="currentColor" opacity="0.75" font-size="11">' . $percent . '%</text>';
        }
    }
    $html .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />';
    $html .= '<text x="' . $left . '" y="' . ($height - 6) . '" fill="currentColor" opacity="0.65" font-size="11">старые</text>';
    $html .= '<text x="' . ($width - 52) . '" y="' . ($height - 6) . '" fill="currentColor" opacity="0.65" font-size="11">сейчас</text>';
    $html .= '</svg></div>';
    return $html;
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$vmId = isset($POST->vm_id) ? trim((string)$POST->vm_id) : '';
if (!preg_match('/^vm-[0-9]+$/', $vmId)) {
    print '<div class="alert alert-danger">Invalid VMware VM external id.</div>';
    exit;
}

$maxSamples = isset($POST->max_samples) ? (string)$POST->max_samples : '60';
if (!preg_match('/^[0-9]+$/', $maxSamples) || (int)$maxSamples < 12 || (int)$maxSamples > 180) {
    print '<div class="alert alert-danger">Invalid performance sample bound.</div>';
    exit;
}
$maxSamples = (int)$maxSamples;

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterPerformance.php");

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
    $Performance = new IntegrationVmwareVcenterPerformance($FaultAwareTransport, 8, 25, 6291456);
    $performance = $Performance->read_realtime_percent_series($endpoint, $runtime['username'], $runtime['password'], 'vm', $vmId, $maxSamples, $runtime['verify_tls']);

    $samples = isset($performance['samples']) ? $performance['samples'] : array();
    print '<div class="phase5f-vm-performance"><hr><h5><strong><i class="fa fa-line-chart"></i> Real-time performance</strong></h5>';
    print phase5f_perf_graph($samples, 'cpu_percent', 'CPU usage', 'text-info');
    print phase5f_perf_graph($samples, 'memory_percent', 'RAM usage', 'text-warning');
    $refreshRate = isset($performance['provider']['refresh_rate']) ? (int)$performance['provider']['refresh_rate'] : 0;
    print '<div class="small text-muted">' . count($samples) . ' samples' . ($refreshRate > 0 ? ' × ' . $refreshRate . ' sec' : '') . '. Данные не сохраняются.</div>';
    print '<div class="small text-muted">persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div></div>';
} catch (Exception $e) {
    ob_clean();
    http_response_code(502);
    print '<div class="alert alert-warning phase5f-vm-performance">VM performance недоступен (' . phase5f_perf_h(phase5f_perf_error_category($e)) . '). Карточка VM остаётся доступной.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f_perf_zero_secret($runtime['password']);
}

print ob_get_clean();
