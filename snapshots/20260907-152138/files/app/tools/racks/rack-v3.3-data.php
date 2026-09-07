<?php
/**
 * RACK V3.3.5 - live data endpoint for visible rack IDs.
 */

require_once(dirname(__FILE__) . '/../../../functions/functions.php');

$Database = new Database_PDO;
$User     = new User($Database);
$Racks    = new phpipam_rack($Database);

header("Content-Type: application/json; charset=utf-8");

function rack_v335_reply(
    $success,
    $message,
    $data = array(),
    $status = 200
) {
    http_response_code($status);

    echo json_encode(
        array_merge(
            array(
                "success" => (bool)$success,
                "message" => $message
            ),
            $data
        )
    );

    exit;
}

$User->check_user_session();
$User->check_module_permissions("racks", User::ACCESS_R, true, true);
$User->check_module_permissions("devices", User::ACCESS_R, true, true);

$raw = isset($POST->rack_ids)
    ? (string)$POST->rack_ids
    : "";

if ($raw === "") {
    rack_v335_reply(
        false,
        "Не переданы идентификаторы стоек.",
        array(),
        400
    );
}

$parts = explode(",", $raw);
$rack_ids = array();

foreach ($parts as $part) {
    $part = trim($part);

    if ($part === "") {
        continue;
    }

    if (!ctype_digit($part)) {
        rack_v335_reply(
            false,
            "Некорректный идентификатор стойки.",
            array(),
            400
        );
    }

    $id = (int)$part;

    if ($id < 1) {
        rack_v335_reply(
            false,
            "Некорректный идентификатор стойки.",
            array(),
            400
        );
    }

    $rack_ids[$id] = $id;
}

$rack_ids = array_values($rack_ids);

if (count($rack_ids) < 1) {
    rack_v335_reply(
        false,
        "Список стоек пуст.",
        array(),
        400
    );
}

if (count($rack_ids) > 100) {
    rack_v335_reply(
        false,
        "Слишком много стоек в одном запросе.",
        array(),
        400
    );
}

$normalize_location = function ($rack) use ($User) {
    if (
        $User->settings->enableLocations !== "1" ||
        $rack->location === "" ||
        is_null($rack->location)
    ) {
        return "0";
    }

    return (string)$rack->location;
};

$result = array();
$location_id = null;

foreach ($rack_ids as $rack_id) {
    $rack = $Racks->fetch_rack_details($rack_id);

    if (!is_object($rack)) {
        rack_v335_reply(
            false,
            "Одна из видимых стоек больше не существует.",
            array("rack_id" => $rack_id),
            404
        );
    }

    $rack_location = $normalize_location($rack);

    if ($location_id === null) {
        $location_id = $rack_location;
    } elseif ($location_id !== $rack_location) {
        rack_v335_reply(
            false,
            "На странице обнаружены стойки из разных местоположений.",
            array(),
            409
        );
    }

    $devices = $Racks->fetch_rack_devices($rack_id);
    $contents = $Racks->fetch_rack_contents($rack_id);

    if (!is_array($devices)) {
        $devices = array();
    }

    if (!is_array($contents)) {
        $contents = array();
    }

    $items = array();

    foreach ($devices as $device) {
        if (
            !isset($device->id) ||
            !is_numeric($device->id) ||
            !isset($device->rack_start) ||
            !is_numeric($device->rack_start)
        ) {
            continue;
        }

        $raw_start = (int)$device->rack_start;
        $side = ($raw_start > (int)$rack->size)
            ? "back"
            : "front";

        $start = ($side === "back")
            ? $raw_start - (int)$rack->size
            : $raw_start;

        $size = (
            isset($device->rack_size) &&
            is_numeric($device->rack_size) &&
            (int)$device->rack_size > 0
        ) ? (int)$device->rack_size : 1;

        $items[] = array(
            "type" => "device",
            "id" => (int)$device->id,
            "name" => isset($device->hostname)
                ? (string)$device->hostname
                : "Device #" . (int)$device->id,
            "side" => $side,
            "start" => $start,
            "size" => $size,
            "deep" => !empty($device->rack_deep),
        );
    }

    foreach ($contents as $content) {
        if (
            !isset($content->id) ||
            !is_numeric($content->id) ||
            !isset($content->rack_start) ||
            !is_numeric($content->rack_start)
        ) {
            continue;
        }

        $raw_start = (int)$content->rack_start;
        $side = ($raw_start > (int)$rack->size)
            ? "back"
            : "front";

        $start = ($side === "back")
            ? $raw_start - (int)$rack->size
            : $raw_start;

        $size = (
            isset($content->rack_size) &&
            is_numeric($content->rack_size) &&
            (int)$content->rack_size > 0
        ) ? (int)$content->rack_size : 1;

        $items[] = array(
            "type" => "content",
            "id" => (int)$content->id,
            "name" => isset($content->name)
                ? (string)$content->name
                : "Rack object #" . (int)$content->id,
            "side" => $side,
            "start" => $start,
            "size" => $size,
            "deep" => !empty($content->rack_deep),
        );
    }

    $result[(string)$rack_id] = array(
        "id" => (int)$rack->id,
        "name" => (string)$rack->name,
        "size" => (int)$rack->size,
        "hasBack" => ((int)$rack->hasBack === 1),
        "topDown" => ((int)$rack->topDown === 1),
        "location" => $rack_location,
        "items" => $items,
        "device_count" => count($devices),
        "content_count" => count($contents),
    );
}

$csrf = $User->Crypto->csrf_cookie(
    "create-if-not-exists",
    "rack_v33_cross_move_" . $location_id
);

rack_v335_reply(
    true,
    "Данные стоек загружены.",
    array(
        "location_id" => $location_id,
        "csrf_cookie" => $csrf,
        "racks" => $result
    )
);
