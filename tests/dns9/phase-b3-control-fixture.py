#!/usr/bin/env python3
"""Render the generated B3 PowerShell control script for Windows parser CI."""
import ast,base64,pathlib
source=(pathlib.Path(__file__).resolve().parents[2]/'tools/live/dns9-phase-b3-first-cycle.py').read_text()
module=ast.parse(source)
main=next(x for x in module.body if isinstance(x,ast.FunctionDef) and x.name=='main')
assignment=next(x for x in main.body if isinstance(x,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='script' for t in x.targets))
script=''.join(x.value if isinstance(x,ast.Constant) else "'fixture'" for x in assignment.value.values)
print(base64.b64encode(script.encode()).decode())
