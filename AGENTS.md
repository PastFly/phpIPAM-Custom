# Development rules

This is the private source/development repository for phpIPAM Custom.

Before making substantial changes, read the companion continuity repository `PastFly/phpIPAM-Custom-Continuity`, starting with `AGENTS.md` and following its bootstrap order.

## Baseline

- Upstream: phpIPAM 1.8.2.
- Current live runtime is customized incrementally and is not yet captured byte-for-byte here.
- Do not claim this repository reproduces production until Continuity marks the build `reproducible_verified`.

## Engineering rules

- Prefer feature branches + PRs for substantial code changes.
- Preserve upstream compatibility where practical.
- Rack UI checks are advisory; backend overlap/overflow validation is authoritative.
- Preserve multi-U, Front/Rear and Full Depth invariants.
- Validate PHP syntax and any installer generator languages before deployment.
- Every live-server update must have rollback/backups.
- Never commit secrets, DB dumps, service credentials, session material or private keys.

## Continuity

After a verified milestone, update `PastFly/phpIPAM-Custom-Continuity` according to its `UPDATE_PROTOCOL.md`.
