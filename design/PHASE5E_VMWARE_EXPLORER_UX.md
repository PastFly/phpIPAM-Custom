# Phase 5E — VMware Explorer UX / navigation

Status: design + repository implementation in progress.

Base: Phase 5D exact head `af1245a1888d967ba07961cf52ddd013fa61ed0e`.

## Goal

Turn the runtime-verified Phase 5D VMware Explorer into a dedicated, navigable product surface without rewriting or weakening the VMware read-only backend.

Owner feedback from the Phase 5D browser gate:

1. the current Explorer uses Bootstrap elements whose foreground/background contrast is poor in the active phpIPAM dark theme;
2. entity names should be the primary navigation target (VM, ESXi host, Datacenter, Cluster, datastore, later phpIPAM IP), not separate action buttons;
3. VMware needs a dedicated product surface below `Integrations`, with compact navigation rather than one vertically growing Administration page;
4. the accepted product direction is an infrastructure hierarchy with a list/detail workspace rather than a long report.

## Non-regression boundary

Phase 5E is a **UI/navigation layer on top of Phase 5D**.

Do not change unless separately evidenced and required:

- `IntegrationVmwareVcenterTopology`;
- `IntegrationVmwareVcenterDatastore`;
- `IntegrationVmwareVcenterDatastoreResolver`;
- `IntegrationVmwareVcenterVmDetail`;
- `IntegrationVmwareVcenterMetadata`;
- `IntegrationVmwareVcenterGuestNetwork`;
- encrypted connector/keyring schema or credential handling;
- datastore correlation authority semantics;
- TLS-INSECURE explicit manual acknowledgement;
- read-only/no-persistence/no-scheduler safety contract.

The existing Phase 5D Administration Explorer remains the fallback/runtime reference until Phase 5E is separately deployed and browser-verified.

## Navigation model

Top-level route remains the already deployed `Integrations` route. No root router rewrite is needed.

`Integrations` dispatches an internal section:

- landing page;
- `VMware vCenter` dedicated workspace.

VMware workspace navigation:

- Overview;
- Infrastructure;
- Virtual Machines;
- Datastores;
- Reconciliation (initially links to the existing read-only Phase 5C surface);
- Settings (Administration → Integrations).

Primary interaction model:

- left: bounded hierarchy browser `Datacenter → Cluster → ESXi Host`;
- center: table/list for the selected scope;
- right: selected VM detail card;
- each pane scrolls internally; the whole page should not become an ever-growing report;
- breadcrumbs describe the current authoritative stable-ID scope.

VMs are intentionally not all pre-expanded in the left tree. A host click selects the host and the center pane lists that host's VMs. This avoids rendering a 631-VM hierarchy on every request.

## Theme rule

Phase 5E custom CSS is **layout-only**.

Do not hardcode foreground/background theme colors. Reuse phpIPAM/Bootstrap theme classes for panels, tables, alerts, labels, active rows and links. Custom CSS may set sizing, spacing, overflow, flex/grid behavior and text wrapping only.

This is specifically intended to fix the Phase 5D dark-theme contrast issue without creating a second independent theme.

## Entity navigation

Phase 5E.1:

- Datacenter name → authoritative Datacenter scope;
- Cluster name → authoritative Cluster scope;
- ESXi hostname → authoritative Host scope;
- VM name → on-demand VM detail in the right pane;
- datastore name/ID → datastore view/filter.

No hierarchy placement is inferred from names.

Phase 5E.2 (after exact phpIPAM route evidence):

- guest IP → matching phpIPAM address object when a SELECT-only match exists;
- contextual phpIPAM Device/IP linkage and reconciliation badges;
- do not invent a destination URL from an IP string.

## Runtime/data behavior

- topology/datastore inventory remains transient per request;
- VM detail/network/tag calls remain on-demand;
- normal page render must not perform an unbounded VM detail crawl;
- all identifiers used for scope are stable vCenter external IDs;
- standalone/unclustered Hosts/VMs remain explicit and are not treated as errors;
- VM-disk → datastore links based on VMDK name remain `correlation_only_not_authoritative_vmware_relation`.

## Deployment strategy

Phase 5E is developed on stacked branch `phase5e/vmware-explorer-ux` from the exact Phase 5D head. The Phase 5D branch and its deployed files are not modified while Phase 5E is being developed.

A future live installer must:

1. require the Phase 5D verifier to pass before mutation;
2. require the current Integrations landing source to match the expected Phase 4 blob;
3. stage/lint all PHP before replacement;
4. back up every replaced file and enumerate every new file;
5. provide an executable rollback script;
6. install only the Phase 5E product surface/assets/endpoints;
7. run both Phase 5D and Phase 5E verifiers after install.

Production deployment remains a separate Owner approval gate.
