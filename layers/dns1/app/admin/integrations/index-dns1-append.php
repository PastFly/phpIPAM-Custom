<?php
// PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1
require(dirname(__FILE__).'/dns-config-panel.php');
?>
<script src="app/admin/integrations/dns/config.js"></script>
<script>
(function () {
    'use strict';
    var root=document.getElementById('dns-configuration');
    if (root && window.PhpIpamDnsConfig) {
        window.PhpIpamDnsConfig.mount(root,'app/admin/integrations/dns-config-action.php');
    }
})();
</script>
