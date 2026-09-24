# Recorrido de navegador antislop

Fecha: 16 de septiembre de 2026.

**BLOQUEADO: browserType.launch: spawn EPERM**

Chromium no arrancó. Cero páginas renderizadas, cero capturas y cero interacciones verificadas en navegador. El Delivery Gate visual no pasa.

Comprobaciones realizadas: 0. Fallos durante el recorrido: 0.

Se ha preparado lectura de archivos file://, sin servidor. Anchuras previstas: 320, 390, 768, 1024 y 1440 px. Zoom tipográfico 200 %, recorrido de Tab, contraste según tokens, diálogos, navegación, filtros, políticas, personas, identidad e integraciones. La cobertura DOM se registra por separado y no reemplaza este recorrido.

## Evidencia ejecutada

No hay evidencia visual ejecutada.


## Capturas

No generadas.

## Repetir en un entorno que permita Chromium

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/antislop-browser.cjs
~~~

Solo después de completar y revisar el recorrido se pueden aprobar R-03, R-32 y R-35. Un resultado nuevo no actualiza automáticamente los README: el gate requiere revisión humana del informe y las capturas.
