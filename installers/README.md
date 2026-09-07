# Historical installer chain

The live server was built incrementally using hotfix installers created during the 2026-09-07 session. These names are preserved as historical evidence in Continuity; the development repository does **not** yet claim that replaying the historical chain is the supported rebuild method.

Known successful/effective families:

- RACK V2 / V2.1 — rack occupancy and backend overlap correctness.
- RACK V3.0 — experimental duplicate interactive rack; superseded by native SVG approach.
- RACK V3.1.x — native single-rack drag-and-drop; current successful behavior finalized by V3.1.4.
- RACK V3.2 — single-rack page layout swap.
- RACK V3.3.x — Rack scheme cross-rack drag; current successful behavior finalized by V3.3.5.
- UI Menu V1.2 repair — sidebar wrapping and recovery from failed V1.1.

See companion Continuity `CURRENT_BUILD.md`, `VERIFIED_HISTORY.md` and `LESSONS_LEARNED.md` for exact chronology and failures.

## Policy

Do not add more cumulative hotfix layers unless necessary for emergency recovery. The next task is to capture exact live files and replace the historical chain with a consolidated, tested patch/install path from pristine phpIPAM 1.8.2.
