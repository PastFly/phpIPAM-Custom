# Phase 5F.3.2 — VM guest facts correctness

Purpose: verify and correct the fields displayed in the VMware VM card without guessing UI translations.

Observed Owner evidence:
- phpIPAM currently displays raw guest identifiers such as `debian_11_64` and `windows_9_server_64` while vSphere displays friendly guest names.
- `RAM GiB` is currently blank for VMs where vSphere has configured memory.

Compatibility preview reads one or more explicit VirtualMachine MORs using legacy-compatible `RetrieveProperties` and direct/no-proxy transport. It compares:
- `config.guestId`
- `config.guestFullName`
- `guest.guestId`
- `guest.guestFullName`
- `guest.guestFamily`
- `config.hardware.memoryMB`
- `config.hardware.numCPU`
- `config.version`
- presence/length of `config.annotation`

No UI/runtime changes are included until real production values are observed. No persistence or vCenter/phpIPAM mutation is permitted.
