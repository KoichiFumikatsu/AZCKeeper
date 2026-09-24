# Validación local de las variantes antislop

Fecha: 16 de septiembre de 2026.

**151 comprobaciones aprobadas; 0 fallos.**

16 HTML ejecutados en JSDOM. Los recursos HTML y CSS son locales. Los 20 archivos originales de neomorfismo/minimal-lineas conservan su SHA-256. Solo se escriben las variantes nuevas y herramientas/documentación bajo la carpeta autorizada.

## Evidencia y límites

Cada línea siguiente registra una acción ejecutada por el validador DOM y su resultado. Los diálogos están emulados; no certifica confinamiento nativo del foco, Tab/Shift+Tab, Escape nativo, tamaños renderizados, reflow o zoom. Los enlaces se resuelven a archivos existentes; JSDOM no navega entre documentos. La redirección de login se inspecciona en su handler, no se afirma haberla recorrido en navegador.

El CSS define estado estrecho, intermedio desde 44em y amplio desde 70em. Esta inspección del código no equivale a medir el layout a 390/1440 px. El navegador real se intenta por separado en antislop-browser.cjs; el resultado está en VALIDACION-ANTISLOP-NAVEGADOR.md. No se considera aprobado el Delivery Gate visual mientras ese recorrido falte.

## Contraste calculado con luminancia sRGB

| Variante | Uso | Primer plano | Fondo | Relación | Mínimo |
|---|---|---|---|---|---|
| neomorfismo-antislop | Texto | #303030 | #E4E4E4 | 10.38:1 | 4.5:1 |
| neomorfismo-antislop | Secundario | #535353 | #E4E4E4 | 6.05:1 | 4.5:1 |
| neomorfismo-antislop | Secundario hover | #535353 | #D9D9D9 | 5.45:1 | 4.5:1 |
| neomorfismo-antislop | Acento | #245D68 | #E4E4E4 | 5.81:1 | 4.5:1 |
| neomorfismo-antislop | Acento hover | #245D68 | #D9D9D9 | 5.24:1 | 4.5:1 |
| neomorfismo-antislop | Botón | #F8F8F8 | #245D68 | 6.96:1 | 4.5:1 |
| neomorfismo-antislop | Borde de control | #686868 | #E4E4E4 | 4.38:1 | 3:1 |
| neomorfismo-antislop | Borde en hover | #686868 | #D9D9D9 | 3.95:1 | 3:1 |
| neomorfismo-antislop | Foco | #303030 | #E4E4E4 | 10.38:1 | 3:1 |
| minimal-lineas-antislop | Texto | #303030 | #F8F8F8 | 12.43:1 | 4.5:1 |
| minimal-lineas-antislop | Secundario | #535353 | #F8F8F8 | 7.24:1 | 4.5:1 |
| minimal-lineas-antislop | Secundario hover | #535353 | #EEEEEE | 6.63:1 | 4.5:1 |
| minimal-lineas-antislop | Acento | #416A60 | #F8F8F8 | 5.72:1 | 4.5:1 |
| minimal-lineas-antislop | Acento hover | #416A60 | #EEEEEE | 5.24:1 | 4.5:1 |
| minimal-lineas-antislop | Botón | #F8F8F8 | #416A60 | 5.72:1 | 4.5:1 |
| minimal-lineas-antislop | Borde de control | #686868 | #F8F8F8 | 5.25:1 | 3:1 |
| minimal-lineas-antislop | Borde en hover | #686868 | #EEEEEE | 4.80:1 | 3:1 |
| minimal-lineas-antislop | Foco | #303030 | #F8F8F8 | 12.43:1 | 3:1 |

El escudo #9A9A9A queda en la marca. Las líneas de separación #B5B5B5 son ornamentales, no límites de controles. Los límites interactivos son #686868, foco #303030 y estados con palabra. Relieve solo en contenedor de trabajo/diálogo y campo enfocado; ninguna sombra contiene texto superpuesto.

## Recorrido ejecutado en DOM

