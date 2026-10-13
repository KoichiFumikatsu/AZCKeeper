# Probador de la API del Portal

Página local que llama a cada ruta de `/portal/v1`, marca si funciona y muestra el pedido y la respuesta. Sirve para dos cosas: saber si la API está bien, y como ejemplo de cómo debe llamarla el Portal (Lovable).

## Abrirla
Doble clic en `probar-api-portal.bat` (o en el acceso del Escritorio). Arranca un servidor local en `http://127.0.0.1:8790` y abre el navegador. Se cierra con la ventana negra.

Requisito una sola vez: en Keeper → **Plataforma → Portal: direcciones autorizadas**, agregar `http://127.0.0.1:8790`. Sin eso el navegador bloquea las respuestas (aviso de CORS en la página).

## Usarla
1. Servidor (por defecto devkeep), correo y contraseña de una persona con cuenta de panel y el interruptor **Puede entrar al Portal** encendido.
2. **Probar todas**. Verde = funciona como debe; rojo = falla o un caso que debía rechazarse fue aceptado (hueco).
3. Las pruebas que escriben (crear una ausencia real) o cierran la sesión están apagadas por defecto: se activan con sus casillas.

## Archivos
- `keeper-portal-client.js` — cliente de ejemplo para Lovable: tokens en memoria, una sola renovación a la vez, reintento tras renovar, paginación. Copiar este patrón.
- `app.js` — las pruebas y qué debe pasar en cada una.
- `index.html` — la página.

La contraseña no se guarda: vive solo en la memoria de la pestaña mientras está abierta.
