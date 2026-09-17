#!/usr/bin/env python3
"""Read-only audit of the retained recovery5g5 app receipt. Never installs or approves drift."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys

APP = Path('/var/www/html')
BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv')
STATUS = 'app/integrations/vmware/phase5g3-status.js'
KNOWN_STATUS = {
    '45c0c5004f9416e6e34016b0fa3effcaf3c058ff': 'recorded_inline_utc3_hotfix',
    '1435e6c09692c37a951376b43b39b528b4429f4a': 'pr56_later_source_not_live_acceptance',
}
PREFIXES = ('app/integrations/vmware/', 'app/tools/search/', 'functions/classes/')
HEX = re.compile(r'[0-9a-f]{64}\Z')


def emit(key, value):
    print(key + '=' + (json.dumps(value, sort_keys=True, separators=(',', ':'))
                      if isinstance(value, (dict, list)) else str(value)), flush=True)


def identity(s):
    return (s.st_dev, s.st_ino, s.st_uid, s.st_gid, s.st_mode, s.st_nlink,
            s.st_size, s.st_mtime_ns, s.st_ctime_ns)


def metadata(s):
    return {'uid': s.st_uid, 'gid': s.st_gid, 'mode': stat.S_IMODE(s.st_mode)}


def chain(path):
    if not path.is_absolute() or '..' in path.parts:
        raise RuntimeError('invalid_absolute_path')
    for parent in reversed(path.parents):
        s = parent.lstat()
        if not stat.S_ISDIR(s.st_mode):
            raise RuntimeError('unsafe_parent')


def read_checked(path, limit=16 * 1024 * 1024, required=None):
    """No symlink traversal; bounded regular-file reads with pre/open/post identity checks."""
    chain(path)
    before = path.lstat()
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
        raise RuntimeError('not_single_link_regular_file')
    if before.st_size > limit:
        raise RuntimeError('file_size_limit')
    if required is not None and metadata(before) != required:
        raise RuntimeError('file_metadata_mismatch')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        if identity(os.fstat(fd)) != identity(before):
            raise RuntimeError('file_changed_before_open')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            raw = stream.read(limit + 1)
        if len(raw) > limit or identity(os.fstat(fd)) != identity(before):
            raise RuntimeError('file_changed_during_read')
    finally:
        os.close(fd)
    chain(path)
    if identity(path.lstat()) != identity(before):
        raise RuntimeError('file_replaced_during_read')
    return raw, before


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate_json_key')
        result[key] = value
    return result


def valid_meta(value):
    return (isinstance(value, dict) and set(value) == {'uid', 'gid', 'mode'}
            and all(type(v) is int and v >= 0 for v in value.values())
            and value['mode'] <= 0o7777)


def valid_rel(rel):
    return (isinstance(rel, str) and len(rel) <= 512
            and re.fullmatch(r'[A-Za-z0-9_./-]+', rel) is not None
            and not PurePosixPath(rel).is_absolute()
            and '..' not in PurePosixPath(rel).parts
            and str(PurePosixPath(rel)) == rel and rel.startswith(PREFIXES))


def audit(app, backup):
    receipt_path = backup / 'receipt.json'
    required = {'uid': 0, 'gid': 0, 'mode': 0o600}
    receipt_raw, _ = read_checked(receipt_path, required=required)
    receipt = json.loads(receipt_raw, object_pairs_hook=unique_object)
    if not isinstance(receipt, dict):
        raise RuntimeError('receipt_not_object')
    if (type(receipt.get('schema')) is not int or receipt['schema'] != 1
            or receipt.get('phase') != '5g4' or receipt.get('recovery') != '5g5'
            or receipt.get('application') != str(APP)
            or type(receipt.get('connector')) is not int or receipt['connector'] != 1
            or receipt.get('stage') != 'active'):
        raise RuntimeError('unexpected_receipt_profile')
    records = receipt.get('app_files')
    if not isinstance(records, dict) or not 1 <= len(records) <= 2000:
        raise RuntimeError('invalid_app_manifest')
    # Validate the entire manifest before reading any of its paths.
    for rel, rec in records.items():
        if not valid_rel(rel) or not isinstance(rec, dict):
            raise RuntimeError('invalid_app_record')
        new, old = rec.get('new'), rec.get('old')
        if not isinstance(new, str) or not HEX.fullmatch(new):
            raise RuntimeError('invalid_new_hash')
        if old is not None and (not isinstance(old, str) or not HEX.fullmatch(old)):
            raise RuntimeError('invalid_old_hash')
        if not valid_meta(rec.get('installed_metadata')):
            raise RuntimeError('invalid_installed_metadata')
        if old is not None and not valid_meta(rec.get('metadata')):
            raise RuntimeError('invalid_original_metadata')
    if STATUS not in records:
        raise RuntimeError('status_missing_from_receipt')

    counters = {'records': len(records), 'baseline_new_matches': 0, 'baseline_old_matches': 0,
                'application_differences': 0, 'backup_errors': 0, 'read_errors': 0}
    fingerprints = {}
    emit('H10_AUDIT_RECEIPT_SHA256', hashlib.sha256(receipt_raw).hexdigest())
    emit('H10_AUDIT_RECOVERY_STAGE', 'active')
    for rel in sorted(records):
        rec = records[rel]
        try:
            raw, s = read_checked(app / rel)
            actual = hashlib.sha256(raw).hexdigest()
            blob = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
            fingerprints[rel] = identity(s)
            meta = metadata(s)
            if actual == rec['new'] and meta == rec['installed_metadata']:
                classification = 'baseline_new'; counters['baseline_new_matches'] += 1
            elif rec['old'] is not None and actual == rec['old'] and meta == rec['metadata']:
                classification = 'baseline_old'; counters['baseline_old_matches'] += 1
            else:
                classification = 'difference'; counters['application_differences'] += 1
            if classification != 'baseline_new' or rel == STATUS:
                emit('H10_AUDIT_FILE', {'path': rel, 'classification': classification,
                    'actual_sha256': actual, 'actual_git_blob': blob,
                    'expected_new_sha256': rec['new'], 'expected_old_sha256': rec['old'],
                    'metadata': meta, 'expected_installed_metadata': rec['installed_metadata'],
                    'known_source': KNOWN_STATUS.get(blob, 'unknown') if rel == STATUS else 'not_classified'})
        except (OSError, RuntimeError) as error:
            counters['read_errors'] += 1
            code = str(error) if isinstance(error, RuntimeError) else type(error).__name__
            emit('H10_AUDIT_FILE_ERROR', {'path': rel, 'error': code})
        for side in ('old', 'new'):
            expected = rec[side]
            if expected is None:
                continue
            try:
                raw, _ = read_checked(backup / side / rel, required=required)
                if hashlib.sha256(raw).hexdigest() != expected:
                    raise RuntimeError('backup_hash_mismatch')
            except (OSError, RuntimeError) as error:
                counters['backup_errors'] += 1
                code = str(error) if isinstance(error, RuntimeError) else type(error).__name__
                emit('H10_AUDIT_BACKUP_ERROR', {'path': rel, 'side': side, 'error': code})
    # Detect app changes during the multi-file observation, not just during each hash read.
    for rel, original in fingerprints.items():
        chain(app / rel)
        if identity((app / rel).lstat()) != original:
            raise RuntimeError('application_changed_during_audit')
    after, _ = read_checked(receipt_path, required=required)
    if after != receipt_raw:
        raise RuntimeError('receipt_changed_during_audit')
    emit('H10_AUDIT_RECEIPT_UNCHANGED', 1)
    emit('H10_AUDIT_SUMMARY', counters)
    emit('H10_AUDIT_COMPLETE', 1)
    # Even a known source fingerprint is not an authorization to ignore the receipt.
    return 2 if any(counters[k] for k in ('application_differences', 'backup_errors',
                                         'read_errors', 'baseline_old_matches')) else 0


def main():
    if len(sys.argv) != 1 or os.geteuid() != 0:
        raise RuntimeError('root_required_no_arguments')
    rc = audit(APP, BACKUP)
    for path in (Path('/opt/phpipam-custom/vmware-fast-reader-candidate'),
                 Path('/etc/phpipam-custom/vmware-fast-reader.json')):
        chain(path)
        try:
            s = path.lstat()
            state = 'symlink' if stat.S_ISLNK(s.st_mode) else 'present'
        except FileNotFoundError:
            state = 'absent'
        emit('H10_AUDIT_PATH', {'path': str(path), 'state': state})
    return rc


if __name__ == '__main__':
    try:
        code = main()
    except Exception as error:
        emit('H10_AUDIT_ERROR', str(error) if isinstance(error, RuntimeError) else type(error).__name__)
        code = 1
    emit('H10_AUDIT_INSTALLER_EXECUTED', 0)
    emit('H10_AUDIT_EXIT', code)
    sys.exit(code)
