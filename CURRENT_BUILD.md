# Current build

Current live baseline: phpIPAM 1.8.2 on Linux, application root `/var/www/html`.

The live server is the current authoritative runtime. It was assembled incrementally through tested hotfix installers. Exact customized source files have not yet been imported into this repository, so this repository is not yet a byte-for-byte representation of the server.

## Runtime-verified behavior

- occupied U ranges are excluded in Add-to-rack, Create Device and Edit Device;
- backend rejects overlap/overflow;
- multi-U and Full Depth are respected;
- native SVG single-rack drag-and-drop works;
- single-rack drag supports Front↔Rear in all directions;
- rack details layout is customized;
- Rack scheme supports same-Location rack-to-rack drag-and-drop;
- cross-rack device data is loaded live from backend using exact visible rack IDs;
- long translated sidebar labels wrap correctly.

See `PastFly/phpIPAM-Custom-Continuity/CURRENT_BUILD.md` and `VERIFIED_HISTORY.md` for the exact history/evidence boundary.

## Next build task

Capture exact modified runtime files, compare them with pristine phpIPAM 1.8.2, commit the consolidated source diff here, and produce one clean verified installation path.
