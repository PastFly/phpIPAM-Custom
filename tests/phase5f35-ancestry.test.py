#!/usr/bin/env python3
"""Prove every retired stacked PR head remains in the consolidated master history."""
import subprocess
MASTER = 'e5cf0a7e726986d06a061d4c9259d20ab5a8c248'
HEADS = {
    10: 'af1245a1888d967ba07961cf52ddd013fa61ed0e',
    11: 'eb9faae741d9e9b6845601eb40583b4d7d501505',
    12: '0cb3c872cf40be8ef6afd577168008ab1b4e11f7',
    13: 'd0b011cc1f2cd4df6639f83d8ff68bebb7b26cc4',
    14: '64e8a78a4b3dae450b327d5c1fcbbdf3da238e70',
    15: '375b8ff20c3f1ffb0832c8095f640e5332d01815',
    16: 'c2b71b68e386ec411ce163d988bf3eb65950f78e',
    17: '7bcce5f5b53e1b7bd13c340a890159a9c442e857',
    18: '281d8c44fe8f89a417ebccbf5a08c0afe62ed535',
    19: 'bfe0166c0dfc0918af0c622603da81b2f0f5bdc8',
    20: '941cfd9bbcc72f9454245aafc0a4a2a92bc21478',
    21: '76d99ea0b1c4b463cf6713b847da96563deb939e',
    22: '79951f1fc16bb9f0f880c64db996fe599196c0f7',
    23: '4fb57fadd115d4ebe93999f4e1bbdca865f86fe7',
    24: '056372227e2e00eb3001af52744f2d461cd751ba',
    25: 'd58c2643aeda5116fe6f881ba7834b8342f2ee74',
    26: '55c664fb91bb361b6c691d5aed33d0f4064ca16b',
    27: '9c40ef6029ebe0d9fb7bbea190fcaca16c67750f',
}
for number, sha in sorted(HEADS.items()):
    subprocess.run(['git', 'merge-base', '--is-ancestor', sha, MASTER], check=True)
    print('PR_%d_SOURCE_RETAINED_IN_MASTER=%s' % (number, sha))
subprocess.run(['git', 'merge-base', '--is-ancestor', MASTER, 'HEAD'], check=True)
print('STACKED_PR_ANCESTRY_OK=18')
