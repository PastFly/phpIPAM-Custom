<?php

/**
 *	Edit rack devices script
 ************************/

/* functions */
require_once( dirname(__FILE__) . '/../../../functions/functions.php' );

# initialize user object
$Database 	= new Database_PDO;
$User 		= new User ($Database);
$Admin	 	= new Admin ($Database, false);
$Tools	 	= new Tools ($Database);
$Racks      = new phpipam_rack ($Database);
$Result 	= new Result ();

# verify that user is logged in
$User->check_user_session();
# perm check popup
if ($POST->action == "edit") {
    $User->check_module_permissions("racks", User::ACCESS_RW, true, true);
} else {
    $User->check_module_permissions("racks", User::ACCESS_RWA, true, true);
}
$User->check_module_permissions ("devices", User::ACCESS_R, true, true);

# validate action
$Admin->validate_action();

# ID must be numeric
if($POST->action!="add" && !is_numeric($POST->rackid))		{ $Result->show("danger", _("Invalid ID"), true, true); }

# device type?
if (!isset($POST->devicetype) || ($POST->devicetype != 'device' && $POST->devicetype != 'content' && $POST->devicetype != 'subrack')) { $Result->show("danger", _("Invalid device type"), true, true); }

# remove or add ?
if ($POST->action=="remove") {
    # fetch rack details
    $rack = $Admin->fetch_object("racks", "id", $POST->rackid);
    # validate csrf cookie
    $User->Crypto->csrf_cookie ("validate", "rack_devices_".$rack->id."_device_".$POST->deviceid, $POST->csrf_cookie) === false ? $Result->show("danger", _("Invalid CSRF cookie"), true, true) : "";
    switch ($POST->devicetype) {
        case 'device':
        # set values
        $values = array("id"=>$POST->deviceid,
                        "rack"=>"",
                        "rack_start"=>"",
                        "rack_size"=>""
                        );
        # update
        if(!$Admin->object_modify("devices", "edit", "id", $values))    { $Result->show("success", _("Failed to remove device from rack").'!', true, true); }
        else                                                            { $Result->show("success", _("Device removed from rack").'!', false, true); }
        break;

        case 'content':
        if (!$Admin->object_modify('rackContents', 'delete', 'id', ['id' => $POST->deviceid])) { $Result->show("success", _("Failed to remove device from rack").'!', true, true); }
        else                                                                                      { $Result->show("success", _("Device removed from rack").'!', false, true); }
        break;
    }

    # js
    ?>
    <script>
    $(document).ready(function() {
    $('.hidePopups').click(function(){
       window. location.reload();
       return false;
    });
    });
    </script>
    <?php

    die();
}
# add to rack
else {
    # create csrf token
    $csrf = $User->Crypto->csrf_cookie ("create-if-not-exists", "rack_devices");
    # fetch rack details
    $rack = $Admin->fetch_object("racks", "id", $POST->rackid);
    # check
    if ($rack===false)                                              { $Result->show("danger", _("Invalid ID"), true, true); }
    # fetch existing devices
    $rack_devices = $Racks->fetch_rack_devices($rack->id);
    $rack_contents = $Racks->fetch_rack_contents($rack->id);

    if ($POST->devicetype == 'device') {
        # all devices
	    $devices = $Admin->fetch_all_objects("devices", "id");
	    if ($devices!==false) {
		    foreach($devices as $k=>$d) {
			    if ((!is_blank($d->rack)) && ($d->rack != 0)) {
				    unset($devices[$k]);
			    }
		    }
	    }
    } elseif ($POST->devicetype == 'subrack') {
		$subracks = $Racks->fetch_orphan_subracks();
		if ($subracks===false) unset($subracks);
	}
?>

<script>
$(document).ready(function(){
     if ($("[rel=tooltip]").length) { $("[rel=tooltip]").tooltip(); }
});
</script>

<!-- header -->
<div class="pHeader"><?php print $User->get_post_action(); ?> <?php print _('device to rack'); ?></div>

<!-- content -->
<div class="pContent">
    <?php
    // no devides
    if ($POST->devicetype == 'device' && (!isset($devices)||sizeof($devices)==0)) {
        $Result->show("info", _("No devices available"), false);
	} elseif ($POST->devicetype == 'subrack' && (!isset($subracks)||sizeof($subracks)==0)) {
		$Result->show("info", _("No subracks available"), false);
	} elseif ($POST->devicetype == 'subrack' && $rack->subrack) {
		$Result->show("info", _("Nesting subracks is not allowed"), false);
		# unsetting the array suppresses the "Add" button
		unset($subracks);
	}
    else {
    ?>
        <form id="rackDeviceManagementEdit">

    	<table class="table table-noborder table-condensed">

        	<!-- Select device  -->
        	<tr>
        		<td><?php print ($POST->devicetype=='subrack') ? _('Subrack') : _('Device'); ?></td>
        		<td>
                    <?php if ($POST->devicetype == 'device') { ?>
        			<select name="deviceid" class="form-control input-sm input-w-auto">
        			<?php
            			foreach($devices as $d) {
                            print "<option value='$d->id' >$d->hostname</option>";
                        }
        			?>
        			</select>
					<?php } elseif ($POST->devicetype=='subrack') { ?>
					<select name="subrackid" class="form-control input-sm input-w-auto">
					<?php
					foreach($subracks as $r) {
						print "<option value='$r->id' >$r->name</option>";
					}
					?>
					</select>
                    <?php } else { ?>
                    <input type="text" name="name" class="form-control input-sm" placeholder="<?php print _('Device name'); ?>">
                    <?php } ?>
        		</td>
        	</tr>

        	<!-- set start -->
        	<tr>
        		<td><?php print _('Start position'); ?></td>
        		<td>
                    <select name="rack_start" id="rack_start" class="form-control input-sm input-w-auto" style="min-width:110px">
            		<?php
                    // RACK_V2_DYNAMIC_FREE_U
                    // Default to a safe 1U candidate; JS below recalculates when size/depth changes.
                    $default_rack_candidate = array("rack_size"=>1, "rack_deep"=>0);
                    list($available_front, $available_back) = $Racks->free_u(
                        $rack,
                        $rack_devices,
                        $rack_contents,
                        $default_rack_candidate,
                        true
                    );

                    // print available spaces
                    if($rack->hasBack!="0") {
                        print "<optgroup label='"._("Front")."'>";
                        foreach ($available_front as $a) {
                            print "<option value='$a'>$a</option>";
                        }
                        print "</optgroup>";

                        print "<optgroup label='"._("Back")."'>";
                        foreach ($available_back as $k=>$a) {
                            print "<option value='$k'>$a</option>";
                        }
                        print "</optgroup>";
                    }
                    else {
                        foreach ($available_front as $a) {
                            print "<option value='$a'>$a</option>";
                        }
                    }
                    ?>
                    </select>
        		</td>
        	</tr>

        	<!-- Set size -->
        	<tr>
        		<td><?php print _('Size'); ?></td>
        		<td>
        			<input type="number"
                               name="rack_size"
                               id="rack_size"
                               class="form-control input-sm"
                               min="1"
                               max="<?php print (int)$rack->size; ?>"
                               value="1"
                               placeholder="<?php print _('Device size in U'); ?>">
                        <div id="rackPositionStatus" class="text-muted small" style="margin-top:5px"></div>
        			<input type="hidden" name="csrf_cookie" value="<?php print $csrf; ?>">
                        <input type="hidden" name="rack_strict" value="1">
                    <input type="hidden" name="rackid" value="<?php print escape_input($POST->rackid); ?>">
                    <input type="hidden" name="devicetype" value="<?php print escape_input($POST->devicetype); ?>">
        		</td>
        	</tr>

			<tr>
				<td><?php print _('Full Depth'); ?></td>
				<td>
					<input type="checkbox" class="input-switch" name="rack_deep" value="1" >
				</td>
			</tr>

            <!-- Location override -->
            <?php if($User->settings->enableLocations=="1" && ($rack->location!="0" && !is_null($rack->location)) && ($POST->devicetype == 'device')) { ?>
            <tr>
                <td colspan="2">
                <hr>
                    <input type="checkbox" class="input-switch" value="1" name="no_location"> <span title="Prevent overwriting the device's Location with the value of the rack's Location" class="text-muted"><?php print _("Don't update device location from rack"); ?></span>
                </td>
            </tr>
            <?php } ?>

    	</table>
        </form>
    <?php } ?>
</div>

<script>
// RACK_V2_DYNAMIC_FREE_U
$(function () {
    var $form   = $("#rackDeviceManagementEdit");
    var $size   = $form.find("[name=rack_size]");
    var $deep   = $form.find("[name=rack_deep]");
    var $start  = $form.find("[name=rack_start]");
    var $status = $("#rackPositionStatus");
    var timer   = null;

    if (!$form.length || !$start.length) {
        return;
    }

    function addOption($parent, value, label) {
        $("<option>")
            .attr("value", value)
            .text(label)
            .appendTo($parent);
    }

    function renderPositions(response, previousValue) {
        $start.empty();

        if (!response || response.success !== true || !Array.isArray(response.positions)) {
            $start.prop("disabled", true);
            $status.text("Unable to calculate free rack positions.");
            return;
        }

        if (response.positions.length === 0) {
            $start.prop("disabled", true);
            $status.text("No free rack position is available for the selected size.");
            return;
        }

        if (response.hasBack) {
            var $front = $("<optgroup>").attr("label", "Front");
            var $back  = $("<optgroup>").attr("label", "Back");

            response.positions.forEach(function (position) {
                if (position.side === "back") {
                    addOption($back, position.value, position.label);
                } else {
                    addOption($front, position.value, position.label);
                }
            });

            if ($front.children().length) {
                $start.append($front);
            }
            if ($back.children().length) {
                $start.append($back);
            }
        } else {
            response.positions.forEach(function (position) {
                addOption($start, position.value, position.label);
            });
        }

        $start.prop("disabled", false);

        if (previousValue && $start.find('option[value="' + previousValue + '"]').length) {
            $start.val(previousValue);
        }

        $status.text(
            response.positions.length +
            " valid start position" +
            (response.positions.length === 1 ? "" : "s") +
            " for " + response.size + "U."
        );
    }

    function refreshPositions() {
        var size = parseInt($size.val(), 10);

        if (isNaN(size) || size < 1) {
            $start.prop("disabled", true);
            $status.text("Enter a device size of at least 1U.");
            return;
        }

        var previousValue = $start.val();
        $status.text("Calculating free rack positions...");

        $.ajax({
            url: "app/admin/racks/edit-rack-devices-free-u.php",
            method: "POST",
            dataType: "json",
            data: {
                rackid: <?php print (int)$rack->id; ?>,
                rack_size: size,
                rack_deep: $deep.is(":checked") ? 1 : 0
            }
        })
        .done(function (response) {
            renderPositions(response, previousValue);
        })
        .fail(function () {
            $start.prop("disabled", true);
            $status.text("Unable to calculate free rack positions.");
        });
    }

    function scheduleRefresh() {
        clearTimeout(timer);
        timer = setTimeout(refreshPositions, 180);
    }

    $size.on("input change", scheduleRefresh);
    $deep.on("change", refreshPositions);

    refreshPositions();
});
</script>


<!-- footer -->
<div class="pFooter">
	<div class="btn-group">
		<button class="btn btn-sm btn-default hidePopups"><?php print _('Cancel'); ?></button>
		<?php if (!empty($devices) || !empty($subracks) || $POST->devicetype=='content') { ?>
		<button class="btn btn-sm btn-default btn-success" id="editRackDevicesubmit"><i class="fa fa-plus"></i> <?php print $User->get_post_action(); ?></button>
        <?php } ?>
	</div>

	<!-- result -->
	<div class="rackDeviceManagementEditResult"></div>
</div>
<?php } ?>