- PASS: neomorfismo-antislop / ocho páginas exactas
- PASS: neomorfismo-antislop/login / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/login / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/login / nombres y muestra explícita
- PASS: neomorfismo-antislop/login / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/index / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/index / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/index / nombres y muestra explícita
- PASS: neomorfismo-antislop/index / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/index / escenario empty
- PASS: neomorfismo-antislop/index / escenario loading
- PASS: neomorfismo-antislop/index / escenario error
- PASS: neomorfismo-antislop/devices / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/devices / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/devices / nombres y muestra explícita
- PASS: neomorfismo-antislop/devices / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/devices / escenario empty
- PASS: neomorfismo-antislop/devices / escenario loading
- PASS: neomorfismo-antislop/devices / escenario error
- PASS: neomorfismo-antislop/device / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/device / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/device / nombres y muestra explícita
- PASS: neomorfismo-antislop/device / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/device / escenario empty
- PASS: neomorfismo-antislop/device / escenario loading
- PASS: neomorfismo-antislop/device / escenario error
- PASS: neomorfismo-antislop/policies / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/policies / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/policies / nombres y muestra explícita
- PASS: neomorfismo-antislop/policies / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/policies / escenario empty
- PASS: neomorfismo-antislop/policies / escenario loading
- PASS: neomorfismo-antislop/policies / escenario error
- PASS: neomorfismo-antislop/users / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/users / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/users / nombres y muestra explícita
- PASS: neomorfismo-antislop/users / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/users / escenario empty
- PASS: neomorfismo-antislop/users / escenario loading
- PASS: neomorfismo-antislop/users / escenario error
- PASS: neomorfismo-antislop/settings / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/settings / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/settings / nombres y muestra explícita
- PASS: neomorfismo-antislop/settings / sin red ni fuentes remotas
- PASS: neomorfismo-antislop/settings / escenario empty
- PASS: neomorfismo-antislop/settings / escenario loading
- PASS: neomorfismo-antislop/settings / escenario error
- PASS: neomorfismo-antislop/states / sintaxis y DOM sin errores
- PASS: neomorfismo-antislop/states / navegación y recursos relativos existentes
- PASS: neomorfismo-antislop/states / nombres y muestra explícita
- PASS: neomorfismo-antislop/states / sin red ni fuentes remotas
- PASS: neomorfismo-antislop / tenant y flota: 180, 56 y empresa vacía
- PASS: neomorfismo-antislop / buscar, limpiar, paginar, conexión y versión
- PASS: neomorfismo-antislop / comando Bloquear: revisión, destino y cola
- PASS: neomorfismo-antislop / comando Apagar: revisión, destino y cola
- PASS: neomorfismo-antislop / comando Borrar: revisión, destino y cola
- PASS: neomorfismo-antislop / cancelar no agrega solicitudes
- PASS: neomorfismo-antislop / política: ámbitos, dominios, días, horario y confirmación
- PASS: neomorfismo-antislop / personas: filtros, páginas, rol e invitación
- PASS: neomorfismo-antislop / identidad: selección de empresa, aplicar, contraste y restaurar
- PASS: neomorfismo-antislop / claves: vacío, creación y revocación confirmada
- PASS: neomorfismo-antislop / acceso: validación, contraseña, ayuda y error explícito
- PASS: neomorfismo-antislop / menú móvil: Escape retorna al control
- PASS: neomorfismo-antislop / restaurar foco tras reemplazar filas y cerrar aviso
- PASS: neomorfismo-antislop / comandos locales separados al cambiar empresa
- PASS: neomorfismo-antislop / CSS: tres estados, foco, 44 px, reducción y colores forzados
- PASS: neomorfismo-antislop / contraste Texto
- PASS: neomorfismo-antislop / contraste Secundario
- PASS: neomorfismo-antislop / contraste Secundario hover
- PASS: neomorfismo-antislop / contraste Acento
- PASS: neomorfismo-antislop / contraste Acento hover
- PASS: neomorfismo-antislop / contraste Botón
- PASS: neomorfismo-antislop / contraste Borde de control
- PASS: neomorfismo-antislop / contraste Borde en hover
- PASS: neomorfismo-antislop / contraste Foco
- PASS: minimal-lineas-antislop / ocho páginas exactas
- PASS: minimal-lineas-antislop/login / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/login / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/login / nombres y muestra explícita
- PASS: minimal-lineas-antislop/login / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/index / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/index / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/index / nombres y muestra explícita
- PASS: minimal-lineas-antislop/index / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/index / escenario empty
- PASS: minimal-lineas-antislop/index / escenario loading
- PASS: minimal-lineas-antislop/index / escenario error
- PASS: minimal-lineas-antislop/devices / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/devices / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/devices / nombres y muestra explícita
- PASS: minimal-lineas-antislop/devices / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/devices / escenario empty
- PASS: minimal-lineas-antislop/devices / escenario loading
- PASS: minimal-lineas-antislop/devices / escenario error
- PASS: minimal-lineas-antislop/device / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/device / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/device / nombres y muestra explícita
- PASS: minimal-lineas-antislop/device / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/device / escenario empty
- PASS: minimal-lineas-antislop/device / escenario loading
- PASS: minimal-lineas-antislop/device / escenario error
- PASS: minimal-lineas-antislop/policies / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/policies / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/policies / nombres y muestra explícita
- PASS: minimal-lineas-antislop/policies / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/policies / escenario empty
- PASS: minimal-lineas-antislop/policies / escenario loading
- PASS: minimal-lineas-antislop/policies / escenario error
- PASS: minimal-lineas-antislop/users / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/users / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/users / nombres y muestra explícita
- PASS: minimal-lineas-antislop/users / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/users / escenario empty
- PASS: minimal-lineas-antislop/users / escenario loading
- PASS: minimal-lineas-antislop/users / escenario error
- PASS: minimal-lineas-antislop/settings / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/settings / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/settings / nombres y muestra explícita
- PASS: minimal-lineas-antislop/settings / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop/settings / escenario empty
- PASS: minimal-lineas-antislop/settings / escenario loading
- PASS: minimal-lineas-antislop/settings / escenario error
- PASS: minimal-lineas-antislop/states / sintaxis y DOM sin errores
- PASS: minimal-lineas-antislop/states / navegación y recursos relativos existentes
- PASS: minimal-lineas-antislop/states / nombres y muestra explícita
- PASS: minimal-lineas-antislop/states / sin red ni fuentes remotas
- PASS: minimal-lineas-antislop / tenant y flota: 180, 56 y empresa vacía
- PASS: minimal-lineas-antislop / buscar, limpiar, paginar, conexión y versión
- PASS: minimal-lineas-antislop / comando Bloquear: revisión, destino y cola
- PASS: minimal-lineas-antislop / comando Apagar: revisión, destino y cola
- PASS: minimal-lineas-antislop / comando Borrar: revisión, destino y cola
- PASS: minimal-lineas-antislop / cancelar no agrega solicitudes
- PASS: minimal-lineas-antislop / política: ámbitos, dominios, días, horario y confirmación
- PASS: minimal-lineas-antislop / personas: filtros, páginas, rol e invitación
- PASS: minimal-lineas-antislop / identidad: selección de empresa, aplicar, contraste y restaurar
- PASS: minimal-lineas-antislop / claves: vacío, creación y revocación confirmada
- PASS: minimal-lineas-antislop / acceso: validación, contraseña, ayuda y error explícito
- PASS: minimal-lineas-antislop / menú móvil: Escape retorna al control
- PASS: minimal-lineas-antislop / restaurar foco tras reemplazar filas y cerrar aviso
- PASS: minimal-lineas-antislop / comandos locales separados al cambiar empresa
- PASS: minimal-lineas-antislop / CSS: tres estados, foco, 44 px, reducción y colores forzados
- PASS: minimal-lineas-antislop / contraste Texto
- PASS: minimal-lineas-antislop / contraste Secundario
- PASS: minimal-lineas-antislop / contraste Secundario hover
- PASS: minimal-lineas-antislop / contraste Acento
- PASS: minimal-lineas-antislop / contraste Acento hover
- PASS: minimal-lineas-antislop / contraste Botón
- PASS: minimal-lineas-antislop / contraste Borde de control
- PASS: minimal-lineas-antislop / contraste Borde en hover
- PASS: minimal-lineas-antislop / contraste Foco
- PASS: Originales neomorfismo y minimal-lineas sin cambios (SHA-256)

