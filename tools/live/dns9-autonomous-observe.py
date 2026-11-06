#!/usr/bin/env python3
"""Read-only observation of subsequent scheduled DNS zone-list jobs."""
import hashlib
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONNECTION = '33b6fade9323f15aa7b1f56a8a865a9d'
COLLECTOR = 'f57057d6677e1ca7a032aab8b68796ac'
SERVER = 'srv-dc01.rbank.local'
FIRST_JOB = '60df7e4fab8c4795408b447fb64efd2b'
SOURCE_SHA = 'd002bdf7623803dee998a1fb568934f33913e7e6'
BACKUP_SHA = '732bb9bdd520670a65b2d97d1b8f90df6829d5c3b19f1a90a14d9c9278bb0881'
PAYLOAD_SHA = 'f22f6cbcd8a2dd35b20a2a531550475b2e329b93323b8e7c1a31452db895eea0'
BACKUP = Path('/var/backups/phpipam-custom/dns9-activation-7b184667f6e2a681')
MARKER = Path('/var/lib/phpipam-custom/dns9-production-activation-global')


def validate_receipt(doc):
    if (not isinstance(doc, dict) or doc.get('source_sha') != SOURCE_SHA or doc.get('backup_sha256') != BACKUP_SHA
            or doc.get('dns_write') is not False or type(doc.get('revision')) is not int or doc['revision'] < 1
            or type(doc.get('binding_epoch')) is not int or doc['binding_epoch'] < 1):
        raise RuntimeError('activation_receipt_invalid')
    cycle = doc.get('cycle')
    if (not isinstance(cycle, dict) or cycle.get('job_id') != FIRST_JOB or cycle.get('state') != 'succeeded'
            or type(cycle.get('attempts')) is not int or cycle['attempts'] != 1
            or type(cycle.get('zone_count')) is not int or cycle['zone_count'] != 172
            or cycle.get('payload_sha256') != PAYLOAD_SHA or type(cycle.get('old_jobs_expired')) is not int
            or cycle['old_jobs_expired'] != 2 or any(cycle.get(k) is not True for k in
                ('timer_enabled','timer_active','windows_enabled','windows_ran','service_ran'))):
        raise RuntimeError('activation_receipt_invalid')


def inspect_jobs(one, revision, binding):
    if type(revision) is not int or revision < 1 or type(binding) is not int or binding < 1:
        raise RuntimeError('binding_invalid')
    base = f""" FROM custom_dns_control_jobs j
        JOIN custom_dns_connections c ON c.id=j.connection_id AND c.revision=j.configuration_revision AND c.binding_epoch=j.binding_epoch
        JOIN custom_dns_collector_assignments a ON a.connection_id=j.connection_id AND a.collector_id=j.collector_id AND a.revision=j.assignment_revision
        JOIN custom_dns_collectors k ON k.id=j.collector_id
        WHERE j.connection_id='{CONNECTION}' AND j.collector_id='{COLLECTOR}' AND c.enabled=1 AND c.server='{SERVER}'
        AND c.credential_mode='collector_identity' AND c.interval_seconds=300 AND k.revoked=0
        AND j.job_type='list_zones' AND j.credential_version IS NULL AND j.configuration_revision={revision} AND j.binding_epoch={binding}
        AND EXISTS (SELECT 1 FROM custom_dns_control_events e WHERE e.object_id=j.id AND e.action='autonomous_job_queued' AND e.actor_id=0)"""
    valid = "j.state='succeeded' AND j.attempt_count=1 AND j.lease_id IS NULL AND j.credential_delivered_at IS NOT NULL AND j.result_code='ok' AND j.finished_at IS NOT NULL"
    first = one('SELECT COUNT(*)' + base + f" AND j.id='{FIRST_JOB}' AND {valid};")
    if first != '1': raise RuntimeError('baseline_or_binding_changed')
    created = one(f"SELECT created_at FROM custom_dns_control_jobs WHERE id='{FIRST_JOB}';")
    if not created.isdigit(): raise RuntimeError('baseline_or_binding_changed')
    recent = base + ' AND j.created_at>=' + created
    counts = one("SELECT CONCAT(COUNT(DISTINCT CASE WHEN " + valid + " THEN j.id END),'|',"
        "COUNT(DISTINCT CASE WHEN j.state IN ('failed','expired','obsolete') OR (j.state='succeeded' AND "
        "(j.attempt_count<>1 OR j.lease_id IS NOT NULL OR j.credential_delivered_at IS NULL OR j.result_code IS NULL "
        "OR j.result_code<>'ok' OR j.finished_at IS NULL)) THEN j.id END),'|',"
        "COUNT(DISTINCT CASE WHEN j.state IN ('pending','running') THEN j.id END))" + recent + ';')
    parts = counts.split('|')
    if len(parts) != 3 or any(not x.isdigit() for x in parts): raise RuntimeError('observation_invalid')
    followup = one("SELECT COALESCE((SELECT j.id" + base + f" AND j.created_at>={int(created)+300} AND {valid} ORDER BY j.created_at DESC,j.id DESC LIMIT 1),'none');")
    if followup != 'none' and not re.fullmatch('[0-9a-f]{32}', followup): raise RuntimeError('observation_invalid')
    return {'successful':int(parts[0]),'failed':int(parts[1]),'active':int(parts[2]),'followup':None if followup=='none' else followup}


