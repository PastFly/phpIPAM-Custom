#!/usr/bin/env python3
"""Unprivileged entry guard for a root-owned release and its accepted app dependencies."""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys

ROOT = Path(__file__).resolve().parents[1]
APP = Path('/var/www/html')

def checked(path):
    if not path.is_absolute() or '..' in path.parts:
        raise RuntimeError('unsafe_path')
    for parent in (path, *path.parents):
        if parent.is_symlink(): raise RuntimeError('unsafe_path')
    s = path.stat()
    if not stat.S_ISREG(s.st_mode) or s.st_nlink != 1: raise RuntimeError('unsafe_file')
    return s

def validate():
    s = checked(ROOT / 'release.json')
    if s.st_uid != 0 or stat.S_IMODE(s.st_mode) != 0o444: raise RuntimeError('release_metadata')
    manifest = json.loads((ROOT / 'release.json').read_text())
    if manifest.get('schema') != 1 or manifest.get('application') != str(APP): raise RuntimeError('release_format')
    for field, prefix, immutable in [('files', ROOT, True), ('dependencies', APP, False)]:
        for rel, expected in manifest[field].items():
            if Path(rel).is_absolute() or '..' in Path(rel).parts: raise RuntimeError('release_path')
            path = prefix / rel; st = checked(path)
            if immutable and (st.st_uid != 0 or stat.S_IMODE(st.st_mode) != 0o444): raise RuntimeError('release_metadata')
            if hashlib.sha256(path.read_bytes()).hexdigest() != expected: raise RuntimeError('source_changed')
    return manifest

def main():
    if os.geteuid() != 33 or os.getegid() != 33: raise RuntimeError('runtime_identity')
    if sys.argv[1:] not in [['--tick', '--connector=1'], ['--status', '--connector=1'], ['--inspect', '--connector=1']]:
        raise RuntimeError('arguments')
    validate()
    inspect = sys.argv[1] == '--inspect'
    target = ROOT / 'tools' / ('phase5g4-inspect.php' if inspect else 'vmware-inventory-cycle.php')
    args = ['--connector=1'] if inspect else sys.argv[1:]
    os.execv('/usr/bin/php', ['php', '-d', 'memory_limit=256M', '-d', 'display_errors=0',
        '-d', 'log_errors=0', str(target), *args])

if __name__ == '__main__':
    try: main()
    except Exception:
        print('PHASE5G4_WORKER_GUARD_FAILED=1', file=sys.stderr); sys.exit(1)
