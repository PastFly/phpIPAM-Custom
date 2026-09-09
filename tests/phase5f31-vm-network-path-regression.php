<?php

require_once(__DIR__ . '/../layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php');

$reader = new IntegrationVmwareVcenterVmNetworkPath(function () {
    throw new RuntimeException('transport must not be called by mapping regression');
});

$invoke = function ($method, array $args) use ($reader) {
    $ref = new ReflectionMethod($reader, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($reader, $args);
};

$networkXml = <<<'XML'
<?xml version="1.0"?>
<val>
  <pnic><key>key-vim.host.PhysicalNic-vmnic0</key><device>vmnic0</device></pnic>
  <pnic><key>key-vim.host.PhysicalNic-vmnic1</key><device>vmnic1</device></pnic>
  <pnic><key>key-vim.host.PhysicalNic-vmnic2</key><device>vmnic2</device></pnic>
  <vswitch>
    <key>key-vim.host.VirtualSwitch-vSwitch0</key><name>vSwitch0</name>
    <pnic>key-vim.host.PhysicalNic-vmnic0</pnic>
    <pnic>key-vim.host.PhysicalNic-vmnic1</pnic>
    <spec><policy><nicTeaming><policy>loadbalance_srcid</policy><nicOrder><activeNic>vmnic0</activeNic><standbyNic>vmnic1</standbyNic></nicOrder></nicTeaming></policy></spec>
  </vswitch>
  <portgroup>
    <key>key-vim.host.PortGroup-VM Network</key>
    <spec><name>VM Network</name><vswitchName>vSwitch0</vswitchName><policy/></spec>
  </portgroup>
  <proxySwitch>
    <key>key-vim.host.HostProxySwitch-50 35 33 10 2a 58 c8 fe-e8 89 36 99 d2 31 93 de</key>
    <dvsUuid>50 35 33 10 2a 58 c8 fe-e8 89 36 99 d2 31 93 de</dvsUuid>
    <dvsName>Prod-DVS</dvsName>
    <uplinkPort><key>100</key><value>dvUplink1</value></uplinkPort>
    <uplinkPort><key>101</key><value>dvUplink2</value></uplinkPort>
    <spec><backing>
      <pnicSpec><pnicDevice>vmnic1</pnicDevice><uplinkPortKey>100</uplinkPortKey></pnicSpec>
      <pnicSpec><pnicDevice>vmnic2</pnicDevice><uplinkPortKey>101</uplinkPortKey></pnicSpec>
    </backing></spec>
  </proxySwitch>
</val>
XML;
$networkDoc = new DOMDocument();
if (!$networkDoc->loadXML($networkXml, LIBXML_NONET | LIBXML_NOBLANKS)) throw new RuntimeException('network test XML failed');
$networkInfo = $invoke('parse_host_network_info', array($networkDoc->documentElement));

if ($networkInfo['pnic_count'] !== 3) throw new RuntimeException('pNIC count mismatch');
if (!isset($networkInfo['vswitches']['vSwitch0'])) throw new RuntimeException('standard vSwitch missing');
if (!isset($networkInfo['portgroups']['VM Network']) || count($networkInfo['portgroups']['VM Network']) !== 1) throw new RuntimeException('standard portgroup missing');
$dvsKey = '503533102a58c8fee8893699d23193de';
if (!isset($networkInfo['proxy_switches'][$dvsKey])) throw new RuntimeException('DVS proxy switch UUID normalization mismatch');
if (count($networkInfo['proxy_switches'][$dvsKey]['pnic_specs']) !== 2) throw new RuntimeException('DVS pNIC spec count mismatch');

$hintXml = <<<'XML'
<?xml version="1.0"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>
<QueryNetworkHintResponse xmlns="urn:vim25">
  <returnval><device>vmnic0</device><connectedSwitchPort><devId>n9k-a</devId><portId>Ethernet1/1</portId></connectedSwitchPort></returnval>
  <returnval><device>vmnic1</device><connectedSwitchPort><devId>n9k-b</devId><portId>Ethernet1/2</portId></connectedSwitchPort></returnval>
  <returnval><device>vmnic2</device><lldpInfo><chassisId>00:11:22:33:44:55</chassisId><portId>Ethernet1/3</portId></lldpInfo></returnval>
</QueryNetworkHintResponse></soapenv:Body></soapenv:Envelope>
XML;
$hintDoc = new DOMDocument();
if (!$hintDoc->loadXML($hintXml, LIBXML_NONET | LIBXML_NOBLANKS)) throw new RuntimeException('hint test XML failed');
$hints = $invoke('parse_hints', array($hintDoc));
if ($hints['vmnic0']['device_id'] !== 'n9k-a' || $hints['vmnic0']['port_id'] !== 'Ethernet1/1') throw new RuntimeException('CDP hint mismatch');
if ($hints['vmnic2']['protocol'] !== 'LLDP' || $hints['vmnic2']['device_id'] !== '00:11:22:33:44:55') throw new RuntimeException('LLDP hint mismatch');

$nics = array(
    array(
        'external_id' => '4000', 'label' => 'Network adapter 1', 'adapter_type' => 'vmxnet3',
        'mac_address' => '00:50:56:aa:bb:01', 'state' => 'connected', 'backing_type' => 'standard_portgroup',
        'network_external_id' => 'network-1', 'network_name' => 'VM Network',
        'distributed_switch_uuid' => null, 'distributed_port' => null,
        'opaque_network_type' => null, 'opaque_network_id' => null
    ),
    array(
        'external_id' => '4001', 'label' => 'Network adapter 2', 'adapter_type' => 'vmxnet3',
        'mac_address' => '00:50:56:aa:bb:02', 'state' => 'connected', 'backing_type' => 'distributed_port',
        'network_external_id' => null, 'network_name' => 'Prod-PG',
        'distributed_switch_uuid' => '50 35 33 10 2a 58 c8 fe-e8 89 36 99 d2 31 93 de', 'distributed_port' => '42',
        'opaque_network_type' => null, 'opaque_network_id' => null
    )
);
$paths = $invoke('map_nics', array($nics, $networkInfo, $hints));
if (count($paths) !== 2) throw new RuntimeException('VM path count mismatch');

$standard = $paths[0];
if ($standard['path_type'] !== 'standard_vswitch' || $standard['switch_name'] !== 'vSwitch0') throw new RuntimeException('standard path mismatch');
if ($standard['teaming_policy'] !== 'loadbalance_srcid') throw new RuntimeException('standard teaming policy mismatch');
if (count($standard['uplinks']) !== 2) throw new RuntimeException('standard uplink count mismatch');
if ($standard['uplinks'][0]['adapter'] !== 'vmnic0' || $standard['uplinks'][0]['role'] !== 'active') throw new RuntimeException('active uplink mismatch');
if ($standard['uplinks'][1]['adapter'] !== 'vmnic1' || $standard['uplinks'][1]['role'] !== 'standby') throw new RuntimeException('standby uplink mismatch');
if ($standard['uplinks'][0]['device_id'] !== 'n9k-a') throw new RuntimeException('standard physical peer enrichment mismatch');
if ($standard['current_uplink'] !== null || $standard['current_uplink_proven'] !== false) throw new RuntimeException('standard current uplink must not be guessed');

$distributed = $paths[1];
if ($distributed['path_type'] !== 'distributed_vswitch' || $distributed['switch_name'] !== 'Prod-DVS') throw new RuntimeException('distributed path mismatch');
if (count($distributed['uplinks']) !== 2) throw new RuntimeException('DVS uplink count mismatch');
if ($distributed['uplinks'][0]['adapter'] !== 'vmnic1' || $distributed['uplinks'][0]['uplink_name'] !== 'dvUplink1') throw new RuntimeException('DVS uplink1 mismatch');
if ($distributed['uplinks'][1]['adapter'] !== 'vmnic2' || $distributed['uplinks'][1]['uplink_name'] !== 'dvUplink2') throw new RuntimeException('DVS uplink2 mismatch');
if ($distributed['uplinks'][1]['device_id'] !== '00:11:22:33:44:55' || $distributed['uplinks'][1]['port_id'] !== 'Ethernet1/3') throw new RuntimeException('DVS physical peer mismatch');
if ($distributed['current_uplink'] !== null || $distributed['current_uplink_proven'] !== false) throw new RuntimeException('DVS current uplink must not be guessed');

print "PHASE5F31_VM_NETWORK_PATH_REGRESSION_OK\n";
