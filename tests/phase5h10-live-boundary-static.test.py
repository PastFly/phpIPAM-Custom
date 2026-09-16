#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
p = ROOT / 'tools/install-phase5h10-disabled-reader-candidate.py'
s = p.read_text(); n = 0

def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

start = s.index('def verify_live_boundary()')
end = s.index('\ndef require_parent(', start)
pre = s[start:end]
start2 = s.index('def verify_live_boundary_after(')
end2 = s.index('\ndef main(', start2)
post = s[start2:end2]

for text, label in [(pre,'pre'),(post,'post')]:
    for token in [
        "ACTIVE_RELEASE / ACTIVE_RECOVERY_REL",
        "'RECOVERY_STAGE=active'",
        "'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1'",
        "SERVING_TIMER", "SHADOW_TIMER",
        "regular(LOCK, uid=33, gid=33, mode=0o600, size_zero=True)",
        "WEBROOT / rel",
    ]: ok(token in text, label + ':' + token)
    for forbidden in [
        "systemctl', 'enable", "systemctl','enable", "systemctl', 'disable", "systemctl','disable",
        "systemctl', 'start", "systemctl','start", "systemctl', 'stop", "systemctl','stop",
        'write_file(', 'write_receipt(', 'unlink(', 'mkdir(', 'rmdir(', 'os.replace(', 'os.link(',
        'mysql', 'curl_exec(', 'SoapClient', 'PropertyCollector', 'QueryPerf',
    ]: ok(forbidden not in text, label + ':forbidden:' + forbidden)

ok("if LIVE_CONFIG.exists() or LIVE_CONFIG.is_symlink()" in pre, 'pre live config absent')
ok("if CANDIDATE.exists() or CANDIDATE.is_symlink()" in pre, 'pre candidate absent')
ok("if LIVE_CONFIG.exists() or LIVE_CONFIG.is_symlink()" in post, 'post live config absent')
ok("if not candidate_real.is_dir()" in post, 'post candidate present')

print(f'PHASE5H10_LIVE_BOUNDARY_STATIC_ASSERTIONS={n}')
print('PHASE5H10_LIVE_PREFLIGHT_READ_ONLY_OK')
print('PHASE5H10_LIVE_POSTCHECK_READ_ONLY_OK')
