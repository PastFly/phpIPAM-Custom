#!/usr/bin/env python3
"""Render the SYSTEM preflight PowerShell for the Windows parser test."""
import ast,base64,pathlib
source=(pathlib.Path(__file__).resolve().parents[2]/'tools/live/dns9-b3-after-pdo.py').read_text()
module=ast.parse(source)
fn=next(x for x in module.body if isinstance(x,ast.FunctionDef) and x.name=='remote_preflight')
assignment=next(x for x in fn.body if isinstance(x,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='script' for t in x.targets))
script=''.join(x.value if isinstance(x,ast.Constant) else "'{}'" for x in assignment.value.values)
print(base64.b64encode(script.encode()).decode())
