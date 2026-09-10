<?php
require_once(__DIR__ . '/../layers/phase5f332/functions/classes/class.IntegrationVmwareVcenterObjectFolders.php');
$checks = 0;
function check($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException('FAIL: ' . $label); }
function ref($type, $id) { return array('type' => $type, 'id' => $id); }
function row($type, $id, $name, array $refs = array(), $template = false) { return array('type' => $type, 'id' => $id, 'name' => $name, 'refs' => $refs, 'template' => $template); }
function indexed(array $rows) { $out = array(); foreach ($rows as $r) $out[$r['type'] . ':' . $r['id']] = $r; return $out; }
function by_id(array $rows, $id) { foreach ($rows as $r) if ($r['external_id'] === $id) return $r; throw new RuntimeException('missing fixture ' . $id); }
function fails($callback, $code) { try { $callback(); } catch (RuntimeException $e) { check($e->getMessage() === $code, 'error category ' . $code . ' got ' . $e->getMessage()); return; } throw new RuntimeException('expected ' . $code); }
$vmRoot = ref('Folder', 'group-v3'); $dsRoot = ref('Folder', 'group-s4');
$vm = indexed(array(
    row('Folder', 'group-v3', 'vm', array('parent' => array(ref('Datacenter', 'datacenter-1')), 'childEntity' => array(ref('Folder', 'group-v10'), ref('VirtualMachine', 'vm-1'), ref('VirtualApp', 'resgroup-22')))),
    row('Folder', 'group-v10', 'NORD', array('parent' => array($vmRoot), 'childEntity' => array(ref('VirtualMachine', 'vm-2'), ref('VirtualMachine', 'vm-3'), ref('Folder', 'group-v11')))),
    row('Folder', 'group-v11', 'Пустая <script> папка', array('parent' => array(ref('Folder', 'group-v10')), 'childEntity' => array())),
    row('VirtualMachine', 'vm-1', 'loose-vm', array('parent' => array($vmRoot))),
    row('VirtualMachine', 'vm-2', 'inside-vm', array('parent' => array(ref('Folder', 'group-v10')))),
    row('VirtualMachine', 'vm-3', 'template', array('parent' => array(ref('Folder', 'group-v10'))), true),
    row('VirtualApp', 'resgroup-22', 'Application', array('parentFolder' => array($vmRoot), 'vm' => array(ref('VirtualMachine', 'vm-4')), 'resourcePool' => array(ref('VirtualApp', 'resgroup-23')))),
    row('VirtualApp', 'resgroup-23', 'Nested app', array('parentVApp' => array(ref('VirtualApp', 'resgroup-22')), 'vm' => array(ref('VirtualMachine', 'vm-5')))),
    row('VirtualMachine', 'vm-4', 'app-vm', array('parent' => array($vmRoot), 'parentVApp' => array(ref('VirtualApp', 'resgroup-22')))),
    row('VirtualMachine', 'vm-5', 'nested-vm', array('parentVApp' => array(ref('VirtualApp', 'resgroup-23')))),
    row('Folder', 'group-v13', 'vm', array('parent' => array(ref('Datacenter', 'datacenter-2')), 'childEntity' => array(ref('Folder', 'group-v20')))),
    row('Folder', 'group-v20', 'NORD', array('parent' => array(ref('Folder', 'group-v13')), 'childEntity' => array(ref('VirtualMachine', 'vm-20')))),
    row('VirtualMachine', 'vm-20', 'inside-vm', array('parent' => array(ref('Folder', 'group-v20'))))
));
$ds = indexed(array(
    row('Folder', 'group-s4', 'datastore', array('parent' => array(ref('Datacenter', 'datacenter-1')), 'childEntity' => array(ref('Folder', 'group-s10'), ref('StoragePod', 'group-p1')))),
    row('Folder', 'group-s10', 'NORD', array('parent' => array($dsRoot), 'childEntity' => array(ref('Datastore', 'datastore-2')))),
    row('StoragePod', 'group-p1', 'Storage Tier', array('parent' => array($dsRoot), 'childEntity' => array(ref('Datastore', 'datastore-1')))),
    row('Datastore', 'datastore-1', 'Shared datastore', array('parent' => array(ref('StoragePod', 'group-p1')))),
    row('Datastore', 'datastore-2', 'Local datastore', array('parent' => array(ref('Folder', 'group-s10')))),
    row('Folder', 'group-s14', 'datastore', array('parent' => array(ref('Datacenter', 'datacenter-2')), 'childEntity' => array()))
));
$vmRoots = array('Folder:group-v3' => 'datacenter-1', 'Folder:group-v13' => 'datacenter-2');
$dsRoots = array('Folder:group-s4' => 'datacenter-1', 'Folder:group-s14' => 'datacenter-2');
$result = IntegrationVmwareVcenterObjectFolders::project($vm, $vmRoots, 'vm');
check($result['resolved_object_count'] === 6 && $result['unresolved_object_count'] === 0, 'all VMs resolved');
check(by_id($result['nodes'], 'group-v3')['implicit_root'], 'root determined by dc reference');
check(!by_id($result['nodes'], 'group-v10')['implicit_root'], 'ordinary folder not implicit');
check(by_id($result['nodes'], 'group-v3')['subtree_object_count'] === 5, 'descendant count includes vApps and templates');
check(by_id($result['nodes'], 'group-v10')['direct_object_count'] === 2, 'direct children distinct from subtree');
check(by_id($result['nodes'], 'group-v11')['subtree_object_count'] === 0, 'empty folder retained');
check(by_id($result['nodes'], 'group-v11')['name'] === 'Пустая <script> папка', 'display name not reinterpreted as identity');
check(by_id($result['objects'], 'vm-3')['is_template'], 'templates retained and identified');
check(by_id($result['objects'], 'vm-4')['parent_key'] === 'VirtualApp:resgroup-22', 'VM parentVApp overrides ordinary parent');
check(by_id($result['nodes'], 'resgroup-23')['kind'] === 'vapp', 'vApp not mislabeled Folder');
check(by_id($result['objects'], 'vm-20')['datacenter_external_id'] === 'datacenter-2', 'duplicate names isolated by id/dc');
$storage = IntegrationVmwareVcenterObjectFolders::project($ds, $dsRoots, 'datastore');
check($storage['resolved_object_count'] === 2, 'storage resolved');
check(by_id($storage['nodes'], 'group-p1')['kind'] === 'datastore_cluster', 'StoragePod distinct from Folder');
check(by_id($storage['objects'], 'datastore-1')['parent_key'] === 'StoragePod:group-p1', 'datastore parent native MoRef');
$bad = $vm; $bad['VirtualMachine:vm-2']['refs']['parent'] = array(ref('Folder', 'group-v20'));
$projected = IntegrationVmwareVcenterObjectFolders::project($bad, $vmRoots, 'vm');
check($projected['unresolved_object_count'] === 1, 'racing parent/child edge not guessed');
$bad = $vm; $bad['Folder:group-v10']['refs']['parent'] = array(ref('Folder', 'group-v11'));
$bad['Folder:group-v11']['refs']['childEntity'] = array(ref('Folder', 'group-v10'));
$projected = IntegrationVmwareVcenterObjectFolders::project($bad, $vmRoots, 'vm');
check($projected['unresolved_container_count'] === 2 && $projected['unresolved_object_count'] === 2, 'cycles bounded and exposed');
$bad = $vm; unset($bad['Folder:group-v10']);
check(IntegrationVmwareVcenterObjectFolders::project($bad, $vmRoots, 'vm')['unresolved_object_count'] === 2, 'missing parent no name fallback');
check($result['objects'] === IntegrationVmwareVcenterObjectFolders::project($vm, $vmRoots, 'vm')['objects'], 'deterministic model');
echo 'PHASE5F332_MODEL_ASSERTIONS_OK=' . $checks . "\n";
if (in_array('--model-only', $argv, true)) exit(0);
if (!class_exists('DOMDocument')) { fwrite(STDERR, "ERROR: full transport tests require PHP DOM\n"); exit(2); }

