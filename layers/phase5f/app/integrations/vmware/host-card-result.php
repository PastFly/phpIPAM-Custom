<?php

/**
 * phpIPAM Custom — Phase 5F ESXi host observability card.
 * Read-only, on-demand and non-persistent. Uses a stable HostSystem MOR selected
 * by the existing Phase 5E topology tree and the verified direct/no-proxy SOAP path.
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

// PHPIPAM_CUSTOM_VMWARE_PHASE5F_HOST_CARD_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5F_READ_ONLY_NO_PERSISTENCE_V1

function phase5f_host_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5f_host_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f_host_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'soap') !== false || strpos($message, 'response') !== false || strpos($message, 'property') !== false || strpos($message, 'performance') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

function phase5f_host_gib($bytes)
{
    if ($bytes === null) return '—';
    return number_format(((float)$bytes) / 1073741824, 2, '.', ' ') . ' GiB';
}

function phase5f_host_ghz($mhz)
{
    if ($mhz === null) return '—';
    return number_format(((float)$mhz) / 1000, 2, '.', ' ') . ' GHz';
}

function phase5f_host_percent($value)
{
    if ($value === null || !is_numeric($value)) return '—';
    return number_format((float)$value, 2, '.', ' ') . '%';
}

function phase5f_host_uptime($seconds, $bootTime)
{
    if ($seconds === null && $bootTime !== null) {
        $boot = strtotime((string)$bootTime);
        if ($boot !== false && $boot <= time()) $seconds = time() - $boot;
    }
    if ($seconds === null) return '—';
    $seconds = max(0, (int)$seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $days . 'd ' . $hours . 'h ' . $minutes . 'm';
}

function phase5f_host_graph($samples, $field, $title, $textClass)
{
    $values = array();
    foreach ((array)$samples as $sample) {
        if (!isset($sample[$field]) || !is_numeric($sample[$field])) continue;
        $values[] = max(0.0, min(100.0, (float)$sample[$field]));
    }
    if (count($values) < 2) {
        return '<div class="text-muted small">' . phase5f_host_h($title) . ': недостаточно real-time samples.</div>';
    }

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
    $html = '<div class="phase5f-chart ' . phase5f_host_h($textClass) . '">';
    $html .= '<div><strong>' . phase5f_host_h($title) . '</strong> <span class="label label-default">' . phase5f_host_h(phase5f_host_percent($latest)) . '</span></div>';
    $html .= '<svg role="img" aria-label="' . phase5f_host_h($title) . '" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;max-height:190px;">';
    $html .= '<title>' . phase5f_host_h($title) . '</title>';
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

$hostId = isset($POST->host_id) ? trim((string)$POST->host_id) : '';
if (!preg_match('/^host-[0-9]+$/', $hostId)) {
    print '<div class="alert alert-danger">Invalid VMware HostSystem external id.</div>';
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
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php");
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

    $HostReader = new IntegrationVmwareVcenterSoapReadOnly($FaultAwareTransport, 8, 20, 4194304);
    $summary = $HostReader->read_host_summary($endpoint, $runtime['username'], $runtime['password'], $hostId, $runtime['verify_tls']);

    $Performance = new IntegrationVmwareVcenterPerformance($FaultAwareTransport, 8, 25, 6291456);
    $performance = $Performance->read_realtime_percent_series($endpoint, $runtime['username'], $runtime['password'], 'host', $hostId, $maxSamples, $runtime['verify_tls']);

    $hardware = isset($summary['hardware']) ? $summary['hardware'] : array();
    $usage = isset($summary['usage']) ? $summary['usage'] : array();
    $runtimeState = isset($summary['runtime']) ? $summary['runtime'] : array();
    $product = isset($summary['product']) ? $summary['product'] : array();
    $hostName = isset($product['name']) && trim((string)$product['name']) !== '' ? $product['name'] : $hostId;

    if (empty($runtime['verify_tls'])) {
        print '<div class="alert alert-warning"><i class="fa fa-warning"></i> TLS verification отключена в настройках connector. Observability остаётся ручной и read-only; scheduler запрещён.</div>';
    }

    $power = isset($runtimeState['power_state']) ? (string)$runtimeState['power_state'] : 'unknown';
    $connection = isset($runtimeState['connection_state']) ? (string)$runtimeState['connection_state'] : 'unknown';
    $powerClass = $power === 'poweredOn' ? 'label label-success' : 'label label-default';
    $connectionClass = $connection === 'connected' ? 'label label-success' : 'label label-default';

    print '<div class="phase5f-host-card">';
    print '<h4 style="margin-top:0;"><i class="fa fa-server"></i> ' . phase5f_host_h($hostName) . ' <span class="' . $powerClass . '">' . phase5f_host_h($power) . '</span> <span class="' . $connectionClass . '">' . phase5f_host_h($connection) . '</span></h4>';
    print '<div><code class="phase5e-id">' . phase5f_host_h($hostId) . '</code></div>';

    print '<h5><strong>Аппаратная платформа</strong></h5><table class="table table-condensed phase5e-detail-table">';
    $rows = array(
        'Производитель' => isset($hardware['vendor']) ? $hardware['vendor'] : null,
        'Модель' => isset($hardware['model']) ? $hardware['model'] : null,
        'CPU' => isset($hardware['cpu_model']) ? $hardware['cpu_model'] : null,
        'Сокеты' => isset($hardware['cpu_packages']) ? $hardware['cpu_packages'] : null,
        'Ядра / потоки' => (isset($hardware['cpu_cores']) ? $hardware['cpu_cores'] : '—') . ' / ' . (isset($hardware['cpu_threads']) ? $hardware['cpu_threads'] : '—'),
        'Частота / ядро' => phase5f_host_ghz(isset($hardware['cpu_mhz_per_core']) ? $hardware['cpu_mhz_per_core'] : null),
        'RAM всего' => phase5f_host_gib(isset($hardware['memory_total_bytes']) ? $hardware['memory_total_bytes'] : null),
        'ESXi' => isset($product['full_name']) ? $product['full_name'] : null
    );
    foreach ($rows as $label => $value) print '<tr><th>' . phase5f_host_h($label) . '</th><td>' . phase5f_host_h($value === null ? '—' : $value) . '</td></tr>';
    print '</table>';

    $cpuPercent = isset($usage['cpu_used_percent']) ? $usage['cpu_used_percent'] : null;
    $memPercent = isset($usage['memory_used_percent']) ? $usage['memory_used_percent'] : null;
    $cpuWidth = $cpuPercent === null ? 0 : max(0, min(100, (float)$cpuPercent));
    $memWidth = $memPercent === null ? 0 : max(0, min(100, (float)$memPercent));

    print '<h5><strong>Текущая загрузка</strong></h5>';
    print '<div class="small"><strong>CPU:</strong> ' . phase5f_host_h(phase5f_host_ghz(isset($usage['cpu_used_mhz']) ? $usage['cpu_used_mhz'] : null)) . ' / ' . phase5f_host_h(phase5f_host_ghz(isset($usage['cpu_capacity_mhz']) ? $usage['cpu_capacity_mhz'] : null)) . ' (' . phase5f_host_h(phase5f_host_percent($cpuPercent)) . ')</div>';
    print '<div class="progress" style="margin-bottom:8px;"><div class="progress-bar" role="progressbar" aria-valuenow="' . phase5f_host_h($cpuWidth) . '" aria-valuemin="0" aria-valuemax="100" style="width:' . phase5f_host_h($cpuWidth) . '%;"></div></div>';
    print '<div class="small"><strong>RAM:</strong> ' . phase5f_host_h(phase5f_host_gib(isset($usage['memory_used_bytes']) ? $usage['memory_used_bytes'] : null)) . ' / ' . phase5f_host_h(phase5f_host_gib(isset($hardware['memory_total_bytes']) ? $hardware['memory_total_bytes'] : null)) . ' (' . phase5f_host_h(phase5f_host_percent($memPercent)) . '), свободно ' . phase5f_host_h(phase5f_host_gib(isset($usage['memory_free_bytes']) ? $usage['memory_free_bytes'] : null)) . '</div>';
    print '<div class="progress" style="margin-bottom:8px;"><div class="progress-bar" role="progressbar" aria-valuenow="' . phase5f_host_h($memWidth) . '" aria-valuemin="0" aria-valuemax="100" style="width:' . phase5f_host_h($memWidth) . '%;"></div></div>';
    print '<div class="small text-muted">Boot: ' . phase5f_host_h(isset($runtimeState['boot_time']) ? $runtimeState['boot_time'] : '—') . ' · Uptime: ' . phase5f_host_h(phase5f_host_uptime(isset($usage['uptime_seconds']) ? $usage['uptime_seconds'] : null, isset($runtimeState['boot_time']) ? $runtimeState['boot_time'] : null)) . ' · Maintenance: ' . (!empty($runtimeState['maintenance_mode']) ? 'yes' : 'no') . '</div>';

    print '<hr><h5><strong>Real-time performance</strong></h5>';
    $samples = isset($performance['samples']) ? $performance['samples'] : array();
    print phase5f_host_graph($samples, 'cpu_percent', 'CPU usage', 'text-info');
    print phase5f_host_graph($samples, 'memory_percent', 'RAM usage', 'text-warning');
    $refreshRate = isset($performance['provider']['refresh_rate']) ? (int)$performance['provider']['refresh_rate'] : 0;
    print '<div class="small text-muted">' . count($samples) . ' samples' . ($refreshRate > 0 ? ' × ' . $refreshRate . ' sec' : '') . '. Данные не сохраняются.</div>';
    print '<div class="small text-muted">persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
    print '</div>';
} catch (Exception $e) {
    ob_clean();
    http_response_code(502);
    print '<div class="alert alert-danger">ESXi observability request failed (' . phase5f_host_h(phase5f_host_error_category($e)) . '). Sensitive server details were not rendered.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f_host_zero_secret($runtime['password']);
}

print ob_get_clean();
