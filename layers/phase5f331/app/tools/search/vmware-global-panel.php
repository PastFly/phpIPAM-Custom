<?php
/** Phase 5F.3.3.1: VMware provider on the native phpIPAM search page. */
if (!isset($User, $Database) || !is_object($User)) { http_response_code(403); return; }
$User->check_user_session();
if (!$User->is_admin(false)) return;
if (!isset($searchTerm) || !is_string($searchTerm)) return;
$phase5f331Query = trim($searchTerm);
if ($phase5f331Query === '' || strlen($phase5f331Query) > 256 || preg_match('/[\x00-\x1f\x7f]/', $phase5f331Query)) return;

$phase5f331Configs = array();
try {
    require_once(__DIR__ . '/../../../functions/classes/class.IntegrationVmwareVcenter.php');
    require_once(__DIR__ . '/../../../functions/classes/class.IntegrationSecretCipher.php');
    require_once(__DIR__ . '/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php');
    $phase5f331Cipher = IntegrationSecretCipher::from_keyring_file();
    $phase5f331Store = new IntegrationVmwareVcenterConfigStore($Database, $phase5f331Cipher);
    foreach ($phase5f331Store->list_configs() as $config) {
        if (empty($config['enabled']) || empty($config['has_credential'])) continue;
        $id = filter_var($config['id'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($id !== false) $phase5f331Configs[] = array('id' => $id, 'name' => (string)$config['name'], 'verify_tls' => !empty($config['verify_tls']));
    }
    if (!$phase5f331Configs) return;
    $phase5f331Csrf = $User->Crypto->csrf_cookie('create', 'integration_vmware_vcenter_explorer');
    $phase5f331Url = create_link('integrations', 'vmware');
} catch (Throwable $e) {
    // Provider failure must not suppress the native IP/subnet/device results.
    print '<div class="text-muted">VMware: подключения временно недоступны. Обычный поиск phpIPAM продолжает работать.</div>';
    return;
}
$phase5f331H = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<section class="panel panel-default" id="phase5f331-global-search"
    data-query="<?php print $phase5f331H($phase5f331Query); ?>"
    data-csrf="<?php print $phase5f331H($phase5f331Csrf); ?>"
    data-workspace-url="<?php print $phase5f331H($phase5f331Url); ?>">
    <div class="panel-heading"><strong><i class="fa fa-cloud"></i> VMware / Виртуальные машины</strong></div>
    <div class="panel-body">
        <div class="form-inline">
            <label for="phase5f331-connector">Подключение</label>
            <select id="phase5f331-connector" class="form-control input-sm">
                <?php if (count($phase5f331Configs) > 1 && count($phase5f331Configs) <= 5) { ?>
                    <option value="all">Все доступные подключения</option>
                <?php } ?>
                <?php foreach ($phase5f331Configs as $config) { ?>
                    <option value="<?php print (int)$config['id']; ?>" data-connector="1" title="<?php print $phase5f331H($config['verify_tls'] ? 'TLS VERIFY' : 'TLS verification отключена в сохранённых настройках'); ?>"><?php print $phase5f331H($config['name']); ?></option>
                <?php } ?>
            </select>
            <button type="button" class="btn btn-default btn-sm" id="phase5f331-retry"><i class="fa fa-refresh"></i> Обновить VMware</button>
        </div>
        <p class="small text-muted">Поиск по имени, описанию, тегам, MAC, IP, Guest OS и размещению. Результаты phpIPAM ниже не заменяются. При количестве подключений больше пяти выбери одно подключение.</p>
        <div id="phase5f331-status" role="status" aria-live="polite"></div>
        <div id="phase5f331-results"></div>
    </div>
</section>
<script src="app/tools/search/phase5f331-global-search.js"></script>
