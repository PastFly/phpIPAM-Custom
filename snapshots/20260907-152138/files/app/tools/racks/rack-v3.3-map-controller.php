<?php
/**
 * RACK V3.3.1 - cross-rack drag-and-drop controller.
 */

if (!isset($Racks) || !is_object($Racks) || $Racks->all_racks === false) {
    return;
}

$rack_v33_can_move =
    $User->get_module_permissions("racks") >= User::ACCESS_RW &&
    $User->get_module_permissions("devices") >= User::ACCESS_RW;

$rack_v33_s_page = isset($GET->sPage) ? (string)$GET->sPage : "0";
$rack_v33_racks = array();

foreach ($Racks->all_racks as $rv33_rack) {
    $rv33_location = (
        $rv33_rack->location === "" ||
        is_null($rv33_rack->location) ||
        $User->settings->enableLocations !== "1"
    ) ? "0" : (string)$rv33_rack->location;

    if ($rv33_location !== $rack_v33_s_page) {
        continue;
    }

    $rv33_devices = $Racks->fetch_rack_devices($rv33_rack->id);
    $rv33_contents = $Racks->fetch_rack_contents($rv33_rack->id);

    if (!is_array($rv33_devices)) {
        $rv33_devices = array();
    }
    if (!is_array($rv33_contents)) {
        $rv33_contents = array();
    }

    $items = array();

    foreach ($rv33_devices as $d) {
        if (!is_numeric($d->rack_start)) {
            continue;
        }

        $raw_start = (int)$d->rack_start;
        $side = ($raw_start > (int)$rv33_rack->size) ? "back" : "front";
        $start = ($side === "back")
            ? $raw_start - (int)$rv33_rack->size
            : $raw_start;

        $items[] = array(
            "type" => "device",
            "id" => (int)$d->id,
            "name" => (string)$d->hostname,
            "side" => $side,
            "start" => $start,
            "size" => (is_numeric($d->rack_size) && (int)$d->rack_size > 0)
                ? (int)$d->rack_size
                : 1,
            "deep" => !empty($d->rack_deep),
        );
    }

    foreach ($rv33_contents as $c) {
        if (!is_numeric($c->rack_start)) {
            continue;
        }

        $raw_start = (int)$c->rack_start;
        $side = ($raw_start > (int)$rv33_rack->size) ? "back" : "front";
        $start = ($side === "back")
            ? $raw_start - (int)$rv33_rack->size
            : $raw_start;

        $items[] = array(
            "type" => "content",
            "id" => (int)$c->id,
            "name" => (string)$c->name,
            "side" => $side,
            "start" => $start,
            "size" => (is_numeric($c->rack_size) && (int)$c->rack_size > 0)
                ? (int)$c->rack_size
                : 1,
            "deep" => !empty($c->rack_deep),
        );
    }

    $rack_v33_racks[(string)$rv33_rack->id] = array(
        "id" => (int)$rv33_rack->id,
        "name" => (string)$rv33_rack->name,
        "size" => (int)$rv33_rack->size,
        "hasBack" => ((int)$rv33_rack->hasBack === 1),
        "topDown" => ((int)$rv33_rack->topDown === 1),
        "location" => $rv33_location,
        "items" => $items,
    );
}

$rack_v33_csrf = $User->Crypto->csrf_cookie(
    "create-if-not-exists",
    "rack_v33_cross_move_" . $rack_v33_s_page
);

$rack_v33_json = json_encode(
    $rack_v33_racks,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<style>
#rackV33Toolbar {
    margin: 0 0 14px 0;
    padding: 8px 10px;
    border: 1px solid rgba(127,127,127,.35);
    border-radius: 4px;
}
#rackV33Toolbar .rack-v33-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
#rackV33Status {
    font-size: 12px;
    opacity: .92;
}
#rackV33Status.ok,
#rackV33Status.error {
    font-weight: 600;
}
object.rack-v33-svg.move-mode {
    outline: 2px solid rgba(91,192,222,.60);
    outline-offset: 2px;
}
</style>

