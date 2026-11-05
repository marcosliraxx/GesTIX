# Caminho: tests/test_practical_tools.py
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'agentes/windows'))
import arcoll_toolkit as toolkit
class PracticalToolTests(unittest.TestCase):
    def test_disk_space_warns_without_claiming_hardware_failure(self):
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit,'_ps',return_value={'data':[{'DeviceID':'C:','Size':10000,'FreeSpace':500}]}):
            result=toolkit.run_quick_command('storage_health')
        self.assertEqual(result['status'],'completed');self.assertEqual(result['details']['level'],'warning');self.assertEqual(result['details']['volumes'][0]['free_percent'],5)
        self.assertIn('pouco espaço',result['summary'])
    def test_stopped_manual_services_do_not_raise_false_alarm(self):
        data=[{'Name':'Dhcp','State':'Stopped','StartMode':'Manual'},{'Name':'Winmgmt','State':'Running','StartMode':'Auto'}]
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit,'_ps',return_value={'data':data}):result=toolkit.run_quick_command('service_health')
        self.assertEqual(result['details']['level'],'good')
    def test_stopped_automatic_service_warns_in_plain_language(self):
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit,'_ps',return_value={'data':{'Name':'Winmgmt','State':'Stopped','StartMode':'Auto'}}):result=toolkit.run_quick_command('service_health')
        self.assertEqual(result['details']['level'],'warning');self.assertIn('serviço importante',result['summary']);self.assertNotIn('Winmgmt',result['summary'])
    def test_unsupported_platform_reports_unavailable_instead_of_success(self):
        with patch.object(toolkit.platform,'system',return_value='Linux'):result=toolkit.run_quick_command('restart_readiness')
        self.assertEqual(result['status'],'failed');self.assertIn('Windows',result['summary'])
    def test_exception_text_does_not_reach_user_notification(self):
        with patch.object(toolkit,'storage_health',side_effect=RuntimeError('Traceback: SECRET_INTERNAL_CODE')):
            with patch.dict(toolkit.QUICK_COMMANDS,{'storage_health':('Espaço dos discos',toolkit.storage_health)}):result=toolkit.run_quick_command('storage_health')
        self.assertEqual(result['status'],'failed');self.assertNotIn('SECRET_INTERNAL_CODE',result['summary'])
    def test_connection_and_certificate_failures_are_explained(self):
        self.assertIn('internet',toolkit.friendly_error('connection timed out'));self.assertIn('segurança',toolkit.friendly_error('CERTIFICATE_VERIFY_FAILED'))
