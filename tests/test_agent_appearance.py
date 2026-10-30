"""Run under a graphical display: python3 -m unittest discover -s tests -p test_agent_appearance.py -v."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
try:
    import tkinter as tk
except ImportError:
    tk=None
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'agent'))
from arcoll_toolkit import AppearanceManager

@unittest.skipUnless(tk is not None and (os.environ.get('DISPLAY') or sys.platform in ('win32','darwin')), 'A graphical display and Tkinter are required')
class AppearanceTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.env=patch.dict(os.environ,{'APPDATA':self.temp.name,'LOCALAPPDATA':self.temp.name});self.env.start()
        self.root=tk.Tk();self.root.withdraw()
    def tearDown(self):
        self.root.destroy();self.env.stop();self.temp.cleanup()
    def test_light_dark_and_dynamic_canvas_share_semantic_colors(self):
        label=tk.Label(self.root,bg='#111111',fg='#ffffff');label.pack()
        canvas=tk.Canvas(self.root,bg='#0b1220');canvas.pack();text=canvas.create_text(10,10,text='test',fill='#ffffff')
        manager=AppearanceManager(self.root,'2.8.0')
        for choice in ['light','dark','light']:
            manager.set_choice(choice)
            self.assertEqual(label.cget('foreground'),manager.PALETTES[choice]['text'])
            self.assertEqual(canvas.itemcget(text,'fill'),manager.PALETTES[choice]['text'])
            dynamic=canvas.create_text(20,20,text='dynamic',fill='#ffffff')
            self.assertEqual(canvas.itemcget(dynamic,'fill'),manager.PALETTES[choice]['text'])
    def test_preference_is_saved_and_invalid_values_rejected(self):
        manager=AppearanceManager(self.root);manager.set_choice('dark')
        self.assertEqual(AppearanceManager.read_choice(),'dark')
        with self.assertRaises(ValueError):manager.set_choice('invalid')
        self.assertEqual(AppearanceManager.read_choice(),'dark')
        AppearanceManager.preference_path().write_text('[]')
        self.assertEqual(AppearanceManager.read_choice(),'auto')
    def test_auto_follows_operating_system(self):
        manager=AppearanceManager(self.root)
        manager.set_choice('auto')
        for theme in ['dark','light']:
            with patch.object(AppearanceManager,'system_theme',return_value=theme):
                manager.apply();self.assertEqual(manager.resolved,theme)
    def test_controls_work_with_existing_absolute_position_layout(self):
        tk.Label(self.root,text='Registration',bg='#0b1220').place(x=20,y=20,width=200,height=30)
        manager=AppearanceManager(self.root,'2.8.0');manager.set_choice('light');self.root.update_idletasks()
        self.assertTrue(any(child.winfo_manager()=='pack' for child in self.root.winfo_children()))
    def test_accent_button_remains_readable(self):
        button=tk.Button(self.root,bg='#22d3ee',fg='#06131b');button.pack()
        manager=AppearanceManager(self.root)
        for choice in ['light','dark']:
            manager.set_choice(choice);self.assertEqual(button.cget('foreground'),manager.PALETTES[choice]['onaccent'])
    def test_agent_windows_build_with_unified_version(self):
        self.root.destroy();self.root=tk.Tk();self.root.withdraw()
        import arcoll_app
        with patch.object(tk.Tk,'mainloop',lambda root:root.update_idletasks()),patch.object(arcoll_app.ArcollApp,'_start_focus_server'),patch.object(arcoll_app.ArcollApp,'_start_tray'),patch.object(arcoll_app.ArcollApp,'_start_realtime'),patch.object(arcoll_app.urllib.request,'urlopen',side_effect=RuntimeError('Network disabled during GUI test')):
            app=arcoll_app.ArcollApp()
            try:
                self.assertEqual(arcoll_app.AGENT_VERSION,(ROOT/'VERSION').read_text().strip())
                for theme in ['light','dark']:
                    app.appearance.set_choice(theme);app.root.geometry('800x600');app.root.update_idletasks()
                    self.assertEqual(app.appearance.resolved,theme)
                    self.assertLessEqual(app.nb.winfo_width(),app.root.winfo_width())
            finally:app.stop_event.set();app.root.destroy()
        with patch('sys.argv',['arcoll_desktop.py']),patch.object(tk.Tk,'mainloop',lambda root:root.update_idletasks()):
            import arcoll_desktop
            agent=arcoll_desktop.DesktopAgent()
            try:
                self.assertEqual(arcoll_desktop.VERSION,arcoll_app.AGENT_VERSION)
                agent.root.geometry('380x430');agent.root.deiconify();agent.root.update_idletasks();agent._fit_registration();agent.appearance.set_choice('light');agent.root.update_idletasks()
                self.assertLessEqual(agent.status.winfo_width(),agent.root.winfo_width())
            finally:agent.closed=True;agent.root.destroy()

if __name__=='__main__':unittest.main()
