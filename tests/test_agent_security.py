"""Run with: python3 -m unittest discover -s tests -v.

Exercises the shipped agent implementation without scanning any real network.
"""
import ast
import http.client
import ipaddress
import json
import threading
from pathlib import Path
import re
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load_agents():
    sources = [('package', (ROOT / 'agent/arcoll_agent.py').read_text())]
    agents = []
    for name, source in sources:
        module = types.ModuleType('agent_security_test')
        with patch('sys.argv', ['arcoll_agent.py']), patch.dict('os.environ', {'ARCOLL_AGENT_TOKEN': 'test-only-token'}):
            exec(compile(source, name, 'exec'), module.__dict__)
        agents.append((name, module, source))
    return agents


class AgentSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.agents = load_agents()

    def request(self, module, path, address='127.0.0.1', origin=None, token=None, method='GET'):
        handler = object.__new__(module.Handler)
        handler.client_address = (address, 12345)
        handler.path = path
        handler.headers = {}
        if origin is not None:
            handler.headers['Origin'] = origin
        if token is not None:
            handler.headers['Authorization'] = 'Bearer ' + token
        handler.reply = lambda code, payload, **kw: (code, payload)
        return getattr(handler, 'do_' + method)()

    def test_listener_is_loopback_in_every_distribution(self):
        for name, module, source in self.agents:
            with self.subTest(distribution=name):
                self.assertEqual(module.LISTEN_HOST, '127.0.0.1')
                calls = [n for n in ast.walk(ast.parse(source)) if isinstance(n, ast.Call) and isinstance(n.func, ast.Name) and n.func.id == 'ThreadingHTTPServer']
                self.assertEqual(len(calls), 1)
                self.assertEqual(ast.unparse(calls[0].args[0]), '(LISTEN_HOST, PORT)')

    def test_nonlocal_requests_cannot_reach_operations_even_with_token(self):
        for name, module, _ in self.agents:
            for address in ['192.168.1.2', '10.0.0.2', '172.16.0.2', '8.8.8.8', 'invalid']:
                for path in ['/api/health', '/api/devices?refresh=1', '/api/system', '/api/wol?mac=001122334455']:
                    with self.subTest(distribution=name, address=address, path=path), patch.object(module, 'do_scan') as scan, patch.object(module, 'wol') as wol:
                        code, _ = self.request(module, path, address, token='test-only-token')
                        self.assertEqual(code, 403)
                        scan.assert_not_called()
                        wol.assert_not_called()

    def test_origin_check_keeps_local_desktop_and_authorized_browser_working(self):
        for name, module, _ in self.agents:
            module.ALLOWED_ORIGINS = ['https://panel.example']
            for origin, expected in [(None, 200), ('https://panel.example', 200), ('https://untrusted.example', 403)]:
                with self.subTest(distribution=name, origin=origin), patch.object(module, 'do_scan', return_value=[]) as scan:
                    code, _ = self.request(module, '/api/devices', origin=origin)
                    self.assertEqual(code, expected)
                    self.assertEqual(scan.call_count, int(expected == 200))

    def test_inventory_requires_exact_token_and_fails_closed_without_configuration(self):
        for name, module, _ in self.agents:
            with patch.object(module, 'system_inventory', return_value={'hostname': 'test'}) as inventory:
                for token in [None, '', 'wrong', 'test-only-token-extra']:
                    with self.subTest(distribution=name, token=token):
                        self.assertEqual(self.request(module, '/api/system', token=token)[0], 403)
                inventory.assert_not_called()
                self.assertEqual(self.request(module, '/api/system', token='test-only-token')[0], 200)
                with patch.object(module, 'AGENT_TOKEN', ''):
                    self.assertEqual(self.request(module, '/api/system', token='')[0], 403)

    def test_preflight_does_not_grant_nonlocal_access(self):
        for name, module, _ in self.agents:
            with self.subTest(distribution=name):
                self.assertEqual(self.request(module, '/api/devices', method='OPTIONS')[0], 204)
                self.assertEqual(self.request(module, '/api/devices', address='192.168.1.2', method='OPTIONS')[0], 403)

    def test_http_server_preserves_browser_access_and_rejects_untrusted_origin(self):
        for name, module, _ in self.agents:
            module.ALLOWED_ORIGINS = ['https://panel.example']
            with self.subTest(distribution=name), patch.object(module, 'do_scan', return_value=[{'ip': '192.168.1.7'}]) as scan:
                server = module.ThreadingHTTPServer((module.LISTEN_HOST, 0), module.Handler)
                worker = threading.Thread(target=server.serve_forever, daemon=True)
                worker.start()
                try:
                    connection = http.client.HTTPConnection(*server.server_address, timeout=3)
                    try:
                        connection.request('GET', '/api/devices', headers={'Origin': 'https://panel.example'})
                        response = connection.getresponse()
                        self.assertEqual(response.status, 200)
                        self.assertEqual(response.getheader('Access-Control-Allow-Origin'), 'https://panel.example')
                        self.assertEqual(json.loads(response.read())['devices'][0]['ip'], '192.168.1.7')
                        connection.request('GET', '/api/devices', headers={'Origin': 'https://untrusted.example'})
                        response = connection.getresponse()
                        self.assertEqual(response.status, 403)
                        self.assertIsNone(response.getheader('Access-Control-Allow-Origin'))
                        response.read()
                        self.assertEqual(scan.call_count, 1)
                    finally:
                        connection.close()
                finally:
                    server.shutdown()
                    server.server_close()
                    worker.join(timeout=3)

    def test_scan_does_not_forward_installation_token_to_discovered_hosts(self):
        neighbors = {'192.168.1.1': '00:11:22:33:44:55', '192.168.1.2': '00:11:22:33:44:66'}
        for name, module, _ in self.agents:
            with (
                self.subTest(distribution=name),
                patch.object(module, 'local_ip', return_value='192.168.1.2'),
                patch.object(module, 'local_network', return_value=ipaddress.ip_network('192.168.1.0/30')),
                patch.object(module, 'gateway', return_value='192.168.1.1'),
                patch.object(module, 'arp_table', return_value=neighbors),
                patch.object(module, 'ping', return_value=1.2),
                patch.object(module, 'reverse_name', return_value='test-device'),
                patch.object(module, 'probe_services', return_value=[]),
                patch.object(module, 'system_inventory', return_value={}),
                patch.object(module.urllib.request, 'urlopen') as outbound,
            ):
                devices = module.do_scan(force=True)
                self.assertEqual({device['ip'] for device in devices}, set(neighbors))
                outbound.assert_not_called()


if __name__ == '__main__':
    unittest.main()
