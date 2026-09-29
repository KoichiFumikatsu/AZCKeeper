using System.IO.Pipes;
using System.Runtime.InteropServices;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Ipc;

// Modo identificacion: una ventana pide la cedula al usuario y la envia al agente por el canal que este creo.
public static class IdentifyPrompt
{
    public static async Task RunAsync(string pipeName, int agentPid, Control dispatcher, CancellationToken ct)
    {
        using var pipe = new NamedPipeClientStream(".", pipeName, PipeDirection.InOut, PipeOptions.Asynchronous,
            System.Security.Principal.TokenImpersonationLevel.Identification);
        await pipe.ConnectAsync(15000, ct);
        if (!GetNamedPipeServerProcessId(pipe.SafePipeHandle, out var pid) || pid != agentPid)
            throw new UnauthorizedAccessException("unexpected_agent_process");
        var answer = new TaskCompletionSource<string?>(TaskCreationOptions.RunContinuationsAsynchronously);
        dispatcher.BeginInvoke(() => answer.TrySetResult(Ask()));
        var document = await answer.Task;
        await SessionProtocol.WriteAsync(pipe, new IdentifyAnswer(1, document), ct);
    }

    private static string? Ask()
    {
        using var form = new Form
        {
            Text = "AZCKeeper", FormBorderStyle = FormBorderStyle.FixedDialog, MaximizeBox = false, MinimizeBox = false, TopMost = true,
            StartPosition = FormStartPosition.CenterScreen, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Padding = new Padding(24)
        };
        var panel = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, AutoSize = true, WrapContents = false, Dock = DockStyle.Fill };
        panel.Controls.Add(new Label { Text = "¿A quién pertenece este equipo?", AutoSize = true, Font = new Font("Segoe UI", 14, FontStyle.Bold) });
        panel.Controls.Add(new Label
        {
            Text = "Tu empresa instaló AZCKeeper. Escribe tu número de cédula para asignarte este equipo.\nIT lo confirmará desde el panel.",
            AutoSize = true, MaximumSize = new Size(440, 0), Font = new Font("Segoe UI", 10), Margin = new Padding(0, 8, 0, 12)
        });
        var input = new TextBox { MaxLength = 20, Width = 260, Font = new Font("Segoe UI", 12) };
        var error = new Label { AutoSize = true, ForeColor = Color.FromArgb(160, 30, 30), Font = new Font("Segoe UI", 9), Text = "" };
        var buttons = new FlowLayoutPanel { FlowDirection = FlowDirection.LeftToRight, AutoSize = true, Margin = new Padding(0, 12, 0, 0) };
        var ok = new Button { Text = "Enviar", AutoSize = true };
        var later = new Button { Text = "Ahora no", AutoSize = true, DialogResult = DialogResult.Cancel };
        buttons.Controls.Add(ok); buttons.Controls.Add(later);
        panel.Controls.Add(input); panel.Controls.Add(error); panel.Controls.Add(buttons);
        form.Controls.Add(panel);
        form.AcceptButton = ok; form.CancelButton = later;
        string? result = null;
        ok.Click += (_, _) =>
        {
            result = IdentifyProtocol.Normalize(input.Text);
            if (result is null) { error.Text = "Escribe solo los números de tu cédula (entre 5 y 15)."; input.Focus(); return; }
            form.DialogResult = DialogResult.OK;
        };
        return form.ShowDialog() == DialogResult.OK ? result : null;
    }

    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeServerProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
