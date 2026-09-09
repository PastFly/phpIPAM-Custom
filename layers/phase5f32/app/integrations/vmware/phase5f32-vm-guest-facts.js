/* phpIPAM Custom — Phase 5F.3.2 VM guest/config fact fidelity. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var request = null;
    var currentVmId = null;
    var sequence = 0;

    function commonData() {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val()
        };
    }

    function abortRequest() {
        sequence++;
        if (request && request.readyState !== 4) {
            try { request.abort(); } catch (ignored) {}
        }
        request = null;
    }

    function selectedVmId() {
        var id = currentVmId;
        if (!id) id = $('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id');
        id = String(id || '');
        return /^vm-[0-9]+$/.test(id) ? id : null;
    }

    function renderedVmId() {
        var value = $('#phase5e-detail-result .phase5e-detail-section').first().find('code.phase5e-id').first().text();
        value = String(value || '').trim();
        return /^vm-[0-9]+$/.test(value) ? value : null;
    }

    function findValueCell(label) {
        var found = null;
        $('#phase5e-detail-result .phase5e-detail-table tr').each(function () {
            var $row = $(this);
            var text = String($row.children('th').first().text() || '').trim();
            if (text === label) {
                found = $row.children('td').first();
                return false;
            }
        });
        return found;
    }

    function setTextFact(label, value, source) {
        if (value === null || value === undefined || String(value).trim() === '') return;
        var $cell = findValueCell(label);
        if (!$cell || !$cell.length) return;
        $cell.empty().text(String(value));
        if (source) $cell.attr('title', source);
        $cell.attr('data-phase5f32-source', 'guest-facts');
    }

    function setGuestOsFact(payload) {
        var value = payload && payload.friendly_guest_os;
        if (value === null || value === undefined || String(value).trim() === '') return;
        var $cell = findValueCell('Guest OS');
        if (!$cell || !$cell.length) return;

        $cell.empty();
        $('<span class="phase5f32-guest-friendly"></span>').text(String(value)).appendTo($cell);

        var runtimeId = String(payload.guest_guest_id || '').trim();
        var configId = String(payload.config_guest_id || '').trim();
        var secondary = [];
        if (runtimeId) secondary.push('runtime: ' + runtimeId);
        if (configId && configId !== runtimeId) secondary.push('configured: ' + configId);
        if (secondary.length) {
            $('<br><small class="text-muted phase5f32-guest-technical"></small>')
                .text(secondary.join(' · '))
                .appendTo($cell);
        }

        $cell.attr('title', 'Source: ' + String(payload.friendly_guest_os_source || 'vCenter guest/config facts') + ' via RetrieveProperties');
        $cell.attr('data-phase5f32-source', 'guest-facts');
    }

    function applyFacts(payload, vmId) {
        if (!payload || payload.ok !== true || String(payload.vm_external_id || '') !== vmId) return;
        if (currentVmId !== vmId || renderedVmId() !== vmId) return;

        setGuestOsFact(payload);

        if (payload.memory_mb !== null && payload.memory_mb !== undefined && isFinite(Number(payload.memory_mb))) {
            var gib = Number(payload.memory_mb) / 1024;
            setTextFact('RAM GiB', gib.toFixed(2), 'Source: VirtualMachine.config.hardware.memoryMB via RetrieveProperties');
        }
        if (payload.num_cpu !== null && payload.num_cpu !== undefined && isFinite(Number(payload.num_cpu))) {
            setTextFact('vCPU', String(Number(payload.num_cpu)), 'Source: VirtualMachine.config.hardware.numCPU via RetrieveProperties');
        }
        if (payload.hardware_version !== null && payload.hardware_version !== undefined) {
            setTextFact('Hardware', String(payload.hardware_version), 'Source: VirtualMachine.config.version via RetrieveProperties');
        }
    }

    function loadFacts(vmId) {
        vmId = String(vmId || '');
        if (!/^vm-[0-9]+$/.test(vmId)) return;

        abortRequest();
        currentVmId = vmId;
        var mySequence = ++sequence;
        var data = commonData();
        data.vm_id = vmId;

        request = $.ajax({
            url: 'app/integrations/vmware/vm-guest-facts-result.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            cache: false
        }).done(function (payload) {
            if (mySequence !== sequence || currentVmId !== vmId) return;
            applyFacts(payload, vmId);
        }).fail(function (xhr, status) {
            if (status === 'abort' || mySequence !== sequence || currentVmId !== vmId) return;
            // Fail open: the original VM card remains visible and unchanged.
        });
    }

    $(document).on('click.phase5f32', '.phase5e-vm-link', function () {
        currentVmId = String($(this).attr('data-vm-id') || '');
        abortRequest();
        currentVmId = /^vm-[0-9]+$/.test(currentVmId) ? currentVmId : null;
    });

    $(document).on('click.phase5f32', '.phase5e-scope-link', function () {
        currentVmId = null;
        abortRequest();
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('app/integrations/vmware/vm-card-result.php') === -1) return;
        if (xhr.status < 200 || xhr.status >= 300) return;
        var vmId = selectedVmId();
        if (vmId) window.setTimeout(function () { loadFacts(vmId); }, 0);
    });

    $('#phase5e-config').on('change.phase5f32', function () {
        currentVmId = null;
        abortRequest();
    });
})(window.jQuery);
