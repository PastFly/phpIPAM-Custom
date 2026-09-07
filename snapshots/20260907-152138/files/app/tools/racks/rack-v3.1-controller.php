<?php
/**
 * RACK V3.1 native SVG drag-and-drop controller.
 *
 * The existing phpIPAM SVG rack remains the primary visualization.
 * This file only adds a compact move-mode toolbar and JS behavior.
 */

if (!isset($rack) || !is_object($rack)) {
    return;
}

$rack_v31_can_move =
    $User->get_module_permissions("racks") >= User::ACCESS_RW &&
    $User->get_module_permissions("devices") >= User::ACCESS_RW;

$rack_v31_csrf = $User->Crypto->csrf_cookie(
    "create-if-not-exists",
    "rack_v31_move_" . $rack->id
);

$rack_v31_devices = is_array($rack_devices) ? $rack_devices : array();
$rack_v31_contents = is_array($rack_contents) ? $rack_contents : array();

$rack_v31_items = array();

foreach ($rack_v31_devices as $d) {
    if (!is_numeric($d->rack_start) || !is_numeric($d->rack_size)) {
        continue;
    }

    $raw_start = (int)$d->rack_start;
    $side = ($raw_start > (int)$rack->size) ? "back" : "front";
    $start = ($side === "back") ? $raw_start - (int)$rack->size : $raw_start;

    $rack_v31_items[] = array(
        "type" => "device",
        "id" => (int)$d->id,
        "name" => (string)$d->hostname,
        "side" => $side,
        "start" => $start,
        "size" => max(1, (int)$d->rack_size),
        "deep" => !empty($d->rack_deep)
    );
}

foreach ($rack_v31_contents as $c) {
    if (!is_numeric($c->rack_start) || !is_numeric($c->rack_size)) {
        continue;
    }

    $raw_start = (int)$c->rack_start;
    $side = ($raw_start > (int)$rack->size) ? "back" : "front";
    $start = ($side === "back") ? $raw_start - (int)$rack->size : $raw_start;

    $rack_v31_items[] = array(
        "type" => "content",
        "id" => (int)$c->id,
        "name" => (string)$c->name,
        "side" => $side,
        "start" => $start,
        "size" => max(1, (int)$c->rack_size),
        "deep" => !empty($c->rack_deep)
    );
}

$rack_v31_json = json_encode(
    $rack_v31_items,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<style>
#rackV31Toolbar {
    display: none;
    margin: 0 0 10px 0;
    padding: 8px 10px;
    border: 1px solid rgba(127,127,127,.35);
    border-radius: 4px;
}
#rackV31Toolbar .rack-v31-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
#rackV31Status {
    font-size: 12px;
    opacity: .9;
}
#rackV31Status.ok {
    font-weight: 600;
}
#rackV31Status.error {
    font-weight: 600;
}
.rack-v31-svg.move-mode {
    outline: 2px solid rgba(91,192,222,.55);
    outline-offset: 3px;
}
</style>

<div id="rackV31Toolbar"
     data-rack-id="<?php print (int)$rack->id; ?>"
     data-rack-size="<?php print (int)$rack->size; ?>"
     data-has-back="<?php print ((int)$rack->hasBack === 1) ? "1" : "0"; ?>"
     data-can-move="<?php print $rack_v31_can_move ? "1" : "0"; ?>"
     data-csrf="<?php print htmlspecialchars($rack_v31_csrf, ENT_QUOTES, "UTF-8"); ?>">
    <div class="rack-v31-row">
        <?php if ($rack_v31_can_move) { ?>
            <button type="button" class="btn btn-xs btn-default" id="rackV31Toggle">
                <i class="fa fa-arrows"></i>
                Режим перемещения: выключен
            </button>
        <?php } ?>
        <span id="rackV31Status">
            <?php if ($rack_v31_can_move) { ?>
                Включите режим перемещения, затем перетащите устройство прямо в штатном изображении шкафа.
            <?php } else { ?>
                Для drag-and-drop нужны права записи на Racks и Devices.
            <?php } ?>
        </span>
    </div>
</div>

