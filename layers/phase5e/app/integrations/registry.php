<?php

/**
 * phpIPAM Custom integration connector registry — Phase 5E product state.
 * Metadata only: no endpoint, username, credential, token or runtime secret.
 */

// PHPIPAM_CUSTOM_INTEGRATIONS_REGISTRY_PHASE5E_V1
return array(
    "vmware-vcenter" => array(
        "id" => "vmware-vcenter",
        "name" => "VMware vCenter",
        "icon" => "fa-cloud",
        "state" => "ready",
        "state_label" => "Read-only",
        "mode" => "read-only",
        "summary" => "Навигация по Datacenter, Cluster, ESXi, VM и datastore с on-demand VM card и advisory-сверкой с phpIPAM.",
        "capabilities" => array(
            "Datacenter → Cluster → ESXi → VM",
            "Datastore inventory и безопасная correlation provenance",
            "VM identity / compute / guest / disks / NIC / MAC / IP",
            "Read-only Tags / Categories",
            "Advisory VMware ↔ phpIPAM reconciliation"
        )
    ),
    "hyper-v" => array(
        "id" => "hyper-v",
        "name" => "Microsoft Hyper-V",
        "icon" => "fa-windows",
        "state" => "planned",
        "state_label" => "Запланировано",
        "mode" => "read-only",
        "summary" => "Инвентаризация Hyper-V hosts и виртуальных машин после стабилизации VMware-коннектора.",
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
