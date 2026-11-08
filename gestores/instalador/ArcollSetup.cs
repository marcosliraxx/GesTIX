// Caminho: gestores/instalador/ArcollSetup.cs
// Instalador do Gestor Windows: um único .exe. O pacote (arquivos do gestor + gestor-config.json
// exclusivo da rede) é anexado ao final do executável pelo servidor (api/download-gestor.php).
//
// Layout do final do arquivo:  [ZIP do pacote][SHA-256 do ZIP: 32 bytes][tamanho do ZIP: Int64 LE][ARCSETUP]
//
// Parâmetros:  /S ou /silent  instalação sem janela (código de saída 0 = sucesso)
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Text;
using System.IO;
using System.IO.Compression;
using System.Management;
using System.Reflection;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("Instalador do Gestor de Rede")]
[assembly: AssemblyDescription("Instalador do Gestor de Rede para Windows")]
[assembly: AssemblyProduct("Gestor de Rede")]
[assembly: AssemblyCompany("Arcoll")]

namespace ArcollSetup
{
    internal sealed class PackageInfo
    {
        public string TempDir;
        public string SystemName = "Gestor de Rede";
        public string SystemDescription = "Instalação e configuração deste computador";
        public string Origin = "";
        public string Version = "";
        public byte[] IconBytes;
    }

    internal static class Log
    {
        private static readonly object Gate = new object();
        public static string Directory_ = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Arcoll", "logs");
        public static string FilePath { get { return Path.Combine(Directory_, "installation.log"); } }

        public static void Write(string line)
        {
            lock (Gate)
            {
                for (int i = 0; i < 5; i++)
                {
                    try
                    {
                        Directory.CreateDirectory(Directory_);
                        using (var fs = new FileStream(FilePath, FileMode.Append, FileAccess.Write, FileShare.ReadWrite))
                        using (var sw = new StreamWriter(fs, new UTF8Encoding(false)))
                            sw.WriteLine(DateTime.Now.ToString("s") + " [setup] " + line);
                        return;
                    }
                    catch { Thread.Sleep(80); }
                }
            }
        }
    }

    internal static class Program
    {
        public const string Magic = "ARCSETUP";
        public const string Destination = @"C:\Arcoll";

        [STAThread]
        private static int Main(string[] args)
        {
            bool silent = false;
            foreach (string a in args)
            {
                string x = a.Trim().ToLowerInvariant();
                if (x == "/s" || x == "/silent" || x == "-s" || x == "--silent" || x == "/verysilent") silent = true;
            }

            if (!IsAdministrator())
            {
                // O manifesto já solicita elevação; este é só um reforço (ex.: manifesto ignorado).
                try
                {
                    var psi = new ProcessStartInfo(Installer.SelfPath(), string.Join(" ", args))
                    { UseShellExecute = true, Verb = "runas" };
                    Process.Start(psi);
                    return 0;
                }
                catch
                {
                    Fail(silent, "A instalação requer permissão de administrador do Windows.");
                    return 5;
                }
            }

            bool created;
            using (var mutex = new Mutex(true, @"Global\ArcollGestorSetup", out created))
            {
                if (!created)
                {
                    Fail(silent, "Já existe uma instalação em andamento neste computador.");
                    return 3;
                }

                PackageInfo pkg;
                try
                {
                    pkg = Installer.OpenPackage();
                }
                catch (Exception ex)
                {
                    Log.Write("Pacote inválido: " + ex);
                    Fail(silent, ex.Message);
                    return 2;
                }

                if (silent)
                {
                    try
                    {
                        new Installer(pkg).Run(delegate (int p, string m) { Log.Write("[" + p + "] " + m); });
                        return 0;
                    }
                    catch (Exception ex)
                    {
                        Log.Write("ERRO: " + ex);
                        return 1;
                    }
                    finally { Installer.CleanTemp(pkg); }
                }

                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                var form = new SetupForm(pkg);
                Application.Run(form);
                Installer.CleanTemp(pkg);
                return form.Succeeded ? 0 : 1;
            }
        }

