# Caminho: tests/test_support_client.py
import importlib.util
import os
from pathlib import Path
import sys
import unittest
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'agent'));sys.path.insert(0,str(ROOT/'support'))
try:
    from arcoll_support import RemoteAPI, SupportApp, VERSION
    import tkinter as tk
except ImportError:
    RemoteAPI=None

@unittest.skipUnless(RemoteAPI is not None,'Pillow and Tkinter are required')
class SupportClientTests(unittest.TestCase):
    def test_origin_requires_https_without_embedded_credentials(self):
        for origin in ['http://example.test','https://user:password@example.test','https://example.test/?token=secret','https://example.test/#fragment','https://example.test:444']:
            with self.subTest(origin=origin),self.assertRaises(ValueError):RemoteAPI(origin)
        api=RemoteAPI('https://example.test/workspace');self.assertEqual(api.host,'example.test');self.assertEqual(api.prefix,'/workspace');self.assertEqual(api.token,'')
    def test_release_matches_platform(self):self.assertEqual(VERSION,(ROOT/'VERSION').read_text().strip())
    @unittest.skipUnless(os.environ.get('DISPLAY') or sys.platform=='win32','A graphical display is required')
    def test_window_builds_and_coordinate_mapping_respects_letterbox(self):
        root=tk.Tk()
        try:
            app=SupportApp(root);root.update_idletasks();app.image_box=(100,50,800,400)
            event=type('Event',(),{'x':500,'y':250})();self.assertEqual(app.coordinates(event),{'x':.5,'y':.5})
            event.x=10;self.assertIsNone(app.coordinates(event));self.assertFalse(app.control)
            app.emit({'t':'m','x':0,'y':0});self.assertTrue(app.input_queue.empty())
            app.appearance.set_choice('dark');app.appearance.set_choice('light');app.close()
        finally:
            try:root.destroy()
            except tk.TclError:pass
