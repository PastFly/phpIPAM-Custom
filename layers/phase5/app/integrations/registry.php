<?php

/**
 * phpIPAM Custom integration connector registry — Phase 5A.
 *
 * Metadata only. No endpoint, credentials, tokens or runtime secrets live here.
 */

// PHPIPAM_CUSTOM_INTEGRATIONS_REGISTRY_V1
// PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1
return array(
    "vmware-vcenter" => array(
        "id" => "vmware-vcenter",
        "name" => "VMware vCenter",
        "icon" => "fa-cloud",
        "state" => "next",
        "state_label" => "Тест подключения",
        "mode" => "read-only",
        "summary" => "Инвентаризация Datacenter, Cluster, ESXi, VM, NIC, MAC и IP с последующей сверкой с phpIPAM.",
        "capabilities" => array(
            "Transient HTTPS connection test",
            "Datacenters и Clusters",
            "ESXi hosts и Virtual Machines",
            "NIC / MAC / IP observation",
            "Сверка наблюдаемых IP с phpIPAM"
        )
    ),
    "hyper-v" => array(
        "id" => "hyper-v",
        "name" => "Microsoft Hyper-V",
        "icon" => "fa-windows",
        "state" => "planned",
        "state_label" => "Запланировано",
        "mode" => "read-only",
        "summary" => "Инвентаризация Hyper-V hosts и виртуальных машин после готовности VMware-коннектора.",
        "capabilities" => array(
            "Hosts и Virtual Machines",
            "NIC / MAC / IP observation"
        )
    ),
    "active-directory" => array(
        "id" => "active-directory",
        "name" => "Active Directory",
        "icon" => "fa-sitemap",
        "state" => "planned",
        "state_label" => "Запланировано",
        "mode" => "read-only",
        "summary" => "Контекст компьютеров AD для Device/IP страниц без превращения phpIPAM в AD-admin инструмент.",
        "capabilities" => array(
            "Computer metadata",
            "OU / OS / DNS hostname",
            "Enabled state и last-logon context"
        )
    ),
    "dns" => array(
        "id" => "dns",
        "name" => "DNS",
        "icon" => "fa-globe",
        "state" => "planned",
        "state_label" => "Запланировано",
        "mode" => "read-only",
        "summary" => "Сверка A/PTR и отображение расхождений между DNS и адресами phpIPAM.",
        "capabilities" => array(
            "A/PTR reconciliation",
            "DNS mismatch indicators"
        )
    ),
    "dhcp" => array(
        "id" => "dhcp",
        "name" => "DHCP",
        "icon" => "fa-exchange",
        "state" => "planned",
        "state_label" => "Запланировано",
        "mode" => "read-only",
        "summary" => "Наблюдение scope, reservations и leases с поиском адресов, активных в DHCP, но свободных в phpIPAM.",
        "capabilities" => array(
            "Scopes / reservations / leases",
            "DHCP-active vs phpIPAM-free detection"
        )
    )
);