def main():
    if os.geteuid() != 0: raise RuntimeError('root_required')
    import importlib.util
    def module(name, relative):
        spec=importlib.util.spec_from_file_location(name,ROOT / relative)
        obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj
    b3=module('b3','tools/live/dns9-phase-b3-first-cycle.py')
    b4=module('b4','tools/live/dns9-b4-list-zones.py')
    pdo=module('pdo','tools/live/dns9-pdo-hotfix.py')
    def private_doc(path):
        snap=pdo.snapshot(path)
        if (snap['uid'],snap['gid'],snap['mode']) != (0,0,0o600): raise RuntimeError('activation_receipt_invalid')
        doc=json.loads(snap['data'])
        if not isinstance(doc,dict): raise RuntimeError('activation_receipt_invalid')
        return doc
    receipt=private_doc(BACKUP/'receipt.json')
    validate_receipt(receipt)
    marker=private_doc(MARKER)
    if marker != {'source_sha':SOURCE_SHA,'backup':str(BACKUP)}: raise RuntimeError('activation_marker_invalid')
    snap=pdo.snapshot(BACKUP/'prestate.json')
    if (snap['uid'],snap['gid'],snap['mode']) != (0,0,0o600) or hashlib.sha256(snap['data']).hexdigest()!=BACKUP_SHA:
        raise RuntimeError('activation_backup_invalid')
    if not b3.timer_enabled() or not b3.timer_active(): raise RuntimeError('timer_not_active')
    summary=inspect_jobs(b3.one,receipt['revision'],receipt['binding_epoch'])
    first_count,first_digest=b4.read_result(b3,FIRST_JOB)
    if first_count != 172 or first_digest != PAYLOAD_SHA: raise RuntimeError('baseline_result_changed')
    print('DNS9_OBSERVE_FIRST_JOB='+FIRST_JOB)
    print('DNS9_OBSERVE_SUCCESSFUL_AUTO_JOBS='+str(summary['successful']))
    print('DNS9_OBSERVE_FAILED_AUTO_JOBS='+str(summary['failed']))
    print('DNS9_OBSERVE_ACTIVE_AUTO_JOBS='+str(summary['active']))
    if summary['followup']:
        count,digest=b4.read_result(b3,summary['followup'])
        print('DNS9_OBSERVE_FOLLOWUP_JOB='+summary['followup'])
        print('DNS9_OBSERVE_FOLLOWUP_ZONE_COUNT='+str(count))
        print('DNS9_OBSERVE_FOLLOWUP_PAYLOAD_SHA256='+digest)
    print('DNS9_OBSERVE_SERVER_TIMER=enabled_active')
    print('DNS9_OBSERVE_DB_MUTATION=0')
    print('DNS9_OBSERVE_REMOTE_MUTATION=0')
    print('DNS9_OBSERVE_DNS_WRITE=0')
    result='DEGRADED' if summary['failed'] or summary['active']>1 else ('PASS' if summary['followup'] else 'WAITING')
    print('DNS9_OBSERVE_RESULT='+result)


if __name__ == '__main__':
    try: main()
    except Exception:
        print('DNS9_OBSERVE_ERROR=read_only_check_stopped',file=sys.stderr)
        raise SystemExit(1)
