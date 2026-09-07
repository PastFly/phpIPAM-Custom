<?php
/**
 * RACK V3.3.1 cross-rack move endpoint.
 * Same Location only.
 */

require_once(dirname(__FILE__) . '/../../../functions/functions.php');

@set_time_limit(20);

$Database = new Database_PDO;
$User     = new User($Database);
$Admin    = new Admin($Database, false);
$Racks    = new phpipam_rack($Database);

header("Content-Type: application/json; charset=utf-8");

function rack_v33_reply(
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

if (
    $User->get_module_permissions("racks") < User::ACCESS_RW ||
    $User->get_module_permissions("devices") < User::ACCESS_RW
) {
    rack_v33_reply(
        false,
        "Недостаточно прав для перемещения устройства.",
        array(),
        403
    );
}

$User->check_maintaneance_mode();

if ((string)$POST->action !== "move") {
    rack_v33_reply(
        false,
        "Некорректное действие.",
        array(),
        400
    );
}

if (!is_numeric($POST->target_rackid)) {
    rack_v33_reply(
        false,
        "Некорректная целевая стойка.",
        array(),
        400
    );
}

if (!is_numeric($POST->deviceid)) {
    rack_v33_reply(
        false,
        "Некорректное устройство.",
        array(),
        400
    );
}

if (!is_numeric($POST->rack_start)) {
    rack_v33_reply(
        false,
        "Некорректная позиция.",
        array(),
        400
    );
}

$location_id =
    (string)$POST->location_id;

$target_rack_id =
    (int)$POST->target_rackid;

$device_id =
    (int)$POST->deviceid;

$new_start =
    (int)$POST->rack_start;

if (
    $User->Crypto->csrf_cookie(
        "validate",
        "rack_v33_cross_move_" . $location_id,
        $POST->csrf_cookie
    ) === false
) {
    rack_v33_reply(
        false,
        "Некорректный CSRF token.",
        array(),
        403
    );
}

$device =
    $Admin->fetch_object(
        "devices",
        "id",
        $device_id
    );

if (!is_object($device)) {
    rack_v33_reply(
        false,
        "Устройство не найдено.",
        array(),
        404
    );
}

if (
    !is_numeric($device->rack) ||
    (int)$device->rack < 1
) {
    rack_v33_reply(
        false,
        "Устройство больше не привязано к стойке. Обновите страницу.",
        array(),
        409
    );
}

$source_rack =
    $Racks->fetch_rack_details(
        (int)$device->rack
    );

$target_rack =
    $Racks->fetch_rack_details(
        $target_rack_id
    );

if (!is_object($source_rack)) {
    rack_v33_reply(
        false,
        "Исходная стойка не найдена.",
        array(),
        409
    );
}

if (!is_object($target_rack)) {
    rack_v33_reply(
        false,
        "Целевая стойка не найдена.",
        array(),
        404
    );
}

$normalize_location =
    function ($rack) use ($User) {
        if (
            $User->settings->enableLocations !== "1" ||
            $rack->location === "" ||
            is_null($rack->location)
        ) {
            return "0";
        }

        return (string)$rack->location;
    };

$source_location =
    $normalize_location($source_rack);

$target_location =
    $normalize_location($target_rack);

if (
    $source_location !==
    $target_location
) {
    rack_v33_reply(
        false,
        "V3.3 разрешает перенос только между стойками одного местоположения.",
        array(),
        409
    );
}

if (
    $target_location !==
    $location_id
) {
    rack_v33_reply(
        false,
        "Целевая стойка не относится к текущему местоположению.",
        array(),
        409
    );
}

if (
    !is_numeric($device->rack_size) ||
    (int)$device->rack_size < 1
) {
    rack_v33_reply(
        false,
        "У устройства некорректный размер в U.",
        array(),
        409
    );
}

$size =
    (int)$device->rack_size;

$deep =
    !empty($device->rack_deep)
        ? 1
        : 0;

if (
    $new_start >
        (int)$target_rack->size &&
    (int)$target_rack->hasBack !== 1
) {
    rack_v33_reply(
        false,
        "У целевой стойки нет Rear стороны.",
        array(),
        409
    );
}

if (
    $Racks->check_device_overflow(
        $target_rack_id,
        $new_start,
        $size
    )
) {
    rack_v33_reply(
        false,
        "Устройство выходит за границы целевой стойки.",
        array(),
        409
    );
}

if (
    $Racks->check_device_overlap(
        $target_rack_id,
        $new_start,
        $size,
        $deep,
        $device_id
    )
) {
    rack_v33_reply(
        false,
        "Целевая позиция пересекается с существующим объектом.",
        array(),
        409
    );
}

$old_rack_id =
    (int)$device->rack;

$old_start =
    (int)$device->rack_start;

if (
    $old_rack_id !== $target_rack_id ||
    $old_start !== $new_start
) {
    $values = array(
        "id" => $device_id,
        "rack" => $target_rack_id,
        "rack_start" => $new_start
    );

    if (
        function_exists("session_status") &&
        session_status() ===
            PHP_SESSION_ACTIVE
    ) {
        session_write_close();
    }

    if (
        !$Admin->object_modify(
            "devices",
            "edit",
            "id",
            $values
        )
    ) {
        rack_v33_reply(
            false,
            "Не удалось сохранить перенос устройства.",
            array(),
            500
        );
    }
}

$physical_start =
    ($new_start > (int)$target_rack->size)
        ? $new_start -
          (int)$target_rack->size
        : $new_start;

$physical_end =
    $physical_start +
    $size -
    1;

$side =
    ($new_start > (int)$target_rack->size)
        ? "Rear"
        : "Front";

$position_label =
    ($size === 1)
        ? "U" . $physical_start
        : "U" .
          $physical_start .
          "–U" .
          $physical_end;

rack_v33_reply(
    true,
    "Устройство перемещено.",
    array(
        "device_id" => $device_id,
        "device" => $device->hostname,
        "source_rack_id" => $old_rack_id,
        "source_rack" => $source_rack->name,
        "target_rack_id" => $target_rack_id,
        "target_rack" => $target_rack->name,
        "side" => $side,
        "position_label" => $position_label,
        "rack_start" => $new_start,
        "rack_size" => $size
    )
);