        internal static bool IsAdministrator()
        {
            using (WindowsIdentity id = WindowsIdentity.GetCurrent())
                return new WindowsPrincipal(id).IsInRole(WindowsBuiltInRole.Administrator);
        }

        internal static void Fail(bool silent, string message)
        {
            if (!silent)
                MessageBox.Show(message, "Instalador do Gestor", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    internal delegate void ProgressReport(int percent, string message);

    internal sealed class Installer
    {
        private static readonly string[] Required =
        {
            "arcoll_gestor.py", "arcoll_release.py", "arcoll_app.py", "arcoll_toolkit.py", "arcoll_remote.py",
            "arcoll_secure_helper.py", "rt_engine.py", "Instalar_Arcoll.ps1", "gestor-config.json", "favicon.ico"
        };

        private static readonly Regex SafePath = new Regex(@"^[A-Za-z0-9_./ -]+$", RegexOptions.Compiled);
        private readonly PackageInfo pkg;

        public Installer(PackageInfo pkg) { this.pkg = pkg; }

        // ---------- leitura do pacote embutido ----------
        public static PackageInfo OpenPackage()
        {
            string exe = SelfPath();
            byte[] zip;
            using (var fs = new FileStream(exe, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
            {
                if (fs.Length < 48 + 22) throw new InvalidOperationException("Este instalador não contém o pacote do gestor. Baixe novamente pelo painel da rede.");
                var trailer = new byte[48];
                fs.Seek(-48, SeekOrigin.End);
                ReadFully(fs, trailer, 48);
                if (Encoding.ASCII.GetString(trailer, 40, 8) != Program.Magic)
                    throw new InvalidOperationException("Este instalador não contém o pacote do gestor. Baixe novamente pelo painel da rede.");
                long len = BitConverter.ToInt64(trailer, 32);
                if (len < 22 || len > fs.Length - 48 || len > 268435456L)
                    throw new InvalidOperationException("Pacote do gestor corrompido. Baixe novamente pelo painel da rede.");
                zip = new byte[len];
                fs.Seek(-48 - len, SeekOrigin.End);
                ReadFully(fs, zip, (int)len);
                using (var sha = SHA256.Create())
                {
                    byte[] hash = sha.ComputeHash(zip);
                    for (int i = 0; i < 32; i++)
                        if (hash[i] != trailer[i])
                            throw new InvalidOperationException("O arquivo está corrompido (verificação de integridade falhou). Baixe novamente pelo painel da rede.");
                }
            }

            string temp = Path.Combine(Path.GetTempPath(), "ArcollSetup-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(temp);
            string root = Path.GetFullPath(temp).TrimEnd(Path.DirectorySeparatorChar, '\\') + Path.DirectorySeparatorChar;
            using (var ms = new MemoryStream(zip))
            using (var archive = new ZipArchive(ms, ZipArchiveMode.Read))
            {
                foreach (ZipArchiveEntry entry in archive.Entries)
                {
                    if (string.IsNullOrEmpty(entry.Name)) continue; // pasta
                    string rel = entry.FullName.Replace(Path.DirectorySeparatorChar, '/').Replace('\\', '/');
                    if (!SafePath.IsMatch(rel) || rel.Contains("..") || rel.StartsWith("/") || rel.Contains(":"))
                        throw new InvalidOperationException("Caminho inválido no pacote: " + rel);
                    string target = Path.GetFullPath(Path.Combine(temp, rel.Replace('/', Path.DirectorySeparatorChar)));
                    if (!target.StartsWith(root, StringComparison.OrdinalIgnoreCase))
                        throw new InvalidOperationException("Destino fora da pasta de instalação.");
                    Directory.CreateDirectory(Path.GetDirectoryName(target));
                    using (Stream input = entry.Open())
                    using (var output = new FileStream(target, FileMode.Create, FileAccess.Write, FileShare.None))
                        input.CopyTo(output);
                }
            }

            var info = new PackageInfo { TempDir = temp };
            string configPath = Path.Combine(temp, "gestor-config.json");
            if (File.Exists(configPath))
            {
                string json = File.ReadAllText(configPath, Encoding.UTF8);
                string v;
                if (!string.IsNullOrWhiteSpace(v = JsonString(json, "system_name"))) info.SystemName = v;
                if (!string.IsNullOrWhiteSpace(v = JsonString(json, "system_description"))) info.SystemDescription = v;
                info.Origin = JsonString(json, "origin") ?? "";
            }
            string versionPath = Path.Combine(temp, "VERSION");
            if (File.Exists(versionPath)) info.Version = File.ReadAllText(versionPath, Encoding.UTF8).Trim();
            string iconPath = Path.Combine(temp, "favicon.ico");
            if (File.Exists(iconPath)) info.IconBytes = File.ReadAllBytes(iconPath);
            return info;
        }

        internal static string SelfPath()
        {
            string path = Assembly.GetExecutingAssembly().Location;
            if (string.IsNullOrEmpty(path) || !File.Exists(path)) path = Process.GetCurrentProcess().MainModule.FileName;
            return path;
        }

        private static void ReadFully(Stream s, byte[] buffer, int count)
        {
            int read = 0;
            while (read < count)
            {
                int n = s.Read(buffer, read, count - read);
                if (n <= 0) throw new EndOfStreamException();
                read += n;
            }
        }

        private static string JsonString(string json, string key)
        {
            Match m = Regex.Match(json, "\"" + Regex.Escape(key) + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"");
            if (!m.Success) return null;
            try { return Regex.Unescape(m.Groups[1].Value); } catch { return m.Groups[1].Value; }
        }

        public static void CleanTemp(PackageInfo pkg)
        {
            try { if (pkg != null && pkg.TempDir != null && Directory.Exists(pkg.TempDir)) Directory.Delete(pkg.TempDir, true); } catch { }
        }

        // ---------- instalação ----------
        public void Run(ProgressReport report)
        {
            string source = pkg.TempDir;
            string destination = Program.Destination;
            string targetRoot = Path.GetFullPath(destination).TrimEnd(Path.DirectorySeparatorChar, '\\') + Path.DirectorySeparatorChar;

            report(4, "Validando arquivos do gestor...");
            foreach (string item in Required)
                if (!File.Exists(Path.Combine(source, item)))
                    throw new InvalidOperationException("Arquivo obrigatório ausente no pacote: " + item + ". Baixe novamente pelo painel da rede.");
            if (!Regex.IsMatch(pkg.Origin, "^https://[^/]+"))
                throw new InvalidOperationException("Endereço HTTPS inválido no pacote. Baixe novamente pelo painel da rede.");

            report(10, "Encerrando a versão anterior de " + pkg.SystemName + "...");
            StopPreviousGestor(targetRoot);

            report(16, "Copiando arquivos do gestor...");
            Directory.CreateDirectory(destination);
            string sourceRoot = Path.GetFullPath(source).TrimEnd(Path.DirectorySeparatorChar, '\\') + Path.DirectorySeparatorChar;
            var files = new List<string>();
            foreach (string full in Directory.GetFiles(source, "*", SearchOption.AllDirectories))
            {
                if (Path.GetFileName(full) == ".arcoll-installer-marker") continue;
                if ((File.GetAttributes(full) & FileAttributes.ReparsePoint) != 0) continue;
                files.Add(full);
            }
            var incoming = new List<string>();
            int index = 0;
            foreach (string full in files)
            {
                string rel = full.Substring(sourceRoot.Length).Replace(Path.DirectorySeparatorChar, '/').Replace('\\', '/');
                if (!SafePath.IsMatch(rel) || rel.Contains("..") || rel.Contains(":")) throw new InvalidOperationException("Caminho inválido no pacote.");
                string target = Path.GetFullPath(Path.Combine(destination, rel.Replace('/', Path.DirectorySeparatorChar)));
                if (!target.StartsWith(targetRoot, StringComparison.OrdinalIgnoreCase)) throw new InvalidOperationException("Destino fora da instalação.");
                Directory.CreateDirectory(Path.GetDirectoryName(target));
                CopyWithRetry(full, target);
                incoming.Add(rel);
                index++;
                report(16 + (int)(20.0 * index / Math.Max(1, files.Count)), "Copiando arquivos do gestor (" + index + "/" + files.Count + ")...");
            }

            report(37, "Removendo apenas componentes obsoletos...");
            RemoveObsolete(destination, targetRoot, incoming);

            report(39, "Configurando o iniciador do aplicativo...");
            string launcher = "@rem Caminho: gestores/windows/INICIAR_ARCOLL.bat\r\n@echo off\r\nsetlocal\r\ncd /d \"%~dp0\"\r\n" +
                "powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File \"%~dp0Instalar_Arcoll.ps1\" -Run %*\r\n";
            File.WriteAllText(Path.Combine(destination, "INICIAR_ARCOLL.bat"), launcher, Encoding.ASCII);

            report(42, "Instalando Python e dependências, se necessário...");
            RunConfigurationScript(destination, report);

            report(97, "Registrando o aplicativo no Windows...");
            try { RegisterUninstall(destination); } catch (Exception ex) { Log.Write("Registro de desinstalação: " + ex.Message); }

            report(100, "Instalação concluída. O gestor foi iniciado.");
        }

        private static void StopPreviousGestor(string targetRoot)
        {
            int self = Process.GetCurrentProcess().Id;
            var pids = new List<int>();
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT ProcessId, Name, CommandLine FROM Win32_Process"))
                using (ManagementObjectCollection results = searcher.Get())
                {
                    foreach (ManagementObject mo in results)
                    {
                        try
                        {
                            string name = Convert.ToString(mo["Name"]) ?? "";
                            string cmd = Convert.ToString(mo["CommandLine"]) ?? "";
                            int pid = Convert.ToInt32(mo["ProcessId"]);
                            if (pid == self || cmd.Length == 0) continue;
                            if (!Regex.IsMatch(name, "^pythonw?\\.exe$", RegexOptions.IgnoreCase)) continue;
                            if (!Regex.IsMatch(cmd, "(arcoll_desktop|arcoll_app|arcoll_gestor)\\.py", RegexOptions.IgnoreCase)) continue;
                            if (cmd.IndexOf(targetRoot, StringComparison.OrdinalIgnoreCase) < 0) continue;
                            pids.Add(pid);
                        }
                        finally { mo.Dispose(); }
                    }
                }
            }
            catch (Exception ex) { Log.Write("Consulta de processos: " + ex.Message); }

            foreach (int pid in pids)
            {
                try
                {
                    using (Process p = Process.GetProcessById(pid)) { p.Kill(); p.WaitForExit(5000); }
                }
                catch (ArgumentException) { /* já encerrou */ }
                catch (Exception)
                {
                    throw new InvalidOperationException("Não foi possível encerrar o gestor anterior (PID " + pid + "). Feche-o e tente novamente.");
                }
            }
            if (pids.Count > 0) Thread.Sleep(450);
        }

        private static void CopyWithRetry(string from, string to)
        {
            Exception last = null;
            for (int i = 0; i < 8; i++)
            {
                try
                {
                    if (File.Exists(to)) File.SetAttributes(to, FileAttributes.Normal);
                    File.Copy(from, to, true);
                    return;
                }
                catch (Exception ex) { last = ex; Thread.Sleep(300); }
            }
            throw new IOException("Não foi possível gravar " + Path.GetFileName(to) + " (arquivo em uso ou bloqueado pelo antivírus): " + (last != null ? last.Message : ""));
        }

        private static void RemoveObsolete(string destination, string targetRoot, List<string> incoming)
        {
            string manifestPath = Path.Combine(destination, ".arcoll-managed-files.json");
            var keep = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
                { "gestor-config.json", "favicon.ico", "INICIAR_ARCOLL.bat", "Instalar_Arcoll.ps1", "Preparar_Instalacao.ps1" };
            if (File.Exists(manifestPath))
            {
                try
                {
                    string text = File.ReadAllText(manifestPath, Encoding.UTF8);
                    foreach (Match m in Regex.Matches(text, "\"((?:[^\"\\\\]|\\\\.)*)\""))
                    {
                        string old = Regex.Unescape(m.Groups[1].Value);
                        if (!SafePath.IsMatch(old) || old.Contains("..") || old.Contains(":")) continue;
                        if (keep.Contains(old) || incoming.Contains(old)) continue;
                        string path = Path.GetFullPath(Path.Combine(destination, old.Replace('/', Path.DirectorySeparatorChar)));
                        if (path.StartsWith(targetRoot, StringComparison.OrdinalIgnoreCase) && File.Exists(path))
                            File.Delete(path);
                    }
                }
                catch (Exception ex) { Log.Write("Limpeza de obsoletos: " + ex.Message); }
            }
            var sb = new StringBuilder("[\r\n");
            for (int i = 0; i < incoming.Count; i++)
                sb.Append("    \"").Append(incoming[i].Replace("\\", "\\\\").Replace("\"", "\\\"")).Append('"').Append(i + 1 < incoming.Count ? ",\r\n" : "\r\n");
            sb.Append("]");
            File.WriteAllText(manifestPath, sb.ToString(), new UTF8Encoding(true));
        }

        private static string PowerShellPath()
        {
            string win = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
            string native = Path.Combine(win, @"System32\WindowsPowerShell\v1.0\powershell.exe");
            if (!Environment.Is64BitProcess && Environment.Is64BitOperatingSystem)
            {
                string sysnative = Path.Combine(win, @"Sysnative\WindowsPowerShell\v1.0\powershell.exe");
                if (File.Exists(sysnative)) return sysnative;
            }
            return native;
        }

        private void RunConfigurationScript(string destination, ProgressReport report)
        {
            string script = Path.Combine(destination, "Instalar_Arcoll.ps1");
            string installLog = Path.Combine(Log.Directory_, "gestor-install.log");
            long logStart = 0;
            try { if (File.Exists(installLog)) logStart = new FileInfo(installLog).Length; } catch { }

            var psi = new ProcessStartInfo(PowerShellPath(),
                "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File \"" + script + "\"")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = destination
            };
            psi.EnvironmentVariables["ARCOLL_ORIGIN"] = pkg.Origin;

            using (Process proc = Process.Start(psi))
            {
                int position = 42;
                string message = "Configurando o gestor e os atalhos do Windows...";
                int tick = 0;
                while (!proc.WaitForExit(500))
                {
                    tick++;
                    string line = LastLogLine(installLog, logStart);
                    int mapped; string friendly = MapLog(line, out mapped);
                    if (friendly != null) { message = friendly; if (mapped > position) position = mapped; }
                    // Não simular progresso: somente etapas efetivamente confirmadas pelo instalador.
                    report(Math.Min(96, position), message);
                }
                if (proc.ExitCode != 0)
                    throw new InvalidOperationException("O gestor não foi instalado (código " + proc.ExitCode + ").");
            }
        }

        private static string LastLogLine(string path, long from)
        {
            try
            {
                using (var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
                {
                    if (fs.Length <= from) return "";
                    fs.Seek(from, SeekOrigin.Begin);
                    string text = new StreamReader(fs, Encoding.UTF8).ReadToEnd();
                    string[] lines = text.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries);
                    return lines.Length > 0 ? lines[lines.Length - 1] : "";
                }
            }
            catch { return ""; }
        }

        private static string MapLog(string line, out int percent)
        {
            percent = 0;
            if (string.IsNullOrEmpty(line)) return null;
            string l = line.ToLowerInvariant();
            if (l.Contains("baixando python") || l.Contains("instalação silenciosa do runtime")) { percent = 50; return "Baixando e instalando o Python (pode levar alguns minutos)..."; }
            if (l.Contains("python setup")) { percent = 58; return "Instalando o Python..."; }
            if (l.Contains("python encontrado") || l.Contains("python pronto")) { percent = 64; return "Python pronto. Instalando dependências..."; }
            if (l.Contains("atalho criado")) { percent = 80; return "Criando atalhos do Windows..."; }
            if (l.Contains("inicialização automática")) { percent = 84; return "Configurando a inicialização automática..."; }
            if (l.Contains("tela segura") || l.Contains("securedesktop")) { percent = 90; return "Configurando o acesso remoto à tela de bloqueio..."; }
            if (l.Contains("núcleo pré-login")) { percent = 93; return "Configurando o núcleo do gestor..."; }
            if (l.Contains("interface iniciada")) { percent = 96; return "Iniciando o gestor..."; }
            return null;
        }

        private void RegisterUninstall(string destination)
        {
            string script = Path.Combine(destination, "Desinstalar_Arcoll.ps1");
            if (!File.Exists(script)) return;
            using (RegistryKey key = Registry.LocalMachine.CreateSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\ArcollGestor"))
            {
                if (key == null) return;
                key.SetValue("DisplayName", pkg.SystemName + " - Gestor de Rede");
                if (pkg.Version.Length > 0) key.SetValue("DisplayVersion", pkg.Version);
                key.SetValue("Publisher", pkg.SystemName);
                key.SetValue("InstallLocation", destination);
                key.SetValue("InstallDate", DateTime.Now.ToString("yyyyMMdd"));
                string icon = Path.Combine(destination, "favicon.ico");
                if (File.Exists(icon)) key.SetValue("DisplayIcon", icon);
                key.SetValue("UninstallString", "\"" + PowerShellPath() + "\" -NoLogo -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File \"" + script + "\"");
                key.SetValue("NoModify", 1, RegistryValueKind.DWord);
                key.SetValue("NoRepair", 1, RegistryValueKind.DWord);
            }
        }
    }

    // ---------- interface ----------
    internal sealed class SetupForm : Form
    {
        private static readonly Color Back = Color.FromArgb(11, 20, 35);
        private static readonly Color Accent = Color.FromArgb(74, 194, 240);
        private static readonly Color Muted = Color.FromArgb(165, 187, 210);

        private readonly PackageInfo pkg;
        private readonly Label title, subtitle, status, percentLabel, footer;
        private readonly Panel bar;
        private readonly Button closeButton, logButton;
        private Bitmap logo;
        private int percent;
        private volatile bool running = true;
        public bool Succeeded { get; private set; }

        public SetupForm(PackageInfo pkg)
        {
            this.pkg = pkg;
            SetStyle(ControlStyles.OptimizedDoubleBuffer | ControlStyles.AllPaintingInWmPaint | ControlStyles.UserPaint, true);
            Text = pkg.SystemName + " | Instalação do Gestor";
            ClientSize = new Size(680, 520);
            FormBorderStyle = FormBorderStyle.FixedSingle;
            MaximizeBox = false;
            MinimizeBox = true;
            StartPosition = FormStartPosition.CenterScreen;
            BackColor = Back;
            ForeColor = Color.White;
            Font = new Font("Segoe UI", 10f);

            try
            {
                if (pkg.IconBytes != null)
                    using (var ico = new Icon(new MemoryStream(pkg.IconBytes)))
                    {
                        Icon = (Icon)ico.Clone();
                        logo = new Bitmap(ico.ToBitmap(), new Size(48, 48));
                    }
            }
            catch { logo = null; }

            int left = logo != null ? 100 : 40;
            var brand = new Label
            {
                Text = pkg.SystemName.ToUpperInvariant(),
                ForeColor = Accent,
                Font = new Font("Segoe UI Semibold", 11f),
                BackColor = Color.Transparent,
                AutoEllipsis = true,
                Bounds = new Rectangle(left, 22, 500, 28)
            };
            var brandSub = new Label
            {
                Text = "Instalador do Gestor Windows",
                ForeColor = Muted,
                Font = new Font("Segoe UI", 9f),
                BackColor = Color.Transparent,
                Bounds = new Rectangle(left, 48, 500, 22)
            };
            title = new Label
            {
                Text = "Instalando o Gestor Windows",
                Font = new Font("Segoe UI Semibold", 21f),
                BackColor = Color.Transparent,
                AutoEllipsis = true,
                Bounds = new Rectangle(38, 81, 605, 44)
            };
            subtitle = new Label
            {
                Text = pkg.SystemDescription,
                ForeColor = Muted,
                BackColor = Color.Transparent,
                AutoEllipsis = true,
                Bounds = new Rectangle(40, 123, 600, 24)
            };
            status = new Label
            {
                Text = "Preparando instalação...",
                ForeColor = Color.FromArgb(216, 232, 244),
                BackColor = Color.Transparent,
                AutoEllipsis = true,
                Bounds = new Rectangle(40, 404, 600, 28)
            };
            bar = new Panel { Bounds = new Rectangle(145, 154, 390, 244), BackColor = Color.Transparent };
            bar.Paint += PaintBar;
            percentLabel = new Label
            {
                Text = "0%",
                ForeColor = Accent,
                BackColor = Color.Transparent,
                TextAlign = ContentAlignment.MiddleRight,
                Bounds = new Rectangle(560, 405, 80, 24), Visible = false
            };
            footer = new Label
            {
                Text = (pkg.Version.Length > 0 ? "Versão " + pkg.Version + "  ·  " : "") + "Destino: " + Program.Destination,
                ForeColor = Color.FromArgb(110, 134, 160),
                Font = new Font("Segoe UI", 8.5f),
                BackColor = Color.Transparent,
                Bounds = new Rectangle(40, 484, 560, 20)
            };
            closeButton = MakeButton("Fechar", new Rectangle(500, 441, 140, 36), true);
            closeButton.Click += delegate { Close(); };
            logButton = MakeButton("Ver registro", new Rectangle(350, 441, 140, 36), false);
            logButton.Click += delegate
            {
                try { Process.Start("notepad.exe", "\"" + Log.FilePath + "\""); } catch { }
            };
            closeButton.Visible = false;
            logButton.Visible = false;

            var stages = new Label
            {
                Text = "VERIFICANDO   •   RECEBENDO   •   VALIDANDO   •   PREPARANDO   •   REINICIANDO",
                Font = new Font("Segoe UI Semibold", 8.5f),
                ForeColor = Muted,
                BackColor = Color.Transparent,
                Bounds = new Rectangle(40, 375, 600, 22),
                AutoEllipsis = true
            };
            Controls.AddRange(new Control[] { brand, brandSub, title, subtitle, status, bar, percentLabel, footer, stages, logButton, closeButton });

            FormClosing += delegate (object s, FormClosingEventArgs e) { if (running) e.Cancel = true; };
            Shown += delegate { var t = new Thread(Work) { IsBackground = true, Name = "setup" }; t.Start(); };
        }

        private Button MakeButton(string text, Rectangle bounds, bool primary)
        {
            var b = new Button
            {
                Text = text,
                Bounds = bounds,
                FlatStyle = FlatStyle.Flat,
                ForeColor = Color.White,
                BackColor = primary ? Color.FromArgb(13, 120, 173) : Color.FromArgb(30, 45, 66),
                Cursor = Cursors.Hand,
                Font = new Font("Segoe UI Semibold", 10f)
            };
            b.FlatAppearance.BorderSize = 0;
            return b;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            using (var brush = new LinearGradientBrush(new Rectangle(0, 0, Width, 110), Color.FromArgb(18, 38, 64), Back, 90f))
                g.FillRectangle(brush, 0, 0, ClientSize.Width, 110);
            using (var pen = new Pen(Color.FromArgb(40, 85, 212, 246)))
                g.DrawLine(pen, 0, 110, ClientSize.Width, 110);
            if (logo != null) g.DrawImage(logo, 38, 17, 48, 48);
            base.OnPaint(e);
        }

        // Anel circular do instalador, integrado à janela principal, sem console adicional.
        private void PaintBar(object sender, PaintEventArgs e)
        {
            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.TextRenderingHint = TextRenderingHint.ClearTypeGridFit;
            var ring = new Rectangle(104, 17, 181, 181);
            using (var background = new Pen(Color.FromArgb(53, 79, 104), 7f))
            using (var active = new Pen(Accent, 8f))
            {
                background.StartCap = background.EndCap = LineCap.Round;
                active.StartCap = active.EndCap = LineCap.Round;
                g.DrawArc(background, ring, -90, 359.9f);
                if (percent > 0) g.DrawArc(active, ring, -90, Math.Min(359.9f, percent * 3.599f));
            }
            using (var font = new Font("Segoe UI Semibold", 37f, FontStyle.Bold))
            using (var white = new SolidBrush(Color.FromArgb(236, 246, 255)))
            using (var small = new Font("Segoe UI", 8.5f))
            using (var muted = new SolidBrush(Muted))
            {
                var number = percent + "%";
                var size = g.MeasureString(number, font);
                g.DrawString(number, font, white, 194.5f - size.Width / 2f, 74f);
                var tag = "Instalação por etapas";
                var labelSize = g.MeasureString(tag, small);
                g.DrawString(tag, small, muted, 194.5f - labelSize.Width / 2f, 139f);
            }
            string[] labels = { "Verificando", "Recebendo", "Validando", "Preparando", "Reiniciando" };
            var positions = new[] { new PointF(188, 2), new PointF(338, 62), new PointF(341, 189), new PointF(186, 225), new PointF(43, 189) };
            int stage = percent < 16 ? 0 : percent < 37 ? 1 : percent < 42 ? 2 : percent < 97 ? 3 : 4;
            using (var font = new Font("Segoe UI", 8f))
            for (int i = 0; i < labels.Length; i++)
            {
                var width = g.MeasureString(labels[i], font).Width + 14f;
                var r = new RectangleF(positions[i].X - width / 2, positions[i].Y, width, 21);
                using (var fill = new SolidBrush(i == stage ? Color.FromArgb(14, 87, 123) : Color.FromArgb(15, 38, 62)))
                using (var border = new Pen(i == stage ? Accent : Color.FromArgb(55, 91, 126)))
                using (var ink = new SolidBrush(i == stage ? Color.White : Muted))
                using (var pill = Round(Rectangle.Round(r), 9))
                {
                    g.FillPath(fill, pill); g.DrawPath(border, pill);
                    g.DrawString(labels[i], font, ink, r.Left + 7, r.Top + 3);
                }
            }
        }

        private static GraphicsPath Round(Rectangle r, int radius)
        {
            int d = radius * 2;
            var p = new GraphicsPath();
            p.AddArc(r.X, r.Y, d, d, 180, 90);
            p.AddArc(r.Right - d, r.Y, d, d, 270, 90);
            p.AddArc(r.Right - d, r.Bottom - d, d, d, 0, 90);
            p.AddArc(r.X, r.Bottom - d, d, d, 90, 90);
            p.CloseFigure();
            return p;
        }

        private void Report(int value, string message)
        {
            Log.Write("[" + value + "] " + message);
            try
            {
                BeginInvoke((Action)delegate
                {
                    if (value > percent) percent = Math.Min(100, value);
                    status.Text = message;
                    percentLabel.Text = percent + "%";
                    bar.Invalidate();
                });
            }
            catch (InvalidOperationException) { }
        }

        private void Work()
        {
            try
            {
                new Installer(pkg).Run(Report);
                Succeeded = true;
                BeginInvoke((Action)delegate
                {
                    title.Text = "Instalação concluída";
                    subtitle.Text = pkg.SystemName + " foi instalado com sucesso.";
                    running = false;
                    var t = new System.Windows.Forms.Timer { Interval = 1800 };
                    t.Tick += delegate { t.Stop(); Close(); };
                    t.Start();
                });
            }
            catch (Exception ex)
            {
                Log.Write("ERRO: " + ex);
                try
                {
                    BeginInvoke((Action)delegate
                    {
                        running = false;
                        title.Text = "Não foi possível concluir";
                        status.ForeColor = Color.FromArgb(255, 139, 139);
                        status.Text = "Falha na instalação: " + ex.Message;
                        status.AutoEllipsis = false;
                        status.Height = 56;
                        closeButton.Visible = true;
                        logButton.Visible = true;
                        MessageBox.Show(this, ex.Message + "\r\n\r\nRegistro: " + Log.FilePath, pkg.SystemName + " - Falha na instalação",
                            MessageBoxButtons.OK, MessageBoxIcon.Error);
                    });
                }
                catch (InvalidOperationException) { }
            }
        }
    }
}
