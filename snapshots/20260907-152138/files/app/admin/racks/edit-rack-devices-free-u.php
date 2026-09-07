<?php

/**
 * RACK V2 - return valid rack start positions for a proposed device.
 * Read-only AJAX endpoint; no database changes are performed.
 */

require_once(dirname(__FILE__) . '/../../../functions/functions.php');

$Database = new Database_PDO;
$User     = new User($Database);
$Racks    = new phpipam_rack($Database);

$User->check_user_session();
$User->check_module_permissions("racks", User::ACCESS_R, true, true);
$User->check_module_permissions("devices", User::ACCESS_R, true, true);

header('Content-Type: application/json; charset=utf-8');

function rack_v2_json_error($message, $status = 400) {
    http_response_code($status);
    echo json_encode(array(
        "success" => false,
        "message" => $message,
        "positions" => array()
    ));
    exit;
}

if (!is_numeric($POST->rackid)) {
    rack_v2_json_error("Invalid rack identifier");
}
if (!is_numeric($POST->rack_size)) {
    rack_v2_json_error("Invalid rack size");
}

$rack_id   = (int)$POST->rackid;
$rack_size = (int)$POST->rack_size;
$rack_deep = ($POST->rack_deep == "1") ? 1 : 0;

if ($rack_size < 1) {
    rack_v2_json_error("Rack size must be at least 1U");
}

$rack = $Racks->fetch_rack_details($rack_id);
if (!is_object($rack)) {
    rack_v2_json_error("Rack does not exist", 404);
}

$rack_devices  = $Racks->fetch_rack_devices($rack_id);
$rack_contents = $Racks->fetch_rack_contents($rack_id);

// RACK_V21_DEVICE_FORM
$candidate = array(
    "rack_size" => $rack_size,
    "rack_deep" => $rack_deep
);
$current_device_id = null;
if (isset($POST->deviceid) && $POST->deviceid !== "") {
    if (!is_numeric($POST->deviceid)) rack_v2_json_error("Invalid device identifier");
    $current_device_id = (int)$POST->deviceid;
    $current_device = $User->fetch_object("devices", "id", $current_device_id);
    if ($current_device === false) rack_v2_json_error("Device does not exist", 404);
    $candidate["id"] = $current_device_id;
    $candidate["hostname"] = $current_device->hostname;
}

list($available_front, $available_back) = $Racks->free_u(
    $rack,
    $rack_devices,
    $rack_contents,
    $candidate,
    true
);

$positions = array();

foreach ($available_front as $value => $label) {
    $positions[] = array(
        "value" => (int)$value,
        "label" => (string)$label,
        "side"  => "front"
    );
}

foreach ($available_back as $value => $label) {
    $positions[] = array(
        "value" => (int)$value,
        "label" => (string)$label,
        "side"  => "back"
    );
}

echo json_encode(array(
    "success"    => true,
    "rack_id"    => $rack_id,
    "size"       => $rack_size,
    "full_depth" => (bool)$rack_deep,
    "hasBack"    => (bool)$rack->hasBack,
    "positions"  => $positions
));