<script>
// RACK_V314_PARENT_FRONT_REAR
(function () {
    var objects = Array.prototype.slice.call(
        document.querySelectorAll("object.rack-v31-svg")
    );

    var toolbar = document.getElementById("rackV31Toolbar");

    if (!toolbar || objects.length === 0) {
        return;
    }

    var firstObject = objects[0];
    var host = firstObject.parentNode;

    if (host) {
        host.insertBefore(toolbar, firstObject);
        toolbar.style.display = "block";
    }

    var canMove =
        toolbar.getAttribute("data-can-move") === "1";

    if (!canMove) {
        return;
    }

    var rackId =
        parseInt(
            toolbar.getAttribute("data-rack-id"),
            10
        );

    var rackSize =
        parseInt(
            toolbar.getAttribute("data-rack-size"),
            10
        );

    var hasBack =
        toolbar.getAttribute("data-has-back") === "1";

    var csrf =
        toolbar.getAttribute("data-csrf");

    var items =
        <?php print $rack_v31_json ?: "[]"; ?>;

    var toggle =
        document.getElementById("rackV31Toggle");

    var status =
        document.getElementById("rackV31Status");

    var moveMode = false;
    var active = null;
    var contexts = [];

    // Parent-page layer is the key fix:
    // pointer movement is no longer trapped inside Front or Rear SVG.
    var dragLayer =
        document.createElement("div");

    dragLayer.id =
        "rackV31DragLayer";

    dragLayer.style.position =
        "fixed";

    dragLayer.style.left =
        "0";

    dragLayer.style.top =
        "0";

    dragLayer.style.right =
        "0";

    dragLayer.style.bottom =
        "0";

    dragLayer.style.zIndex =
        "2147483000";

    dragLayer.style.background =
        "transparent";

    dragLayer.style.cursor =
        "grabbing";

    dragLayer.style.display =
        "none";

    dragLayer.style.touchAction =
        "none";

    dragLayer.style.userSelect =
        "none";

    document.body.appendChild(
        dragLayer
    );

    function setStatus(
        message,
        state
    ) {
        status.classList.remove(
            "ok",
            "error"
        );

        if (state) {
            status.classList.add(state);
        }

        status.textContent =
            message;
    }

    function occupiedSides(item) {
        if (
            item.deep &&
            hasBack
        ) {
            return [
                "front",
                "back"
            ];
        }

        return [item.side];
    }

    function itemOccupiesSide(
        item,
        side
    ) {
        return (
            occupiedSides(item)
                .indexOf(side) !== -1
        );
    }

    function overlaps(
        a1,
        a2,
        b1,
        b2
    ) {
        return (
            a1 <= b2 &&
            b1 <= a2
        );
    }

    function deviceAtUnit(
        side,
        unit
    ) {
        var matches = [];

        for (
            var i = 0;
            i < items.length;
            i++
        ) {
            var item = items[i];

            if (
                item.type !==
                "device"
            ) {
                continue;
            }

            if (
                !itemOccupiesSide(
                    item,
                    side
                )
            ) {
                continue;
            }

            var end =
                item.start +
                item.size -
                1;

            if (
                unit >= item.start &&
                unit <= end
            ) {
                matches.push(item);
            }
        }

        if (
            matches.length === 1
        ) {
            return matches[0];
        }

        if (
            matches.length > 1
        ) {
            // Prefer the device whose native side
            // equals the clicked side.
            for (
                var j = 0;
                j < matches.length;
                j++
            ) {
                if (
                    matches[j].side === side
                ) {
                    return matches[j];
                }
            }

            return matches[0];
        }

        return null;
    }

    function contentAtUnit(
        side,
        unit
    ) {
        for (
            var i = 0;
            i < items.length;
            i++
        ) {
            var item = items[i];

            if (
                item.type !==
                "content"
            ) {
                continue;
            }

            if (
                !itemOccupiesSide(
                    item,
                    side
                )
            ) {
                continue;
            }

            var end =
                item.start +
                item.size -
                1;

            if (
                unit >= item.start &&
                unit <= end
            ) {
                return item;
            }
        }

        return null;
    }

    function findCollision(
        candidate,
        movingId
    ) {
        var candidateSides =
            occupiedSides(candidate);

        for (
            var i = 0;
            i < items.length;
            i++
        ) {
            var item = items[i];

            if (
                item.type === "device" &&
                parseInt(
                    item.id,
                    10
                ) ===
                parseInt(
                    movingId,
                    10
                )
            ) {
                continue;
            }

            var itemSides =
                occupiedSides(item);

            var sameSide =
                candidateSides.some(
                    function (side) {
                        return (
                            itemSides.indexOf(
                                side
                            ) !== -1
                        );
                    }
                );

            if (!sameSide) {
                continue;
            }

            var itemEnd =
                item.start +
                item.size -
                1;

            if (
                overlaps(
                    candidate.start,
                    candidate.end,
                    item.start,
                    itemEnd
                )
            ) {
                return item;
            }
        }

        return null;
    }

    function getSvg(object) {
        try {
            return (
                object.contentDocument
                    ? object.contentDocument
                        .querySelector("svg")
                    : null
            );
        } catch (e) {
            return null;
        }
    }

    function collectUnitCenters(
        svg
    ) {
        var grouped = {};

        Array.prototype.slice.call(
            svg.querySelectorAll("text")
        )
        .forEach(
            function (textNode) {
                var raw =
                    (
                        textNode.textContent ||
                        ""
                    ).trim();

                if (
                    !/^\d+$/.test(raw)
                ) {
                    return;
                }

                var u =
                    parseInt(
                        raw,
                        10
                    );

                if (
                    u < 1 ||
                    u > rackSize
                ) {
                    return;
                }

                try {
                    var box =
                        textNode.getBBox();

                    var y =
                        box.y +
                        box.height / 2;

                    if (!grouped[u]) {
                        grouped[u] = [];
                    }

                    grouped[u].push(y);
                } catch (e) {}
            }
        );

        var centers = {};

        Object.keys(grouped)
            .forEach(
                function (key) {
                    var values =
                        grouped[key];

                    centers[key] =
                        values.reduce(
                            function (
                                sum,
                                value
                            ) {
                                return (
                                    sum +
                                    value
                                );
                            },
                            0
                        ) /
                        values.length;
                }
            );

        return centers;
    }

    function estimatePitch(
        centers
    ) {
        var values =
            Object.keys(centers)
                .map(
                    function (key) {
                        return centers[key];
                    }
                )
                .sort(
                    function (a, b) {
                        return a - b;
                    }
                );

        var diffs = [];

        for (
            var i = 1;
            i < values.length;
            i++
        ) {
            var diff =
                Math.abs(
                    values[i] -
                    values[i - 1]
                );

            if (diff > 0.5) {
                diffs.push(diff);
            }
        }

        if (!diffs.length) {
            return 16;
        }

        diffs.sort(
            function (a, b) {
                return a - b;
            }
        );

        return diffs[
            Math.floor(
                diffs.length / 2
            )
        ];
    }

    function nearestUnit(
        centers,
        y
    ) {
        var best = null;
        var distance = Infinity;

        Object.keys(centers)
            .forEach(
                function (key) {
                    var d =
                        Math.abs(
                            centers[key] -
                            y
                        );

                    if (
                        d < distance
                    ) {
                        distance = d;
                        best =
                            parseInt(
                                key,
                                10
                            );
                    }
                }
            );

        return best;
    }

    function parentPointFromEmbedded(
        context,
        event
    ) {
        var rect =
            context.object
                .getBoundingClientRect();

        return {
            x:
                rect.left +
                event.clientX,
            y:
                rect.top +
                event.clientY
        };
    }

    function svgPointFromParent(
        context,
        parentX,
        parentY
    ) {
        var rect =
            context.object
                .getBoundingClientRect();

        var point =
            context.svg
                .createSVGPoint();

        point.x =
            parentX -
            rect.left;

        point.y =
            parentY -
            rect.top;

        var matrix =
            context.svg
                .getScreenCTM();

        if (!matrix) {
            return null;
        }

        return point.matrixTransform(
            matrix.inverse()
        );
    }

    function unitFromEmbeddedEvent(
        context,
        event
    ) {
        var point =
            context.svg
                .createSVGPoint();

        point.x =
            event.clientX;

        point.y =
            event.clientY;

        var matrix =
            context.svg
                .getScreenCTM();

        if (!matrix) {
            return null;
        }

        var p =
            point.matrixTransform(
                matrix.inverse()
            );

        return nearestUnit(
            context.centers,
            p.y
        );
    }

    function ensureOverlay(
        context
    ) {
        var overlay =
            context.svg
                .querySelector(
                    "#rack-v31-overlay"
                );

        if (!overlay) {
            overlay =
                document.createElementNS(
                    "http://www.w3.org/2000/svg",
                    "rect"
                );

            overlay.setAttribute(
                "id",
                "rack-v31-overlay"
            );

            overlay.setAttribute(
                "pointer-events",
                "none"
            );

            overlay.setAttribute(
                "visibility",
                "hidden"
            );

            overlay.setAttribute(
                "opacity",
                "0.30"
            );

            overlay.setAttribute(
                "rx",
                "2"
            );

            overlay.setAttribute(
                "ry",
                "2"
            );

            context.svg.appendChild(
                overlay
            );
        }

        context.overlay =
            overlay;
    }

    function hideAllOverlays() {
        contexts.forEach(
            function (context) {
                if (
                    context.overlay
                ) {
                    context.overlay
                        .setAttribute(
                            "visibility",
                            "hidden"
                        );
                }
            }
        );
    }

    function paintOverlay(
        context,
        candidate,
        valid
    ) {
        hideAllOverlays();

        var yValues = [];

        for (
            var u = candidate.start;
            u <= candidate.end;
            u++
        ) {
            if (
                context.centers[u] !==
                undefined
            ) {
                yValues.push(
                    context.centers[u]
                );
            }
        }

        if (!yValues.length) {
            return;
        }

        var minY =
            Math.min.apply(
                Math,
                yValues
            );

        var maxY =
            Math.max.apply(
                Math,
                yValues
            );

        var pitch =
            context.pitch;

        var viewBox =
            context.svg
                .viewBox.baseVal;

        var width =
            (
                viewBox &&
                viewBox.width
            )
                ? viewBox.width
                : context.svg
                    .getBBox()
                    .width;

        context.overlay.setAttribute(
            "x",
            0
        );

        context.overlay.setAttribute(
            "y",
            minY -
            pitch / 2
        );

        context.overlay.setAttribute(
            "width",
            width
        );

        context.overlay.setAttribute(
            "height",
            Math.max(
                pitch,
                (
                    maxY -
                    minY
                ) +
                pitch
            )
        );

        context.overlay.setAttribute(
            "fill",
            valid
                ? "#5cb85c"
                : "#d9534f"
        );

        context.overlay.setAttribute(
            "visibility",
            "visible"
        );
    }

    function contextFromParentPoint(
        parentX,
        parentY
    ) {
        for (
            var i = 0;
            i < contexts.length;
            i++
        ) {
            var rect =
                contexts[i].object
                    .getBoundingClientRect();

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

    function buildCandidate(
        context,
        parentX,
        parentY
    ) {
        var p =
            svgPointFromParent(
                context,
                parentX,
                parentY
            );

        if (!p) {
            return null;
        }

        var start =
            nearestUnit(
                context.centers,
                p.y
            );

        if (!start) {
            return null;
        }

        var end =
            start +
            active.item.size -
            1;

        var candidate = {
            side:
                context.side,
            start:
                start,
            end:
                end,
            size:
                active.item.size,
            deep:
                !!active.item.deep
        };

        if (
            start < 1 ||
            end > rackSize
        ) {
            candidate.valid =
                false;

            candidate.reason =
                "Устройство выходит за границы шкафа.";

            return candidate;
        }

        if (
            context.side === "back" &&
            !hasBack
        ) {
            candidate.valid =
                false;

            candidate.reason =
                "У шкафа нет Rear стороны.";

            return candidate;
        }

        var blocker =
            findCollision(
                candidate,
                active.item.id
            );

        if (blocker) {
            candidate.valid =
                false;

            candidate.reason =
                "Занято: " +
                blocker.name +
                " (U" +
                blocker.start +
                (
                    blocker.size > 1
                        ? "–U" +
                          (
                              blocker.start +
                              blocker.size -
                              1
                          )
                        : ""
                ) +
                ").";

            return candidate;
        }

        candidate.valid =
            true;

        candidate.dbStart =
            context.side === "back"
                ? rackSize +
                  start
                : start;

        return candidate;
    }

    function updateTarget(
        parentX,
        parentY
    ) {
        if (!active) {
            return;
        }

        var targetContext =
            contextFromParentPoint(
                parentX,
                parentY
            );

        if (!targetContext) {
            active.targetContext =
                null;

            active.candidate =
                null;

            hideAllOverlays();

            setStatus(
                "Наведите устройство на Front или Rear."
            );

            return;
        }

        var candidate =
            buildCandidate(
                targetContext,
                parentX,
                parentY
            );

        if (!candidate) {
            return;
        }

        active.targetContext =
            targetContext;

        active.candidate =
            candidate;

        paintOverlay(
            targetContext,
            candidate,
            candidate.valid
        );

        if (
            candidate.valid
        ) {
            setStatus(
                "Можно разместить: " +
                active.item.name +
                " → " +
                (
                    candidate.side ===
                    "back"
                        ? "Rear "
                        : "Front "
                ) +
                "U" +
                candidate.start +
                (
                    candidate.size > 1
                        ? "–U" +
                          candidate.end
                        : ""
                ),
                "ok"
            );
        } else {
            setStatus(
                candidate.reason,
                "error"
            );
        }
    }

    function saveMove(
        item,
        candidate
    ) {
        var range =
            "U" +
            candidate.start +
            (
                candidate.size > 1
                    ? "–U" +
                      candidate.end
                    : ""
            );

        var sideLabel =
            candidate.side === "back"
                ? "Rear"
                : "Front";

        var question =
            "Переместить " +
            item.name +
            "?\n\n" +
            "В: " +
            sideLabel +
            " / " +
            range;

        if (
            !window.confirm(
                question
            )
        ) {
            setStatus(
                "Перемещение отменено."
            );

            return;
        }

        setStatus(
            "Сохранение " +
            item.name +
            " → " +
            sideLabel +
            " / " +
            range +
            "…"
        );

        $.ajax({
            url:
                "app/tools/racks/rack-v3-move.php",

            method:
                "POST",

            dataType:
                "json",

            timeout:
                15000,

            data: {
                action:
                    "move",
                rackid:
                    rackId,
                deviceid:
                    item.id,
                rack_start:
                    candidate.dbStart,
                csrf_cookie:
                    csrf
            }
        })
        .done(
            function (
                response
            ) {
                if (
                    !response ||
                    response.success !==
                        true
                ) {
                    setStatus(
                        response &&
                        response.message
                            ? response.message
                            : "Не удалось сохранить позицию.",
                        "error"
                    );

                    return;
                }

                setStatus(
                    response.device +
                    " перемещён: " +
                    response.side +
                    " " +
                    response.position_label +
                    ".",
                    "ok"
                );

                window.setTimeout(
                    function () {
                        window.location
                            .reload();
                    },
                    300
                );
            }
        )
        .fail(
            function (
                xhr,
                state
            ) {
                if (
                    state ===
                    "timeout"
                ) {
                    setStatus(
                        "Сервер не ответил за 15 секунд.",
                        "error"
                    );

                    return;
                }

                var message =
                    "Ошибка сохранения позиции.";

                try {
                    var response =
                        JSON.parse(
                            xhr.responseText
                        );

                    if (
                        response &&
                        response.message
                    ) {
                        message =
                            response.message;
                    }
                } catch (e) {}

                setStatus(
                    message,
                    "error"
                );
            }
        );
    }

    function finishDrag(
        doSave
    ) {
        if (!active) {
            dragLayer.style.display =
                "none";

            hideAllOverlays();

            return;
        }

        var final =
            active;

        active =
            null;

        dragLayer.style.display =
            "none";

        hideAllOverlays();

        if (
            !doSave ||
            !final.moved ||
            !final.candidate
        ) {
            setStatus(
                "Режим перемещения включён."
            );

            return;
        }

        if (
            !final.candidate.valid
        ) {
            setStatus(
                final.candidate.reason,
                "error"
            );

            return;
        }

        saveMove(
            final.item,
            final.candidate
        );
    }

    dragLayer.addEventListener(
        "pointermove",
        function (event) {
            if (
                !moveMode ||
                !active
            ) {
                return;
            }

            event.preventDefault();

            active.moved =
                true;

            updateTarget(
                event.clientX,
                event.clientY
            );
        }
    );

    dragLayer.addEventListener(
        "pointerup",
        function (event) {
            if (!active) {
                return;
            }

            event.preventDefault();

            updateTarget(
                event.clientX,
                event.clientY
            );

            finishDrag(
                true
            );
        }
    );

    dragLayer.addEventListener(
        "pointercancel",
        function () {
            finishDrag(
                false
            );
        }
    );

    function initializeObject(
        object
    ) {
        function initialize() {
            var side =
                object.getAttribute(
                    "data-rack-side"
                ) || "front";

            var svg =
                getSvg(object);

            if (!svg) {
                return;
            }

            if (
                svg.getAttribute(
                    "data-v313-bound"
                ) === "1"
            ) {
                return;
            }

            var centers =
                collectUnitCenters(
                    svg
                );

            if (
                Object.keys(
                    centers
                ).length <
                Math.min(
                    3,
                    rackSize
                )
            ) {
                setStatus(
                    "Не удалось определить U в SVG шкафа.",
                    "error"
                );

                return;
            }

            var context = {
                object:
                    object,
                svg:
                    svg,
                side:
                    side,
                centers:
                    centers,
                pitch:
                    estimatePitch(
                        centers
                    ),
                overlay:
                    null
            };

            ensureOverlay(
                context
            );

            contexts.push(
                context
            );

            svg.setAttribute(
                "data-v313-bound",
                "1"
            );

            // In move mode suppress normal SVG links.
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

            // Identify source by physical U under mouse.
            svg.addEventListener(
                "pointerdown",
                function (event) {
                    if (!moveMode) {
                        return;
                    }

                    if (
                        event.button !==
                            undefined &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    var unit =
                        unitFromEmbeddedEvent(
                            context,
                            event
                        );

                    if (!unit) {
                        return;
                    }

                    var item =
                        deviceAtUnit(
                            side,
                            unit
                        );

                    if (!item) {
                        var custom =
                            contentAtUnit(
                                side,
                                unit
                            );

                        if (custom) {
                            event.preventDefault();
                            event.stopPropagation();

                            setStatus(
                                "U" +
                                unit +
                                " занят пользовательским объектом: " +
                                custom.name +
                                ".",
                                "error"
                            );
                        }

                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    var parentPoint =
                        parentPointFromEmbedded(
                            context,
                            event
                        );

                    active = {
                        item:
                            item,
                        sourceContext:
                            context,
                        moved:
                            false,
                        candidate:
                            null,
                        targetContext:
                            null
                    };

                    setStatus(
                        "Захвачено: " +
                        item.name +
                        " / " +
                        (
                            side === "back"
                                ? "Rear"
                                : "Front"
                        ) +
                        " / U" +
                        item.start +
                        (
                            item.size > 1
                                ? "–U" +
                                  (
                                      item.start +
                                      item.size -
                                      1
                                  )
                                : ""
                        ) +
                        ". Перетащите на Front или Rear."
                    );

                    dragLayer.style.display =
                        "block";

                    updateTarget(
                        parentPoint.x,
                        parentPoint.y
                    );
                },
                true
            );
        }

        object.addEventListener(
            "load",
            initialize
        );

        window.setTimeout(
            initialize,
            0
        );
    }

    objects.forEach(
        initializeObject
    );

    toggle.addEventListener(
        "click",
        function () {
            moveMode =
                !moveMode;

            objects.forEach(
                function (
                    object
                ) {
                    object.classList
                        .toggle(
                            "move-mode",
                            moveMode
                        );
                }
            );

            if (moveMode) {
                toggle.classList
                    .remove(
                        "btn-default"
                    );

                toggle.classList
                    .add(
                        "btn-info"
                    );

                toggle.innerHTML =
                    '<i class="fa fa-arrows"></i> ' +
                    'Режим перемещения: включён';

                setStatus(
                    "Режим включён. Можно переносить Front ↔ Rear."
                );
            } else {
                toggle.classList
                    .remove(
                        "btn-info"
                    );

                toggle.classList
                    .add(
                        "btn-default"
                    );

                toggle.innerHTML =
                    '<i class="fa fa-arrows"></i> ' +
                    'Режим перемещения: выключен';

                active =
                    null;

                dragLayer.style.display =
                    "none";

                hideAllOverlays();

                setStatus(
                    "Обычный режим: ссылки на устройства работают."
                );
            }
        }
    );
})();
</script>