// Entire SOAP inventory comes from fixtures. No real HTTP or configuration access.
class IntegrationVmwareVcenter { public function normalize_endpoint($endpoint) { if ($endpoint !== 'https://vcenter.invalid') throw new RuntimeException('unexpected test endpoint'); return $endpoint; } }
$dc = indexed(array(
    row('Folder', 'group-d1', 'root', array('childEntity' => array(ref('Folder', 'group-d2')))),
    row('Folder', 'group-d2', 'DC folder', array('parent' => array(ref('Folder', 'group-d1')), 'childEntity' => array(ref('Datacenter', 'datacenter-1'), ref('Datacenter', 'datacenter-2')))),
    row('Datacenter', 'datacenter-1', 'Primary DC', array('vmFolder' => array($vmRoot), 'datastoreFolder' => array($dsRoot))),
    row('Datacenter', 'datacenter-2', 'Secondary DC', array('vmFolder' => array(ref('Folder', 'group-v13')), 'datastoreFolder' => array(ref('Folder', 'group-s14'))))
));
function xml($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
class FixtureTransport {
    public $db; public $calls = array(); public $mode = ''; public $tls = false;
    public function __construct($db) { $this->db = $db; }
    public function __invoke($method, $url, $headers, $body, $verify, $connect, $timeout) {
        check($method === 'POST' && $url === 'https://vcenter.invalid/sdk', 'single configured SDK target');
        check($verify === $this->tls, 'saved TLS policy passed verbatim');
        check($connect > 0 && $connect <= 8 && $timeout <= 20, 'bounded call timeout');
        $doc = new DOMDocument(); $doc->loadXML($body); $xp = new DOMXPath($doc);
        $xp->registerNamespace('v', 'urn:vim25'); $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        $op = $xp->query('/s:Envelope/s:Body/*')->item(0)->localName; $this->calls[] = $op;
        check(in_array($op, array('RetrieveServiceContent', 'Login', 'RetrieveProperties', 'Logout'), true), 'operation read/session allowlist');
        $out = ''; $responseHeaders = array();
        if ($op === 'RetrieveServiceContent') $out = '<returnval><rootFolder type="Folder">group-d1</rootFolder><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><sessionManager type="SessionManager">SessionManager</sessionManager></returnval>';
        elseif ($op === 'Login') $responseHeaders = array('Set-Cookie: vmware_soap_session="test-session"; Path=/; HttpOnly');
        elseif ($op === 'RetrieveProperties') {
            check(in_array('Cookie: vmware_soap_session="test-session"', $headers, true), 'session cookie used');
            check($xp->query('//v:objectSet')->length <= 20, 'batch bounded');
            if ($this->mode === 'http') return array('status' => 403, 'body' => 'secret-do-not-print', 'headers' => array());
            if ($this->mode === 'exception') throw new RuntimeException('secret-do-not-print');
            if ($this->mode === 'doctype') return array('status' => 200, 'body' => '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><x>&x;</x>', 'headers' => array());
            foreach ($xp->query('//v:objectSet/v:obj') as $obj) {
                $key = $obj->getAttribute('type') . ':' . $obj->textContent;
                if (!isset($this->db[$key])) continue;
                $r = $this->db[$key]; $out .= '<returnval><obj type="' . xml($r['type']) . '">' . xml($r['id']) . '</obj>';
                $out .= '<propSet><name>name</name><val xsi:type="xsd:string">' . xml($r['name']) . '</val></propSet>';
                foreach ($r['refs'] as $property => $refs) {
                    if (!$refs) continue;
                    $out .= '<propSet><name>' . xml($property) . '</name>';
                    if (in_array($property, array('vm', 'resourcePool', 'childEntity'), true)) {
                        $out .= '<val xsi:type="ArrayOfManagedObjectReference">';
                        foreach ($refs as $ref) $out .= '<ManagedObjectReference type="' . xml($ref['type']) . '">' . xml($ref['id']) . '</ManagedObjectReference>';
                        $out .= '</val>';
                    } else $out .= '<val xsi:type="ManagedObjectReference" type="' . xml($refs[0]['type']) . '">' . xml($refs[0]['id']) . '</val>';
                    $out .= '</propSet>';
                }
                if ($r['type'] === 'VirtualMachine') $out .= '<propSet><name>config.template</name><val xsi:type="xsd:boolean">' . ($r['template'] ? 'true' : 'false') . '</val></propSet>';
                $out .= '</returnval>';
            }
        }
        return array('status' => 200, 'headers' => $responseHeaders, 'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><s:Body><' . $op . 'Response xmlns="urn:vim25">' . $out . '</' . $op . 'Response></s:Body></s:Envelope>');
    }
}
$transport = new FixtureTransport($dc + $vm + $ds);
$reader = new IntegrationVmwareVcenterObjectFolders($transport);
$live = $reader->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', false);
check($live['domains']['vm']['resolved_object_count'] === 6, 'SOAP VM inventory discovered');
check($live['domains']['datastore']['resolved_object_count'] === 2, 'SOAP storage inventory discovered');
check(!$live['partial'], 'complete graph not labeled partial');
check($live['request_count'] === count($transport->calls) && end($transport->calls) === 'Logout', 'logout included in accounting');
check(!$live['vcenter_objects_modified'] && !$live['phpipam_objects_modified'] && !$live['persistent_inventory_written'], 'no mutation/persistence claim');
$transport->tls = true;
check($reader->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', true)['domains']['vm']['resolved_object_count'] === 6, 'reader reuse resets state');
foreach (array('http' => 'folder_http_403', 'exception' => 'folder_transport_failure', 'doctype' => 'folder_unsafe_xml') as $mode => $error) {
    $transport = new FixtureTransport($dc + $vm + $ds); $transport->mode = $mode;
    fails(function () use ($transport) { (new IntegrationVmwareVcenterObjectFolders($transport))->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', false); }, $error);
    check(end($transport->calls) === 'Logout', 'failure cleans up session');
}
$transport = new FixtureTransport($dc + $vm + $ds); unset($transport->db['VirtualMachine:vm-2']);
$partial = (new IntegrationVmwareVcenterObjectFolders($transport))->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', false);
check($partial['partial'] && $partial['missing_object_count'] === 1, 'unreadable object explicitly partial');
$transport = new FixtureTransport($dc + $vm + $ds);
fails(function () use ($transport) { (new IntegrationVmwareVcenterObjectFolders($transport, 1))->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', false); }, 'folder_object_limit');
check(end($transport->calls) === 'Logout', 'object limit cleans session');
$transport = new FixtureTransport($dc + $vm + $ds);
fails(function () use ($transport) { (new IntegrationVmwareVcenterObjectFolders($transport, 4096, 4))->read_inventory('https://vcenter.invalid', 'test', 'test-only-password', false); }, 'folder_request_budget');
check(count($transport->calls) === 4 && end($transport->calls) === 'Logout', 'cleanup request reserved');
echo 'PHASE5F332_FULL_ASSERTIONS_OK=' . $checks . "\n";
