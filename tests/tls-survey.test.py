#!/usr/bin/env python3
"""Local TLS fixtures; no production config, inventory or vCenter access."""
import http.server
import os
from pathlib import Path
import re
import ssl
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'tools/live-vmware-tls-survey.sh'
s = SOURCE.read_text()
subprocess.run(['bash', '-n', SOURCE], check=True)
parts = re.findall(r"<<'PHP'\n(.*?)\nPHP", s, re.S)
assert len(parts) == 2
assert 'SELECT endpoint, verify_tls, enabled FROM custom_integrations' in s
assert 'secret_envelope' not in s and 'load_runtime_config' not in s
assert 'CURLOPT_SSL_VERIFYPEER=>true' in s and 'CURLOPT_SSL_VERIFYHOST=>2' in s
assert 'CURLOPT_PROXY=>\'\'' in s and 'CURLOPT_FOLLOWLOCATION=>false' in s
for forbidden in ['update-ca-certificates', 'curl -k', 'CURLOPT_USERPWD', 'CURLOPT_COOKIE', 'session_start']:
    assert forbidden not in s

class Handler(http.server.BaseHTTPRequestHandler):
    seen = []
    def log_message(self, *args):
        pass
    def do_HEAD(self):
        assert not self.headers.get('Authorization') and not self.headers.get('Cookie')
        self.seen.append(self.path)
        self.send_response(401 if self.path == '/sdk' else 200)
        self.end_headers()

with tempfile.TemporaryDirectory() as d:
    d = Path(d)
    for i, text in enumerate(parts):
        (d / f'{i}.php').write_text(text + '\n')
        subprocess.run(['php', '-l', d / f'{i}.php'], check=True)
    invalid = subprocess.run(['php', d / '0.php'], env=dict(os.environ, PHPIPAM_TLS_APP=str(d)), capture_output=True, text=True)
    assert invalid.returncode == 2 and not invalid.stdout
    assert 'No secrets printed' in invalid.stderr and str(d) not in invalid.stderr
    def openssl(*args):
        subprocess.run(['openssl', *map(str, args)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    openssl('req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=Fixture CA',
            '-keyout',d/'ca.key','-out',d/'ca.pem','-addext','basicConstraints=critical,CA:TRUE')
    openssl('req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=fixture.invalid',
            '-keyout',d/'leaf.key','-out',d/'leaf.csr')
    (d/'ext').write_text('subjectAltName=IP:127.0.0.1,DNS:fixture.invalid\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n')
    openssl('x509','-req','-in',d/'leaf.csr','-CA',d/'ca.pem','-CAkey',d/'ca.key',
            '-CAcreateserial','-days','1','-out',d/'leaf.pem','-extfile',d/'ext')
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(d/'leaf.pem', d/'leaf.key')
    server = http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.socket = ctx.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        for host, trusted, expected in [('127.0.0.1',False,'0'),('127.0.0.1',True,'1'),('localhost',True,'0')]:
            args=['php']
            if trusted: args += ['-d','curl.cainfo='+str(d/'ca.pem')]
            r=subprocess.run(args+[d/'1.php'],env=dict(os.environ,PHPIPAM_TLS_URL=f'https://{host}:{server.server_port}'),capture_output=True,text=True,timeout=30)
            assert r.returncode == 0, (r.returncode, r.stdout, r.stderr)
            assert f'HTTPS_TLS_VERIFIED={expected}' in r.stdout, r.stdout
            assert f'SDK_TLS_VERIFIED={expected}' in r.stdout, r.stdout
            if trusted and host=='127.0.0.1': assert 'HTTP_STATUS=401' in r.stdout
        assert Handler.seen == ['/', '/sdk'], Handler.seen
    finally:
        server.shutdown(); server.server_close()
print('TLS_SURVEY_SYNTAX_METADATA_NO_SECRETS_AND_REAL_TLS_FIXTURES_OK')
