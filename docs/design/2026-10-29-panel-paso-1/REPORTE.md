# Reporte · Paso 1, vuelta 3

Ejecutado ENCARGO-3 con las reglas de ENCARGO.md, sobre la maqueta existente de la vuelta 2. Sin commits ni cambios fuera de esta carpeta durante este paso.

- Protección fallida retirada del cálculo de alertas, KPI, lista, orden de atención y agregados del asistente. El reporte de protección sigue en la ficha como información.
- Cuatro alertas: llegada fuera del margen, sin conexión en horario, posible doble empleo y app sospechosa. 58 personas con alertas, 7 con alguna alta.
- Franja de estados sin contador duplicado: 224 conectadas, 39 sin conexión y 37 sin actividad reciente.
- Asistente de IA en página propia, abierta desde una tarjeta en Administrar. Vista previa en lenguaje llano y JSON plegado en «Ver detalle técnico». Acceso restringido a superadmin en la demostración.
- README y verificar.cjs actualizados. No hay servicios, credenciales reales ni envíos.

## Verificación

`node verificar.cjs`: aprobado. 41 referencias locales, sintaxis de scripts, 300 identidades únicas, alcance de supervisor de 25 personas, tiempos coherentes, cuatro tipos de alerta, severidades/orden y payload sin identidades. Se prueba que modificar protección no cambia alertas. Productividad 63,7 %, 13 llegadas fuera del margen, comparación semanal −6,7 pp.

## Limitaciones y dudas

La revisión visual a 1366×768 y 375 px y la interacción real en navegador quedan a Claude por instrucción expresa del encargo. Las capturas anteriores no acreditan esta vuelta. Pendientes de producto: vigencia y cierre de alertas, alcance de lectura de datos técnicos y contratos del asistente. Las tres decisiones de ENCARGO-3 están resueltas.
