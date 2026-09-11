#!/usr/bin/env python3
"""Only four named undeployed 5G.1 files evolve; deployed c33 ancestors remain byte-preserved."""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[1]
def git(*args):
    return subprocess.check_output(['git',*args],cwd=ROOT,text=True)
base='56ed3b9b1091cd34d075ff49bdd37b6fe0277555'
git('merge-base','--is-ancestor',base,'HEAD')
allowed={
    'layers/phase5g/app/integrations/vmware/mirror/read.php',
    'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php',
    'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryReader.php',
    'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php',
}
for line in git('diff','--name-status','--diff-filter=DMRT',base,'HEAD','--','layers','tools','installer').splitlines():
    status,path=line.split('\t');assert status=='M' and path in allowed,line
# The worker/store that are already on production are NOT among these evolving files.
git('diff','--exit-code','--diff-filter=DMRT','c33c762f24048fb7976312389779047ad2b64b97','HEAD','--','layers','tools','installer')
print('PHASE5G3_DEPLOYED_ANCESTORS_PRESERVED')
