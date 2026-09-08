<?php

/**
 * phpIPAM Custom — transient VMware vCenter connection test.
 *
 * No endpoint, username, password, session token or inventory data is stored.
 */

require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$Result = new Result();

$User->check_user_session();

if (!$User->is_admin(false)) {
    $Result->show("danger", _("Administrative privileges required"), true, true, false, true);
}

if ($User->Crypto->csrf_cookie("validate", "integration_vmware_vcenter_test", $POST->csrf_cookie) === false) {
    $Result->show("danger", _("Invalid CSRF cookie"), true, true, false, true);
}

$endpoint = trim((string)$POST->endpoint);
$username = trim((string)$POST->username);
$password = (string)$POST->password;
$verifyTlsRaw = (string)$POST->verify_tls;

if ($verifyTlsRaw !== "0" && $verifyTlsRaw !== "1") {
    $Result->show("danger", "Некорректное значение TLS verification.", true, true, false, true);
}
$verifyTls = $verifyTlsRaw === "1";

if ($endpoint === "" || $username === "" || $password === "") {
    $Result->show("danger", "Endpoint, username и password обязательны для проверки.", true, true, false, true);
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");

try {
    $Vmware = new IntegrationVmwareVcenter();
    $test = $Vmware->test_connection($endpoint, $username, $password, $verifyTls);

    $safeEndpoint = htmlspecialchars((string)$test["endpoint"], ENT_QUOTES, "UTF-8");
    $safeFamily = htmlspecialchars((string)$test["api_family"], ENT_QUOTES, "UTF-8");
    $safeCount = (int)$test["datacenter_count"];
    $safeDuration = (int)$test["duration_ms"];
    $tlsText = $test["verify_tls"] ? "включена" : "ОТКЛЮЧЕНА";

    $message = "<strong>vCenter connection OK.</strong> "
        . "Endpoint: <code>" . $safeEndpoint . "</code>; "
        . "API: <code>" . $safeFamily . "</code>; "
        . "Datacenters: <strong>" . $safeCount . "</strong>; "
        . "TLS verification: <strong>" . $tlsText . "</strong>; "
        . "время: " . $safeDuration . " ms.";

    if (!$test["verify_tls"]) {
        $message .= "<br><span class='text-warning'><i class='fa fa-warning'></i> TLS certificate verification была отключена только для этой проверки.</span>";
    }

    $Result->show("success", $message, false, true, false, true);
} catch (Exception $e) {
    $safeError = htmlspecialchars((string)$e->getMessage(), ENT_QUOTES, "UTF-8");
    $Result->show("danger", "<strong>vCenter connection failed.</strong> " . $safeError, false, true, false, true);
}
