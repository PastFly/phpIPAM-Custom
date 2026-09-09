#!/usr/bin/env python3
"""Panel guard/escaping and inherited AJAX guard tests; no external requests."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class Panel(unittest.TestCase):
    def execute(self, admin=True, query='VM', failure=False, context=True):
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp)
            panel = target / 'app/tools/search/vmware-global-panel.php'; panel.parent.mkdir(parents=True)
            shutil.copyfile(ROOT / 'layers/phase5f331/app/tools/search/vmware-global-panel.php', panel)
            classes = target / 'functions/classes'; classes.mkdir(parents=True)
            (classes / 'class.IntegrationVmwareVcenter.php').write_text('<?php class IntegrationVmwareVcenter {}')
            (classes / 'class.IntegrationSecretCipher.php').write_text('<?php class IntegrationSecretCipher { static function from_keyring_file() { return new stdClass; }}')
            (classes / 'class.IntegrationVmwareVcenterConfigStore.php').write_text('<?php class IntegrationVmwareVcenterConfigStore { function __construct($a,$b) {} function list_configs() {' +
                ('throw new RuntimeException("SENSITIVE_FIXTURE_ERROR");' if failure else 'return [["id"=>1,"enabled"=>true,"has_credential"=>true,"name"=>"<img src=x> Enabled", "verify_tls"=>false], ["id"=>2,"enabled"=>false,"has_credential"=>true,"name"=>"DISABLED_CONNECTOR", "verify_tls"=>true]];') + '}}')
            bootstrap = '<?php function create_link(...$a) { return "/ipam/index.php?page=integrations&section=vmware"; }\n'
            if context:
                bootstrap += 'class C {function csrf_cookie(...$a) {return "fixture-csrf";}} class U {public $Crypto; function __construct() {$this->Crypto=new C;} function check_user_session() {} function is_admin($a) {return ' + ('true' if admin else 'false') + ';}} $User=new U; $Database=new stdClass;\n'
            bootstrap += '$searchTerm=json_decode(' + repr(json.dumps(query, ensure_ascii=True)) + '); include ' + repr(str(panel)) + '; print "NATIVE_RESULTS_OK";'
            script = target / 'run.php'; script.write_text(bootstrap)
            return subprocess.check_output(['php', str(script)], text=True)

    def test_nonadmin_no_panel(self):
        self.assertEqual(self.execute(admin=False), 'NATIVE_RESULTS_OK')

    def test_direct_no_context(self):
        self.assertEqual(self.execute(context=False), 'NATIVE_RESULTS_OK')

    def test_escape_query_and_connector(self):
        text = self.execute(query='<script>alert(1)</script>')
        self.assertNotIn('<img src=x>', text)
        self.assertNotIn('<script>alert(1)</script>', text)
        self.assertIn('&lt;script&gt;', text)
        self.assertNotIn('DISABLED_CONNECTOR', text)
        self.assertIn('NATIVE_RESULTS_OK', text)

    def test_empty_and_oversize(self):
        self.assertEqual(self.execute(query=''), 'NATIVE_RESULTS_OK')
        self.assertEqual(self.execute(query='x' * 257), 'NATIVE_RESULTS_OK')

    def test_provider_exception_isolated(self):
        text = self.execute(failure=True)
        self.assertNotIn('SENSITIVE_FIXTURE_ERROR', text)
        self.assertIn('NATIVE_RESULTS_OK', text)

    def test_wrapper_inherits_endpoint_guards(self):
        for admin, expected in [(False, 'administrative_privileges_required'), (True, 'invalid_csrf')]:
            with tempfile.TemporaryDirectory() as temp:
                target = Path(temp)
                wrapper = target / 'app/tools/search/vmware-global-result.php'; wrapper.parent.mkdir(parents=True)
                shutil.copyfile(ROOT / 'layers/phase5f331/app/tools/search/vmware-global-result.php', wrapper)
                endpoint = target / 'app/integrations/vmware/enriched-search-result.php'; endpoint.parent.mkdir(parents=True)
                shutil.copyfile(ROOT / 'layers/phase5f33/app/integrations/vmware/enriched-search-result.php', endpoint)
                functions = target / 'functions/functions.php'; functions.parent.mkdir()
                functions.write_text('<?php function disable_php_errors() {} class Database_PDO {} class C {function csrf_cookie(...$a) {return false;}} class User {public $Crypto; function __construct($a) {$this->Crypto=new C;} function check_user_session() {} function is_admin($a) {return ' + ('true' if admin else 'false') + ';}} $POST=(object)["csrf_cookie"=>"invalid"];')
                result = subprocess.check_output(['php', str(wrapper)], text=True)
                self.assertEqual(json.loads(result)['error'], expected)


if __name__ == '__main__': unittest.main(verbosity=2)
