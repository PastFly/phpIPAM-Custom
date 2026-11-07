#!/usr/bin/env python3
"""Coordinate a single explicitly approved DNS automation activation."""
import getpass
import hashlib
import json
import os
import re
import secrets
import select
import signal
import stat
import subprocess
import sys
import time
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]


def validate_task_state(doc, enabled):
    if (not isinstance(doc, dict) or doc.get('enabled') is not enabled
            or type(doc.get('last_run_epoch')) is not int):
        raise RuntimeError('windows_task_state_unknown')


def validate_cycle_evidence(evidence):
    if not isinstance(evidence, dict):
        raise RuntimeError('cycle_evidence_invalid')
    job_id = evidence.get('job_id')
    if (not isinstance(job_id, str) or not re.fullmatch('[0-9a-f]{32}', job_id)
            or evidence.get('state') != 'succeeded' or type(evidence.get('attempts')) is not int
            or evidence['attempts'] != 1 or type(evidence.get('zone_count')) is not int
            or not 0 <= evidence['zone_count'] <= 1024
            or type(evidence.get('old_jobs_expired')) is not int or evidence['old_jobs_expired'] != 2
            or any(evidence.get(k) is not True for k in ('timer_enabled', 'timer_active',
                'windows_enabled', 'windows_ran', 'service_ran'))):
        raise RuntimeError('cycle_evidence_invalid')


def run_sequence(backend):
    locked = False
    windows_attempted = False
    timer_attempted = False
    try:
        backend.preflight()
        backend.reserve()
        locked = True
        backend.lock_and_backup()
        backend.recheck_windows()
        windows_attempted = True
        backend.enable_windows()
        timer_attempted = True
        backend.enable_timer()
        backend.commit_connection()
        result = backend.verify_cycle()
        backend.finish(result)
        return result
    except BaseException:
        for needed, name in ((timer_attempted, 'disable_timer'), (windows_attempted, 'disable_windows'),
                             (locked, 'abort_lock')):
            if needed:
                try:
                    getattr(backend, name)()
                except BaseException:
                    print('DNS9_ACTIVATION_CLEANUP_UNKNOWN=' + name, file=sys.stderr, flush=True)
        raise


