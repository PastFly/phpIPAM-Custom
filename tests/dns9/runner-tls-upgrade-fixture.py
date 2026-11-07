#!/usr/bin/env python3
"""Emit a simulated Windows upgrade control script for the CI fixture."""
import base64
import hashlib
import importlib.util
import pathlib
import sys
root=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'tools/live'))
spec=importlib.util.spec_from_file_location('upgrade',root/'tools/live/dns9-runner-tls-upgrade.py')
u=importlib.util.module_from_spec(spec); spec.loader.exec_module(u)
digest=lambda b:hashlib.sha256(b).hexdigest()
hashes=dict(zip(u.NAMES,map(digest,(b'worker',b'preview',b'old-runner'))))
script=u.control_script('a'*16,hashes,digest(b'new-runner'))
print(base64.b64encode(script.encode('utf-8')).decode('ascii'))
