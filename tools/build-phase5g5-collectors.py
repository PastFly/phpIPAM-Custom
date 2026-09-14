#!/usr/bin/env python3
"""Canonical Phase5G.5 collector bundle entry; all corrections share one generator."""
import importlib.util
from pathlib import Path
import sys
sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('provider_compat', ROOT/'tools/build-phase5g5-provider-compat.py')
compat = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compat)
INPUTS = compat.PINS

def generate():
    return {'collectors/' + name: raw for name, raw in compat.generate(ROOT).items()}

if __name__ == '__main__':
    if len(sys.argv) != 2: raise SystemExit('Usage: build-phase5g5-collectors.py OUTPUT_DIRECTORY')
    out = Path(sys.argv[1]).absolute()
    if any(p.is_symlink() for p in (out, *out.parents)):
        raise SystemExit('Symlink output paths are not allowed')
    if any(out == p or p in out.parents for p in [Path('/var/www'), Path('/opt/phpipam-custom'), Path('/etc/phpipam-custom'), Path('/var/lib/phpipam-custom')]):
        raise SystemExit('Live runtime output is not allowed')
    data = generate()
    if not out.is_dir(): raise SystemExit('Create a disposable output directory first')
    (out/'collectors').mkdir(mode=0o700, exist_ok=False)
    for name, raw in data.items():
        with (out/name).open('xb') as stream: stream.write(raw)
    print('PHASE5G5_PINNED_COLLECTORS_GENERATED=2; DEPLOYMENT_PERFORMED=0')