<div id="rackV33Toolbar"
     data-location="<?php print htmlspecialchars($rack_v33_s_page, ENT_QUOTES, "UTF-8"); ?>"
     data-can-move="<?php print $rack_v33_can_move ? "1" : "0"; ?>"
     data-csrf="<?php print htmlspecialchars($rack_v33_csrf, ENT_QUOTES, "UTF-8"); ?>">
    <div class="rack-v33-row">
        <?php if ($rack_v33_can_move) { ?>
            <button type="button" class="btn btn-xs btn-default" id="rackV33Toggle">
                <i class="fa fa-arrows"></i>
                Межстоечное перемещение: выключено
            </button>
        <?php } ?>

        <span id="rackV33Status">
            <?php if ($rack_v33_can_move) { ?>
                Включите режим, затем перетащите устройство из одной стойки в другую.
            <?php } else { ?>
                Для перемещения нужны права записи на Racks и Devices.
            <?php } ?>
        </span>
    </div>
</div>

<script>
// RACK_V335_LIVE_RACK_DATA
$(function () {
    var toolbar = document.getElementById("rackV33Toolbar");
    var objects = Array.prototype.slice.call(
        document.querySelectorAll("object.rack-v33-svg")
    );

    if (!toolbar || objects.length === 0) {
        if (toolbar) toolbar.style.display = "none";
        return;
    }

    var first = objects[0];
    var rowHost = first.parentNode;

    if (rowHost && rowHost.parentNode) {
        rowHost.parentNode.insertBefore(toolbar, rowHost);
    }

    var canMove = toolbar.getAttribute("data-can-move") === "1";
    if (!canMove) return;

    var toggle = document.getElementById("rackV33Toggle");
    var status = document.getElementById("rackV33Status");

    var racks = {};
    var locationId = null;
    var csrf = null;
    var moveMode = false;
    var active = null;
    var contexts = [];
    var dataReady = false;

    var visibleRackIds = [];
    objects.forEach(function (object) {
        var id = object.getAttribute("data-rack-id");
        if (id && /^\d+$/.test(id) && visibleRackIds.indexOf(id) === -1) {
            visibleRackIds.push(id);
        }
    });

    var dragLayer = document.createElement("div");
    dragLayer.id = "rackV33DragLayer";
    dragLayer.style.position = "fixed";
    dragLayer.style.left = "0";
    dragLayer.style.top = "0";
    dragLayer.style.right = "0";
    dragLayer.style.bottom = "0";
    dragLayer.style.zIndex = "2147483000";
    dragLayer.style.background = "transparent";
    dragLayer.style.cursor = "grabbing";
    dragLayer.style.display = "none";
    dragLayer.style.touchAction = "none";
    dragLayer.style.userSelect = "none";
    document.body.appendChild(dragLayer);

    function setStatus(message, state) {
        status.classList.remove("ok", "error");
        if (state) status.classList.add(state);
        status.textContent = message;
    }

    function occupiedSides(item, rack) {
        if (item.deep && rack.hasBack) return ["front", "back"];
        return [item.side];
    }

    function itemOccupiesSide(item, rack, side) {
        return occupiedSides(item, rack).indexOf(side) !== -1;
    }

    function overlaps(a1, a2, b1, b2) {
        return a1 <= b2 && b1 <= a2;
    }

    function findCollision(rack, candidate, movingId) {
        var candidateSides = occupiedSides(candidate, rack);
        var items = rack.items || [];

        for (var i = 0; i < items.length; i++) {
            var item = items[i];

            if (
                item.type === "device" &&
                parseInt(item.id, 10) === parseInt(movingId, 10)
            ) {
                continue;
            }

            var itemSides = occupiedSides(item, rack);
            var sameSide = candidateSides.some(function (side) {
                return itemSides.indexOf(side) !== -1;
            });

            if (!sameSide) continue;

            var itemEnd = item.start + item.size - 1;

            if (overlaps(
                candidate.start,
                candidate.end,
                item.start,
                itemEnd
            )) {
                return item;
            }
        }

        return null;
    }

    function deviceAtUnit(rack, side, unit) {
        var items = rack.items || [];
        var matches = [];

        for (var i = 0; i < items.length; i++) {
            var item = items[i];

            if (item.type !== "device") continue;
            if (!itemOccupiesSide(item, rack, side)) continue;

            var end = item.start + item.size - 1;

            if (unit >= item.start && unit <= end) {
                matches.push(item);
            }
        }

        if (matches.length === 1) return matches[0];

        if (matches.length > 1) {
            for (var j = 0; j < matches.length; j++) {
                if (matches[j].side === side) return matches[j];
            }
            return matches[0];
        }

        return null;
    }

    function contentAtUnit(rack, side, unit) {
        var items = rack.items || [];

        for (var i = 0; i < items.length; i++) {
            var item = items[i];

            if (item.type !== "content") continue;
            if (!itemOccupiesSide(item, rack, side)) continue;

            var end = item.start + item.size - 1;

            if (unit >= item.start && unit <= end) return item;
        }

        return null;
    }

    function getSvg(object) {
        try {
            return object.contentDocument
                ? object.contentDocument.querySelector("svg")
                : null;
        } catch (e) {
            return null;
        }
    }

    function collectUnitCenters(svg, rackSize) {
        var grouped = {};

        Array.prototype.slice.call(svg.querySelectorAll("text"))
            .forEach(function (text) {
                var raw = (text.textContent || "").trim();
                if (!/^\d+$/.test(raw)) return;

                var u = parseInt(raw, 10);
                if (u < 1 || u > rackSize) return;

                try {
                    var box = text.getBBox();
                    var y = box.y + box.height / 2;

                    if (!grouped[u]) grouped[u] = [];
                    grouped[u].push(y);
                } catch (e) {}
            });

        var centers = {};

        Object.keys(grouped).forEach(function (key) {
            var values = grouped[key];
            centers[key] =
                values.reduce(function (sum, value) {
                    return sum + value;
                }, 0) / values.length;
        });

        return centers;
    }

    function estimatePitch(centers) {
        var values = Object.keys(centers)
            .map(function (key) { return centers[key]; })
            .sort(function (a, b) { return a - b; });

        var diffs = [];

        for (var i = 1; i < values.length; i++) {
            var diff = Math.abs(values[i] - values[i - 1]);
            if (diff > 0.5) diffs.push(diff);
        }

        if (!diffs.length) return 16;

        diffs.sort(function (a, b) { return a - b; });
        return diffs[Math.floor(diffs.length / 2)];
    }

    function nearestUnit(centers, y) {
        var best = null;
        var distance = Infinity;

        Object.keys(centers).forEach(function (key) {
            var d = Math.abs(centers[key] - y);

            if (d < distance) {
                distance = d;
                best = parseInt(key, 10);
            }
        });

        return best;
    }

    function svgPointFromParent(context, parentX, parentY) {
        var rect = context.object.getBoundingClientRect();

        var point = context.svg.createSVGPoint();
        point.x = parentX - rect.left;
        point.y = parentY - rect.top;

        var matrix = context.svg.getScreenCTM();
        if (!matrix) return null;

        return point.matrixTransform(matrix.inverse());
    }

    function parentPointFromEmbedded(context, event) {
        var rect = context.object.getBoundingClientRect();

        return {
            x: rect.left + event.clientX,
            y: rect.top + event.clientY
        };
    }

    function unitFromEmbeddedEvent(context, event) {
        var point = context.svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;

        var matrix = context.svg.getScreenCTM();
        if (!matrix) return null;

        var p = point.matrixTransform(matrix.inverse());
        return nearestUnit(context.centers, p.y);
    }

    function ensureOverlay(context) {
        var overlay = context.svg.querySelector("#rack-v33-overlay");

        if (!overlay) {
            overlay = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "rect"
            );

            overlay.setAttribute("id", "rack-v33-overlay");
            overlay.setAttribute("pointer-events", "none");
            overlay.setAttribute("visibility", "hidden");
            overlay.setAttribute("opacity", "0.30");
            overlay.setAttribute("rx", "2");
            overlay.setAttribute("ry", "2");

            context.svg.appendChild(overlay);
        }

        context.overlay = overlay;
    }

    function hideAllOverlays() {
        contexts.forEach(function (context) {
            if (context.overlay) {
                context.overlay.setAttribute("visibility", "hidden");
            }
        });
    }

    function paintOverlay(context, candidate, valid) {
        hideAllOverlays();

        var yValues = [];

        for (var u = candidate.start; u <= candidate.end; u++) {
            if (context.centers[u] !== undefined) {
                yValues.push(context.centers[u]);
            }
        }

        if (!yValues.length) return;

        var minY = Math.min.apply(Math, yValues);
        var maxY = Math.max.apply(Math, yValues);
        var pitch = context.pitch;
        var viewBox = context.svg.viewBox.baseVal;

        var width = (
            viewBox && viewBox.width
                ? viewBox.width
                : context.svg.getBBox().width
        );

        context.overlay.setAttribute("x", 0);
        context.overlay.setAttribute("y", minY - pitch / 2);
        context.overlay.setAttribute("width", width);
        context.overlay.setAttribute(
            "height",
            Math.max(pitch, (maxY - minY) + pitch)
        );
        context.overlay.setAttribute(
            "fill",
            valid ? "#5cb85c" : "#d9534f"
        );
        context.overlay.setAttribute("visibility", "visible");
    }

    function contextFromParentPoint(parentX, parentY) {
        for (var i = 0; i < contexts.length; i++) {
            var rect = contexts[i].object.getBoundingClientRect();

            if (
                parentX >= rect.left &&
                parentX <= rect.right &&
                parentY >= rect.top &&
                parentY <= rect.bottom
            ) {
                return contexts[i];
            }
        }

        return null;
    }

    function buildCandidate(context, parentX, parentY) {
        var rack = context.rack;
        var p = svgPointFromParent(context, parentX, parentY);

        if (!p) return null;

        var start = nearestUnit(context.centers, p.y);
        if (!start) return null;

        var end = start + active.item.size - 1;

        var candidate = {
            rackId: rack.id,
            rackName: rack.name,
            side: context.side,
            start: start,
            end: end,
            size: active.item.size,
            deep: !!active.item.deep
        };

        if (start < 1 || end > rack.size) {
            candidate.valid = false;
            candidate.reason =
                "Устройство выходит за границы стойки " + rack.name + ".";
            return candidate;
        }

        if (context.side === "back" && !rack.hasBack) {
            candidate.valid = false;
            candidate.reason =
                "У стойки " + rack.name + " нет Rear стороны.";
            return candidate;
        }

        var blocker = findCollision(rack, candidate, active.item.id);

        if (blocker) {
            candidate.valid = false;
            candidate.reason =
                "Занято в " + rack.name + ": " +
                blocker.name + " (U" + blocker.start +
                (
                    blocker.size > 1
                        ? "–U" + (blocker.start + blocker.size - 1)
                        : ""
                ) +
                ").";
            return candidate;
        }

        candidate.valid = true;
        candidate.dbStart =
            context.side === "back"
                ? rack.size + start
                : start;

        return candidate;
    }

    function updateTarget(parentX, parentY) {
        if (!active) return;

        var targetContext =
            contextFromParentPoint(parentX, parentY);

        if (!targetContext) {
            active.targetContext = null;
            active.candidate = null;
            hideAllOverlays();
            setStatus("Наведите устройство на нужную стойку.");
            return;
        }

        var candidate =
            buildCandidate(targetContext, parentX, parentY);

        if (!candidate) return;

        active.targetContext = targetContext;
        active.candidate = candidate;

        paintOverlay(
            targetContext,
            candidate,
            candidate.valid
        );

        if (candidate.valid) {
            setStatus(
                "Можно разместить: " +
                active.item.name + " → " +
                targetContext.rack.name + " / " +
                (candidate.side === "back" ? "Rear " : "Front ") +
                "U" + candidate.start +
                (candidate.size > 1 ? "–U" + candidate.end : ""),
                "ok"
            );
        } else {
            setStatus(candidate.reason, "error");
        }
    }

    function saveMove(item, sourceRack, candidate) {
        var targetRack = racks[String(candidate.rackId)];

        if (!targetRack) {
            setStatus("Целевая стойка не найдена.", "error");
            return;
        }

        var range =
            "U" + candidate.start +
            (candidate.size > 1 ? "–U" + candidate.end : "");

        var sideLabel =
            candidate.side === "back" ? "Rear" : "Front";

        var question =
            "Переместить " + item.name + "?\n\n" +
            "Из: " + sourceRack.name + "\n" +
            "В:  " + targetRack.name + " / " +
            sideLabel + " / " + range;

        if (!window.confirm(question)) {
            setStatus("Перемещение отменено.");
            return;
        }

        setStatus(
            "Сохранение: " + item.name + " → " +
            targetRack.name + " / " + sideLabel + " / " + range + "…"
        );

        $.ajax({
            url: "app/tools/racks/rack-v3.3-move.php",
            method: "POST",
            dataType: "json",
            timeout: 15000,
            data: {
                action: "move",
                location_id: locationId,
                target_rackid: targetRack.id,
                deviceid: item.id,
                rack_start: candidate.dbStart,
                csrf_cookie: csrf
            }
        })
        .done(function (response) {
            if (!response || response.success !== true) {
                setStatus(
                    response && response.message
                        ? response.message
                        : "Не удалось сохранить перенос.",
                    "error"
                );
                return;
            }

            setStatus(
                response.device + " перемещён: " +
                response.source_rack + " → " +
                response.target_rack + " / " +
                response.side + " / " +
                response.position_label + ".",
                "ok"
            );

            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        })
        .fail(function (xhr, state) {
            if (state === "timeout") {
                setStatus(
                    "Сервер не ответил за 15 секунд. Изменение не подтверждено.",
                    "error"
                );
                return;
            }

            var message = "Ошибка сохранения переноса.";

            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.message) {
                    message = response.message;
                }
            } catch (e) {}

            setStatus(message, "error");
        });
    }

    function finishDrag(doSave) {
        if (!active) {
            dragLayer.style.display = "none";
            hideAllOverlays();
            return;
        }

        var final = active;
        active = null;

        dragLayer.style.display = "none";
        hideAllOverlays();

        if (!doSave || !final.moved || !final.candidate) {
            setStatus("Режим межстоечного перемещения включён.");
            return;
        }

        if (!final.candidate.valid) {
            setStatus(final.candidate.reason, "error");
            return;
        }

        saveMove(final.item, final.sourceRack, final.candidate);
    }

    dragLayer.addEventListener("pointermove", function (event) {
        if (!moveMode || !active) return;

        event.preventDefault();
        active.moved = true;
        updateTarget(event.clientX, event.clientY);
    });

    dragLayer.addEventListener("pointerup", function (event) {
        if (!active) return;

        event.preventDefault();
        updateTarget(event.clientX, event.clientY);
        finishDrag(true);
    });

    dragLayer.addEventListener("pointercancel", function () {
        finishDrag(false);
    });

    function initializeObjects() {
        contexts = [];

        objects.forEach(function (object) {
            var rackId = String(object.getAttribute("data-rack-id"));
            var side = object.getAttribute("data-rack-side") || "front";
            var rack = racks[rackId];
            var svg = getSvg(object);

            if (!rack || !svg) return;

            var centers = collectUnitCenters(svg, rack.size);

            if (Object.keys(centers).length < Math.min(3, rack.size)) {
                setStatus(
                    "Не удалось определить U в стойке " + rack.name + ".",
                    "error"
                );
                return;
            }

            var context = {
                object: object,
                svg: svg,
                rack: rack,
                side: side,
                centers: centers,
                pitch: estimatePitch(centers),
                overlay: null
            };

            ensureOverlay(context);
            contexts.push(context);

            if (svg.getAttribute("data-v335-bound") === "1") {
                return;
            }

            svg.setAttribute("data-v335-bound", "1");

            svg.addEventListener(
                "click",
                function (event) {
                    if (moveMode) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                },
                true
            );

            svg.addEventListener(
                "pointerdown",
                function (event) {
                    if (!moveMode || !dataReady) return;

                    if (
                        event.button !== undefined &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    var unit = unitFromEmbeddedEvent(context, event);
                    if (!unit) return;

                    var item = deviceAtUnit(rack, side, unit);

                    if (!item) {
                        var custom = contentAtUnit(rack, side, unit);

                        if (custom) {
                            event.preventDefault();
                            event.stopPropagation();

                            setStatus(
                                "U" + unit +
                                " занят пользовательским объектом: " +
                                custom.name + ".",
                                "error"
                            );
                        }
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    var parentPoint =
                        parentPointFromEmbedded(context, event);

                    active = {
                        item: item,
                        sourceRack: rack,
                        sourceContext: context,
                        moved: false,
                        candidate: null,
                        targetContext: null
                    };

                    setStatus(
                        "Захвачено: " +
                        item.name + " из " +
                        rack.name + " / " +
                        (side === "back" ? "Rear" : "Front") +
                        " / U" + item.start +
                        (
                            item.size > 1
                                ? "–U" + (item.start + item.size - 1)
                                : ""
                        ) +
                        ". Перетащите в целевую стойку."
                    );

                    dragLayer.style.display = "block";
                    updateTarget(parentPoint.x, parentPoint.y);
                },
                true
            );
        });
    }

    function countDevices() {
        var count = 0;

        Object.keys(racks).forEach(function (rackId) {
            (racks[rackId].items || []).forEach(function (item) {
                if (item.type === "device") count++;
            });
        });

        return count;
    }

    function loadRackData() {
        setStatus(
            "Загрузка данных для видимых стоек: " +
            visibleRackIds.length + "…"
        );

        $.ajax({
            url: "app/tools/racks/rack-v3.3-data.php",
            method: "POST",
            dataType: "json",
            timeout: 15000,
            data: {
                rack_ids: visibleRackIds.join(",")
            }
        })
        .done(function (response) {
            if (!response || response.success !== true) {
                setStatus(
                    response && response.message
                        ? response.message
                        : "Не удалось загрузить данные стоек.",
                    "error"
                );
                return;
            }

            racks = response.racks || {};
            locationId = String(response.location_id);
            csrf = response.csrf_cookie;
            dataReady = true;

            initializeObjects();

            setStatus(
                "Данные загружены. Стоек: " +
                Object.keys(racks).length +
                ", устройств: " +
                countDevices() +
                ". Включите режим перемещения."
            );
        })
        .fail(function (xhr, state) {
            if (state === "timeout") {
                setStatus(
                    "Сервер не ответил при загрузке данных стоек.",
                    "error"
                );
                return;
            }

            var message = "Ошибка загрузки данных стоек.";

            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.message) {
                    message = response.message;
                }
            } catch (e) {}

            setStatus(message, "error");
        });
    }

    objects.forEach(function (object) {
        object.addEventListener("load", function () {
            if (dataReady) initializeObjects();
        });
    });

    toggle.addEventListener("click", function () {
        if (!dataReady) {
            setStatus(
                "Данные стоек ещё не загружены.",
                "error"
            );
            return;
        }

        moveMode = !moveMode;

        objects.forEach(function (object) {
            object.classList.toggle("move-mode", moveMode);
        });

        if (moveMode) {
            toggle.classList.remove("btn-default");
            toggle.classList.add("btn-info");
            toggle.innerHTML =
                '<i class="fa fa-arrows"></i> ' +
                'Межстоечное перемещение: включено';

            setStatus(
                "Режим включён. Устройств: " +
                countDevices() +
                ". Нажмите на оборудование и перетащите его в другую стойку."
            );
        } else {
            toggle.classList.remove("btn-info");
            toggle.classList.add("btn-default");
            toggle.innerHTML =
                '<i class="fa fa-arrows"></i> ' +
                'Межстоечное перемещение: выключено';

            active = null;
            dragLayer.style.display = "none";
            hideAllOverlays();

            setStatus(
                "Обычный режим: ссылки на устройства работают."
            );
        }
    });

    loadRackData();
});
</script>
