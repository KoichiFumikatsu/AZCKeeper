using System.Runtime.Versioning;
using System.Windows.Forms;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// La UNICA ventana que el cliente muestra en su vida: el login de primer arranque. Aparece
/// solo cuando no hay identidad guardada (equipo nuevo). Recoge entorno + cedula + contrasena,
/// intenta el login y, segun la respuesta, deja entrar, avisa que el equipo esta en aprobacion
/// o que las credenciales fallaron. Tras enrolar, el token DPAPI persiste y esto no vuelve a
/// verse: el cliente es invisible de nuevo.
///
/// El login real lo hace un delegado que Program provee (construye un ApiClient contra el
/// entorno elegido). Asi la ventana no conoce HTTP y se puede razonar aparte.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class FirstRunLogin : Form
{
    private readonly (string Label, string BaseUrl)[] _envs;
    private readonly Func<string, string, string, Task<(bool Ok, string Status)>> _loginFn;

    private readonly ComboBox _env = new() { DropDownStyle = ComboBoxStyle.DropDownList };
    private readonly TextBox _cc = new();
    private readonly TextBox _pass = new() { UseSystemPasswordChar = true };
    private readonly Label _msg = new() { ForeColor = System.Drawing.Color.FromArgb(190, 22, 34), AutoSize = false };
    private readonly Button _submit = new() { Text = "Ingresar" };

    /// <summary>Resultado si el login fue exitoso (DialogResult.OK).</summary>
    public string ResultBaseUrl { get; private set; } = "";
    public string ResultCc { get; private set; } = "";
    public string ResultPassword { get; private set; } = "";

    public FirstRunLogin((string Label, string BaseUrl)[] envs,
        Func<string, string, string, Task<(bool Ok, string Status)>> loginFn)
    {
        _envs = envs;
        _loginFn = loginFn;
        BuildUi();
    }

    private void BuildUi()
    {
        Text = "AZCKeeper — Configuración inicial";
        FormBorderStyle = FormBorderStyle.FixedDialog;
        StartPosition = FormStartPosition.CenterScreen;
        MaximizeBox = false; MinimizeBox = false;
        ClientSize = new System.Drawing.Size(380, 260);
        Font = new System.Drawing.Font("Segoe UI", 9F);

        void AddLabel(string text, int y) => Controls.Add(new Label { Text = text, Left = 20, Top = y, Width = 340, Height = 18 });

        AddLabel("Entorno", 16);
        _env.SetBounds(20, 36, 340, 24);
        foreach (var e in _envs) _env.Items.Add(e.Label);
        if (_env.Items.Count > 0) _env.SelectedIndex = 0;
        Controls.Add(_env);

        AddLabel("Cédula", 68);
        _cc.SetBounds(20, 88, 340, 24);
        Controls.Add(_cc);

        AddLabel("Contraseña", 120);
        _pass.SetBounds(20, 140, 340, 24);
        Controls.Add(_pass);

        _msg.SetBounds(20, 172, 340, 34);
        Controls.Add(_msg);

        _submit.SetBounds(20, 214, 160, 30);
        _submit.Click += async (_, _) => await OnSubmitAsync();
        Controls.Add(_submit);

        var cancel = new Button { Text = "Cancelar", Left = 200, Top = 214, Width = 160, Height = 30 };
        cancel.Click += (_, _) => { DialogResult = DialogResult.Cancel; Close(); };
        Controls.Add(cancel);

        AcceptButton = _submit;
    }

    private async Task OnSubmitAsync()
    {
        var cc = _cc.Text.Trim();
        var pass = _pass.Text;
        if (_env.SelectedIndex < 0) { _msg.Text = "Elige un entorno."; return; }
        if (cc.Length == 0 || pass.Length == 0) { _msg.Text = "Escribe cédula y contraseña."; return; }

        var baseUrl = _envs[_env.SelectedIndex].BaseUrl;
        _submit.Enabled = false; _msg.Text = "Validando…";
        try
        {
            var (ok, status) = await _loginFn(baseUrl, cc, pass);
            if (ok)
            {
                ResultBaseUrl = baseUrl; ResultCc = cc; ResultPassword = pass;
                DialogResult = DialogResult.OK;
                Close();
                return;
            }
            _msg.Text = status == "pending"
                ? "Equipo en espera de aprobación. Pídele a IT que lo apruebe y reintenta."
                : "Cédula o contraseña incorrecta.";
        }
        catch (Exception ex) { _msg.Text = "No se pudo conectar: " + ex.Message; }
        finally { _submit.Enabled = true; }
    }
}
