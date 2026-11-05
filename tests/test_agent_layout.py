# Caminho: tests/test_agent_layout.py
"""Mede widgets nativos sem capturas; execute em Linux com xvfb-run."""
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agentes/windows'))
try:
    import tkinter as tk
except ImportError:
    tk = None


@unittest.skipUnless(tk is not None and (os.environ.get('DISPLAY') or os.name == 'nt'), 'Requer Tkinter e display')
class AgentLayoutTests(unittest.TestCase):
    def test_sidebar_and_backup_controls_fit_desktop_sizes(self):
        from arcoll_app import ArcollApp
        from arcoll_cloud import CloudBackup
        from arcoll_toolkit import AppearanceManager
        with tempfile.TemporaryDirectory() as folder, patch.dict(os.environ, {'LOCALAPPDATA': folder, 'APPDATA': folder}):
            app = ArcollApp.__new__(ArcollApp)
            app.root = tk.Tk()
            try:
                app.allowed_remote_tabs = None
                app.cloud_backup = CloudBackup('https://example.test', 'a' * 64, 'T3ST1', 'b' * 64, directory=Path(folder) / 'state')
                for method in ['_build_server', '_build_dashboard', '_build_speed', '_build_devices', '_build_map', '_build_connection']:
                    setattr(app, method, Mock())
                app._style()
                app._modern_header()
                app._tabs()
                app.appearance = AppearanceManager(app.root, '3.9.0', controls=False, restyle=app._style)
                app.nb.select(app.clean_page)
                cleaner = app.cc_frames[0]
                cleaner.go('backup')
                for width, height in [(820, 560), (1080, 720), (1440, 900)]:
                    app.root.geometry('%dx%d' % (width, height))
                    app.root.update()
                    app._refresh_navigation()
                    app.root.update()
                    for label, (button, page) in app._nav_buttons.items():
                        if button.winfo_ismapped():
                            self.assertLessEqual(button.winfo_rootx() + button.winfo_width(), app.root.winfo_rootx() + width + 2, label)
                    self.assertEqual(str(app.nb.select()), str(app.clean_page))
                    controls = []
                    def walk(widget):
                        if widget.winfo_class() == 'TButton' and widget.winfo_ismapped():
                            controls.append(widget)
                        for child in widget.winfo_children():
                            walk(child)
                    walk(cleaner.view)
                    self.assertGreaterEqual(len(controls), 5)
                    for button in controls:
                        self.assertLessEqual(button.winfo_rootx() + button.winfo_width(), app.root.winfo_rootx() + width + 2, button.cget('text'))
                app.nb.hide(app.clean_page)
                app._refresh_navigation()
                app.root.update()
                self.assertFalse(app._nav_buttons['Clean'][0].winfo_ismapped(), 'A navegação não pode reabrir uma aba ocultada por permissão/bloqueio')
                app.nb.add(app.clean_page)
                app._refresh_navigation()
                for choice in ['light', 'dark', 'auto']:
                    app.appearance.set_choice(choice)
                    app._refresh_title_and_theme()
                    app.root.update()
                    self.assertEqual(app.appearance.choice, choice)
            finally:
                for callback in app.root.tk.call('after', 'info'):
                    app.root.tk.call('after', 'cancel', callback)
                app.root.destroy()


if __name__ == '__main__':
    unittest.main()
