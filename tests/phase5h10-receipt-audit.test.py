#!/usr/bin/env python3
"""Execute the read-only audit against temporary fixtures only; never call main()."""
import ast
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'tools/live/phase5h10-receipt-audit.py'
spec = importlib.util.spec_from_file_location('receipt_audit', SOURCE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
n = 0


def ok(condition, label):
    global n
    n += 1
    if not condition:
        raise AssertionError(label)


def fails(fn, message):
    global n
    n += 1
    try:
        fn()
    except RuntimeError as e:
        if str(e) == message:
            return
        raise
    raise AssertionError('expected:' + message)


def put(path, raw, mode):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(raw)
    path.chmod(mode)


def fixture(root):
    app, backup = root / 'app', root / 'backup'
    rows = {}
    for rel in (m.STATUS, 'functions/classes/class.A.php'):
        raw = b'new test bytes\n'; old = b'old test bytes\n'
        put(app / rel, raw, 0o644)
        put(backup / 'new' / rel, raw, 0o600)
        put(backup / 'old' / rel, old, 0o600)
        meta = {'uid': os.geteuid(), 'gid': os.getegid(), 'mode': 0o644}
        rows[rel] = {'new': hashlib.sha256(raw).hexdigest(), 'old': hashlib.sha256(old).hexdigest(),
                     'installed_metadata': meta, 'metadata': meta}
    receipt = {'schema': 1, 'phase': '5g4', 'recovery': '5g5', 'application': str(m.APP),
               'connector': 1, 'stage': 'active', 'app_files': rows}
    put(backup / 'receipt.json', json.dumps(receipt).encode(), 0o600)
    return app, backup, receipt


def execute(app, backup):
    out = io.StringIO()
    with contextlib.redirect_stdout(out):
        rc = m.audit(app, backup)
    text = out.getvalue()
    summary = json.loads(next(line.split('=', 1)[1] for line in text.splitlines()
                             if line.startswith('H10_AUDIT_SUMMARY=')))
    return rc, text, summary


# Receipt metadata intentionally matches the fixed production contract: run the fixtures as root.
if os.geteuid() != 0 or os.getegid() != 0:
    raise SystemExit('TEST_ROOT_REQUIRED_FOR_RECEIPT_FIXTURE')

with tempfile.TemporaryDirectory() as td:
    app, backup, receipt = fixture(Path(td))
    before = {str(p): (p.read_bytes(), m.metadata(p.stat())) for p in Path(td).rglob('*') if p.is_file()}
    rc, text, counts = execute(app, backup)
    ok(rc == 0 and counts['baseline_new_matches'] == 2, 'exact baseline passes')
    ok('H10_AUDIT_COMPLETE=1' in text, 'complete marker')
    ok('H10_AUDIT_RECEIPT_UNCHANGED=1' in text, 'receipt re-read')
    ok('new test bytes' not in text and 'old test bytes' not in text, 'no source contents in output')
    after = {str(p): (p.read_bytes(), m.metadata(p.stat())) for p in Path(td).rglob('*') if p.is_file()}
    ok(before == after, 'content and permissions unchanged')
    (app / m.STATUS).write_bytes(b'other status')
    (app / 'functions/classes/class.A.php').write_bytes(b'other class')
    rc, text, counts = execute(app, backup)
    ok(rc == 2 and counts['application_differences'] == 2, 'reports all drift not only first')
    ok(text.count('H10_AUDIT_FILE=') == 2, 'one bounded report per changed file')
    ok(counts['backup_errors'] == 0, 'live drift does not mask backup integrity')
    blob = hashlib.sha1(b'blob 12\0other status').hexdigest()
    old_known = dict(m.KNOWN_STATUS)
    try:
        m.KNOWN_STATUS[blob] = 'fixture_known_source'
        rc, text, counts = execute(app, backup)
        ok('fixture_known_source' in text and rc == 2, 'known source is not drift approval')
    finally:
        m.KNOWN_STATUS.clear(); m.KNOWN_STATUS.update(old_known)

with tempfile.TemporaryDirectory() as td:
    app, backup, receipt = fixture(Path(td))
    (app / m.STATUS).write_bytes(b'old test bytes\n')
    rc, text, counts = execute(app, backup)
    ok(rc == 2 and counts['baseline_old_matches'] == 1, 'retained original is reported separately')
    (backup / 'new' / m.STATUS).write_bytes(b'corrupt backup')
    rc, text, counts = execute(app, backup)
    ok(counts['backup_errors'] == 1 and 'backup_hash_mismatch' in text, 'backup corruption detected')

with tempfile.TemporaryDirectory() as td:
    app, backup, receipt = fixture(Path(td))
    (app / m.STATUS).chmod(0o600)
    rc, _, counts = execute(app, backup)
    ok(rc == 2 and counts['application_differences'] == 1, 'metadata-only drift detected')
    (app / m.STATUS).unlink()
    rc, _, counts = execute(app, backup)
    ok(rc == 2 and counts['read_errors'] == 1, 'missing file is an error')
    (app / m.STATUS).symlink_to(backup / 'new' / m.STATUS)
    rc, text, counts = execute(app, backup)
    ok(rc == 2 and counts['read_errors'] == 1 and 'not_single_link_regular_file' in text, 'symlink rejected')
    (app / m.STATUS).unlink()
    os.link(backup / 'new' / m.STATUS, app / m.STATUS)
    rc, _, counts = execute(app, backup)
    ok(rc == 2 and counts['read_errors'] == 1 and counts['backup_errors'] == 1, 'hardlinks rejected on both sides')

for key, value, error in [('schema', True, 'unexpected_receipt_profile'),
                           ('stage', 'paused', 'unexpected_receipt_profile'),
                           ('connector', True, 'unexpected_receipt_profile')]:
    with tempfile.TemporaryDirectory() as td:
        app, backup, receipt = fixture(Path(td)); receipt[key] = value
        put(backup / 'receipt.json', json.dumps(receipt).encode(), 0o600)
        fails(lambda: m.audit(app, backup), error)

for rel in ('../../etc/shadow', '/etc/shadow', 'functions/classes/../../etc/shadow',
            'functions//classes/a.php', 'functions/classes/bad\nname.php'):
    with tempfile.TemporaryDirectory() as td:
        app, backup, receipt = fixture(Path(td))
        receipt['app_files'][rel] = dict(receipt['app_files'][m.STATUS])
        put(backup / 'receipt.json', json.dumps(receipt).encode(), 0o600)
        fails(lambda: m.audit(app, backup), 'invalid_app_record')

with tempfile.TemporaryDirectory() as td:
    app, backup, receipt = fixture(Path(td))
    receipt['app_files'][m.STATUS]['new'] = 'not a hash'
    put(backup / 'receipt.json', json.dumps(receipt).encode(), 0o600)
    fails(lambda: m.audit(app, backup), 'invalid_new_hash')
    (backup / 'receipt.json').chmod(0o644)
    fails(lambda: m.audit(app, backup), 'file_metadata_mismatch')
    raw = Path(td) / 'raw'; put(raw, b'abc', 0o600)
    fails(lambda: m.read_checked(raw, limit=2), 'file_size_limit')
    parentlink = Path(td) / 'link'; parentlink.symlink_to(backup, target_is_directory=True)
    fails(lambda: m.read_checked(parentlink / 'receipt.json'), 'unsafe_parent')
    real_open = m.os.open
    def replace_before_open(path, *args, **kwargs):
        replacement = Path(td) / 'replacement'; put(replacement, b'abc', 0o600)
        os.replace(replacement, raw)
        return real_open(path, *args, **kwargs)
    try:
        m.os.open = replace_before_open
        fails(lambda: m.read_checked(raw), 'file_changed_before_open')
    finally:
        m.os.open = real_open

try:
    m.unique_object([('a', 1), ('a', 2)])
except ValueError:
    ok(True, 'duplicate JSON fields rejected')
else:
    raise AssertionError('duplicate JSON fields accepted')

# Check the diagnostic source has no write/process/network entrypoints.
source = SOURCE.read_text(); tree = ast.parse(source)
for forbidden in ('subprocess', 'socket', 'urllib', 'requests', 'shutil'):
    ok(not any(isinstance(node, ast.Import) and any(x.name == forbidden for x in node.names)
               for node in ast.walk(tree)), 'no import:' + forbidden)
for forbidden in ('write_bytes', 'write_text', 'unlink', 'mkdir', 'chmod', 'chown', 'replace', 'link', 'system', 'popen'):
    ok(not any(isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
               and node.func.attr == forbidden for node in ast.walk(tree)), 'no call:' + forbidden)

print(f'H10_RECEIPT_AUDIT_TEST_ASSERTIONS={n}')
print('H10_RECEIPT_AUDIT_TEMPORARY_FIXTURES_OK=1')
print('H10_RECEIPT_AUDIT_KNOWN_HASH_NOT_APPROVAL_OK=1')
print('H10_RECEIPT_AUDIT_READ_ONLY_SOURCE_OK=1')
