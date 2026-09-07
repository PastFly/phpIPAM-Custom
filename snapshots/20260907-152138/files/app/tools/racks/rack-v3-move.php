<?php
/**
 * RACK V3.1 single-request move endpoint.
 */

require_once(dirname(__FILE__) . '/../../../functions/functions.php');

@set_time_limit(20);

$Database = new Database_PDO;
$User     = new User($Database);
$Admin    = new Admin($Database, false);
$Racks    = new phpipam_rack($Database);

header("Content-Type: application/json; charset=utf-8");

function rack_v31_reply($success, $message, $data = array(), $status = 200) {
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

if (
    $User->get_module_permissions("racks") < User::ACCESS_RW ||
    $User->get_module_permissions("devices") < User::ACCESS_RW
) {
    rack_v31_reply(false, "Недостаточно прав для перемещения устройства.", array(), 403);
}

$User->check_maintaneance_mode();

if ((string)$POST->action !== "move") {
    rack_v31_reply(false, "Некорректное действие.", array(), 400);
}
if (!is_numeric($POST->rackid)) {
    rack_v31_reply(false, "Некорректный идентификатор шкафа.", array(), 400);
}
if (!is_numeric($POST->deviceid)) {
    rack_v31_reply(false, "Некорректный идентификатор устройства.", array(), 400);
}
if (!is_numeric($POST->rack_start)) {
    rack_v31_reply(false, "Некорректная позиция.", array(), 400);
}

$rack_id = (int)$POST->rackid;
$device_id = (int)$POST->deviceid;
$new_start = (int)$POST->rack_start;

if (
    $User->Crypto->csrf_cookie(
        "validate",
        "rack_v31_move_" . $rack_id,
        $POST->csrf_cookie
    ) === false
) {
    rack_v31_reply(false, "Некорректный CSRF token.", array(), 403);
}

$rack = $Racks->fetch_rack_details($rack_id);
if (!is_object($rack)) {
    rack_v31_reply(false, "Шкаф не найден.", array(), 404);
}

$device = $Admin->fetch_object("devices", "id", $device_id);
if (!is_object($device)) {
    rack_v31_reply(false, "Устройство не найдено.", array(), 404);
}

if ((int)$device->rack !== $rack_id) {
    rack_v31_reply(
        false,
        "Устройство уже не находится в этом шкафу. Обновите страницу.",
        array(),
        409
    );
}

if (!is_numeric($device->rack_size) || (int)$device->rack_size < 1) {
    rack_v31_reply(false, "У устройства некорректный размер в U.", array(), 409);
}

$size = (int)$device->rack_size;
$deep = !empty($device->rack_deep) ? 1 : 0;

if ($Racks->check_device_overflow($rack_id, $new_start, $size)) {
    rack_v31_reply(false, "Устройство выходит за границы шкафа.", array(), 409);
}

if (
    $Racks->check_device_overlap(
        $rack_id,
        $new_start,
        $size,
        $deep,
        $device_id
    )
) {
    rack_v31_reply(
        false,
        "Новая позиция пересекается с существующим объектом шкафа.",
        array(),
        409
    );
}

if ((int)$device->rack_start !== $new_start) {
    $values = array(
        "id" => $device_id,
        "rack_start" => $new_start
    );

    // Release the PHP session lock before the database write.
    if (function_exists("session_status") && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (!$Admin->object_modify("devices", "edit", "id", $values)) {
        rack_v31_reply(
            false,
            "Не удалось сохранить новую позицию устройства.",
            array(),
            500
        );
    }
}

$physical_start = ($new_start > (int)$rack->size)
    ? $new_start - (int)$rack->size
    : $new_start;

$physical_end = $physical_start + $size - 1;
$side = ($new_start > (int)$rack->size) ? "Rear" : "Front";
$position_label = ($size === 1)
    ? "U" . $physical_start
    : "U" . $physical_start . "–U" . $physical_end;

rack_v31_reply(
    true,
    "Позиция устройства обновлена.",
    array(
        "device_id" => $device_id,
        "device" => $device->hostname,
        "side" => $side,
        "position_label" => $position_label,
        "rack_start" => $new_start,
        "rack_size" => $size
    )
);