class LiveBackend:
    """The fixed production gate; all mutable steps occur under a retained marker."""
    def __init__(self):
        self.pre = None
        self.password = None
        self.remote = None
        self.child = None
        self.directory = None
        self.sha = None
        self.start_epoch = None
        self.backup_digest = None
        self.revision = None
        self.binding = None
        self.service_before = None
        self.stdout_buffer = b''

    def query(self, sql):
        proc = subprocess.run(['mariadb', '--batch', '--skip-column-names', 'phpipam'],
                              input=sql, text=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                              timeout=15, check=False)
        lines = [line for line in proc.stdout.splitlines() if line.strip()]
        if proc.returncode != 0 or len(lines) != 1:
            raise RuntimeError('database_state_unknown')
        return lines[0]

    def service_start(self):
        proc = subprocess.run(['systemctl', 'show', 'phpipam-dns-autonomous.service',
                               '--property=ExecMainStartTimestampMonotonic', '--value'],
                              stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True,
                              timeout=15, check=False)
        value = proc.stdout.strip()
        if proc.returncode != 0 or not value.isdigit():
            raise RuntimeError('service_state_unknown')
        return int(value)

    def preflight(self):
        if os.geteuid() != 0:
            raise RuntimeError('root_required')
        self.sha = (ROOT / 'EXPECTED_SHA').read_text().strip()
        if not re.fullmatch('[0-9a-f]{40}', self.sha):
            raise RuntimeError('source_sha_invalid')
        from dns9_winrm_common import ensure_ntlm_md4_runtime, protocol
        # Re-exec, if necessary, must happen before prompting or reserving a marker.
        print('DNS9_ACTIVATION_MD4_PROVIDER=' + ensure_ntlm_md4_runtime(), flush=True)
        import importlib.util
        spec = importlib.util.spec_from_file_location('activation_preflight', ROOT / 'tools/live/dns9-activation-preflight.py')
        self.pre = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.pre)
        m = self.pre.module
        self.b3 = m('b3', ROOT / 'tools/live/dns9-phase-b3-first-cycle.py')
        self.b4 = m('b4', ROOT / 'tools/live/dns9-b4-list-zones.py')
        self.pdo = m('pdo', ROOT / 'tools/live/dns9-pdo-hotfix.py')
        self.ui = m('ui', ROOT / 'tools/live/dns9-ui-deploy.py')
        self.fix = m('fix', ROOT / 'tools/live/dns9-scheduler-deploy.py')
        self.post = m('post', ROOT / 'tools/live/dns9-phase-b3-post-check.py')
        args = (self.b3, self.b4, self.pdo, self.ui, self.fix, self.post)
        if self.pre.local_preflight(*args) != 4:
            raise RuntimeError('actor_changed')
        self.password = getpass.getpass('WinRM password: ')
        if not self.password:
            raise RuntimeError('password_empty')
        self.pre.remote_preflight(self.b3, self.password)
        if self.pre.local_preflight(*args) != 4:
            raise RuntimeError('actor_changed')
        self.remote = protocol(self.pre.SERVER, self.pre.USER, self.password)
        self.service_before = self.service_start()
        print('DNS9_ACTIVATION_FRESH_PREFLIGHT=PASS', flush=True)

    def reserve(self):
        root = self.pre.GUARDS
        backup_root = Path('/var/backups/phpipam-custom')
        for path in (root, backup_root):
            info = path.lstat()
            if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
                    or info.st_uid != 0 or info.st_gid != 0 or info.st_mode & 0o022):
                raise RuntimeError('activation_directory_invalid')
        self.directory = backup_root / ('dns9-activation-' + secrets.token_hex(8))
        marker = root / 'dns9-production-activation-global'
        fd = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            data = (json.dumps({'source_sha': self.sha, 'backup': str(self.directory)}, sort_keys=True) + '\n').encode()
            if os.write(fd, data) != len(data):
                raise RuntimeError('activation_marker_write_failed')
            os.fsync(fd)
        finally:
            os.close(fd)
        dfd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        self.directory.mkdir(mode=0o700)
        dfd = os.open(backup_root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        print('DNS9_ACTIVATION_MARKER_CREATED=1', flush=True)
        print('DNS9_ACTIVATION_BACKUP=' + str(self.directory), flush=True)

    def child_line(self, timeout):
        deadline = time.monotonic() + timeout
        while b'\n' not in self.stdout_buffer:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise RuntimeError('activation_mutex_timeout')
            ready, _, _ = select.select([self.child.stdout], [], [], remaining)
            if not ready:
                raise RuntimeError('activation_mutex_timeout')
            chunk = os.read(self.child.stdout.fileno(), 512)
            if not chunk:
                raise RuntimeError('activation_mutex_output_invalid')
            self.stdout_buffer += chunk
            if len(self.stdout_buffer) > 512:
                raise RuntimeError('activation_mutex_output_invalid')
        line, self.stdout_buffer = self.stdout_buffer.split(b'\n', 1)
        return line.decode('ascii').strip()

    def lock_and_backup(self):
        self.child = subprocess.Popen(['php', str(ROOT / 'tools/live/dns9-activation-mutex.php'), str(self.directory)],
                                      stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                      bufsize=0)
        line = self.child_line(45)
        match = re.fullmatch('DNS9_ACTIVATION_MUTEX_READY=([0-9a-f]{64})', line)
        if match is None:
            raise RuntimeError('activation_mutex_failed')
        self.backup_digest = match.group(1)
        path = self.directory / 'prestate.json'
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_gid != 0
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1
                or hashlib.sha256(path.read_bytes()).hexdigest() != self.backup_digest):
            raise RuntimeError('activation_backup_invalid')
        dfd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        self.start_epoch = int(time.time())
        print('DNS9_ACTIVATION_BACKUP_SHA256=' + self.backup_digest, flush=True)

    def task(self, action, expected):
        from dns9_winrm_common import json_line, ps_quote, run_ps
        if action not in ('enable', 'disable', 'status'):
            raise RuntimeError('task_action_invalid')
        source = (ROOT / 'tools/live/dns9-activation-task-control.ps1').read_text()
        script = '$PermanentName=' + ps_quote('phpIPAM Microsoft DNS Collector') + '\n$Action=' + ps_quote(action) + '\n' + source
        rc, out, _ = run_ps(self.remote, script)
        if rc != 0:
            raise RuntimeError('windows_task_state_unknown')
        doc = json_line(out, 'DNS9_ACTIVATION_TASK_STATE=')
        validate_task_state(doc, expected)
        return doc

    def enable_windows(self):
        self.task('enable', True)
        print('DNS9_ACTIVATION_WINDOWS_TASK=enabled', flush=True)

    def recheck_windows(self):
        self.pre.remote_preflight(self.b3, self.password)

    def timer_command(self, action):
        proc = subprocess.run(['systemctl', action, '--now', 'phpipam-dns-autonomous.timer'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=25, check=False)
        if proc.returncode != 0:
            raise RuntimeError('timer_state_unknown')

    def enable_timer(self):
        self.timer_command('enable')
        if not self.b3.timer_enabled() or not self.b3.timer_active():
            raise RuntimeError('timer_state_unknown')
        print('DNS9_ACTIVATION_SERVER_TIMER=enabled_active', flush=True)

    def commit_connection(self):
        self.child.stdin.write(b'COMMIT_ENABLE\n')
        self.child.stdin.flush()
        line = self.child_line(30)
        match = re.fullmatch('DNS9_ACTIVATION_MUTEX_COMMITTED=([1-9][0-9]*)\\|([1-9][0-9]*)', line)
        if match is None or self.child.wait(timeout=10) != 0:
            raise RuntimeError('connection_commit_unknown')
        self.revision, self.binding = map(int, match.groups())
        print('DNS9_ACTIVATION_CONNECTION_COMMITTED=1', flush=True)

    def verify_cycle(self):
        connection = self.b3.PILOT_CONNECTION_ID
        collector = self.b3.PILOT_COLLECTOR_ID
        candidate = f"""SELECT COALESCE((SELECT j.id FROM custom_dns_control_jobs j
            JOIN custom_dns_control_events e ON e.object_id=j.id AND e.action='autonomous_job_queued' AND e.actor_id=0
            WHERE j.connection_id='{connection}' AND j.collector_id='{collector}' AND j.job_type='list_zones'
            AND j.configuration_revision={self.revision} AND j.binding_epoch={self.binding}
            AND j.created_at>={self.start_epoch - 120} ORDER BY j.created_at,j.id LIMIT 1),'none');"""
        deadline = time.monotonic() + 360
        job = None
        last_message = 0
        while time.monotonic() < deadline:
            if job is None:
                value = self.query(candidate)
                if value != 'none':
                    job = self.b3.checked_job_id(value)
                    print('DNS9_ACTIVATION_JOB_ID=' + job, flush=True)
            if job is not None:
                row = self.query(f"SELECT CONCAT(state,'|',attempt_count) FROM custom_dns_control_jobs WHERE id='{job}';")
                if row == 'succeeded|1':
                    break
                if row not in ('pending|0', 'running|1'):
                    raise RuntimeError('first_automatic_job_failed')
            now = time.monotonic()
            if now - last_message >= 30:
                print('DNS9_ACTIVATION_WAITING_FOR_AUTOMATIC_CYCLE=1', flush=True)
                last_message = now
            time.sleep(5)
        else:
            raise RuntimeError('automatic_cycle_timeout')
        db = SimpleNamespace(one=self.query, checked_job_id=self.b3.checked_job_id,
                             PILOT_CONNECTION_ID=connection, PILOT_COLLECTOR_ID=collector, SERVER=self.b3.SERVER)
        count, digest = self.b4.read_result(db, job)
        old = self.query("""SELECT CONCAT(COUNT(*),'|',COALESCE(SUM(state='expired' AND attempt_count=0 AND
            lease_id IS NULL AND credential_delivered_at IS NULL AND result_code='job_expired'),0))
            FROM custom_dns_control_jobs WHERE id IN ('0f5fbf81fc71164cc7c624fc6101925f','edfcd75935083e51c4536a390174057e');""")
        windows = self.task('status', True)
        evidence = {'job_id': job, 'state': 'succeeded', 'attempts': 1, 'zone_count': count,
                    'old_jobs_expired': 2 if old == '2|2' else 0,
                    'timer_enabled': self.b3.timer_enabled(), 'timer_active': self.b3.timer_active(),
                    'windows_enabled': windows['enabled'], 'windows_ran': windows['last_run_epoch'] >= self.start_epoch - 120,
                    'service_ran': self.service_start() > self.service_before}
        validate_cycle_evidence(evidence)
        return {**evidence, 'payload_sha256': digest}

    def abort_lock(self):
        if self.child is None:
            return
        if self.child.poll() is None:
            if self.child.stdin is not None:
                self.child.stdin.close()
            try: self.child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.child.kill()
                self.child.wait(timeout=5)

    def disable_timer(self):
        self.timer_command('disable')
        if self.b3.timer_enabled() or self.b3.timer_active():
            raise RuntimeError('timer_state_unknown')
        print('DNS9_ACTIVATION_SERVER_TIMER=disabled_inactive', flush=True)

    def disable_windows(self):
        self.task('disable', False)
        print('DNS9_ACTIVATION_WINDOWS_TASK=disabled', flush=True)

    def finish(self, result):
        receipt = {'source_sha': self.sha, 'backup_sha256': self.backup_digest, 'revision': self.revision,
                   'binding_epoch': self.binding, 'cycle': result, 'dns_write': False}
        path = self.directory / 'receipt.json'
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            data = (json.dumps(receipt, sort_keys=True) + '\n').encode()
            if os.write(fd, data) != len(data):
                raise RuntimeError('receipt_write_failed')
            os.fsync(fd)
        finally: os.close(fd)
        dfd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        print('DNS9_ACTIVATION_RECEIPT=' + str(path), flush=True)
        print('DNS9_ACTIVATION_ZONE_COUNT=' + str(result['zone_count']), flush=True)
        print('DNS9_ACTIVATION_PAYLOAD_SHA256=' + result['payload_sha256'], flush=True)
        print('DNS9_ACTIVATION_DNS_WRITE=0', flush=True)
        print('DNS9_ACTIVATION_OK=1', flush=True)


def main():
    from dns9_winrm_common import ensure_ntlm_md4_runtime
    if len(sys.argv) != 2 or sys.argv[1] != '--apply':
        raise RuntimeError('explicit_apply_required')
    def interrupted(signum, _frame):
        raise SystemExit(128 + signum)
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGHUP, interrupted)
    backend = LiveBackend()
    try: run_sequence(backend)
    finally: backend.password = None


if __name__ == '__main__':
    try: main()
    except Exception:
        print('DNS9_ACTIVATION_ERROR=stopped_requires_review', file=sys.stderr, flush=True)
        raise SystemExit(1)
