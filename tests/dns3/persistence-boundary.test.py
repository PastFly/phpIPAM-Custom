#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
SQL=(ROOT/'database/dns3/001-collector-control.sql').read_text()
ADAPTER=(ROOT/'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php').read_text()
n=0
def check(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

check(SQL.count('CREATE TABLE ')==4,'exactly four DNS3 tables')
check(SQL.count('ENGINE=InnoDB')==4,'all DNS3 tables use InnoDB')
for table in [
    'custom_dns_collectors','custom_dns_collector_assignments',
    'custom_dns_control_jobs','custom_dns_control_events'
]:
    check(('CREATE TABLE '+table) in SQL,'required table '+table)

for forbidden in ['DROP TABLE','TRUNCATE','ALTER TABLE','IF NOT EXISTS','secret_envelope','password','profile_id']:
    check(forbidden not in SQL,'schema forbidden '+forbidden)

check('token_digest CHAR(64)' in SQL,'digest-only token column')
check('control_token' not in SQL,'raw control token has no column')
check("job_type IN ('test_connection','list_zones')" in SQL,'job types fixed by schema')
check("'collect_selected_zones'" not in SQL,'collection job not enabled in schema')
check("state IN ('pending','running','succeeded','failed','expired','obsolete')" in SQL,'job states bounded')
check('REFERENCES custom_dns_connections (id)' in SQL,'assignment/job bound to DNS1 connections')
check('REFERENCES custom_dns_collectors (id)' in SQL,'assignment/job bound to collectors')
check('CREATE TABLE custom_dns_config_guard' not in SQL,'DNS3 reuses DNS1 guard instead of duplicating it')

check('custom_dns_config_guard WHERE id = 1 FOR UPDATE' in ADAPTER,'adapter uses shared DNS mutex')
check('OFFSET 9999' in ADAPTER,'security audit bounded at ten thousand')
check('beginTransaction' in ADAPTER and 'rollBack' in ADAPTER and 'commit' in ADAPTER,'transaction lifecycle explicit')
check("getObjectsQuery('no_html_escape'" in ADAPTER,'phpIPAM Database_PDO read primitive used')
check('runQuery(' in ADAPTER,'phpIPAM Database_PDO write primitive used')
check('PDO' not in ADAPTER.replace('Database_PDO',''),'adapter does not reach raw PDO directly')
check('CREATE TABLE' not in ADAPTER and 'ALTER TABLE' not in ADAPTER,'runtime adapter performs no DDL')

print(f'DNS3_PERSISTENCE_BOUNDARY_ASSERTIONS={n}')
print('DNS3_PERSISTENCE_BOUNDARY_TESTS_OK=1')
