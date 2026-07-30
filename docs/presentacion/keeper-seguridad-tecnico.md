# AZCKeeper — Capacidades de Seguridad (versión técnica)

**Documento para el equipo técnico y de dirección de TI.**
Fecha: 2026-07-30 · Estado de cada capacidad marcado explícitamente.

Leyenda de estado: **[VIVO]** en producción · **[DEV]** verificado en entorno de desarrollo ·
**[DISEÑADO]** especificado, pendiente de construir.

---

## 1. Qué es AZCKeeper y qué cambia

AZCKeeper es el sistema de monitoreo de puestos de trabajo de AZC: un cliente en cada equipo reporta
actividad, y un panel central lo administra. La versión en curso (**Keeper 4**) lo reconstruye con tres
objetivos: **proteger los datos de los clientes**, **cumplir la normativa de datos personales**, y
**vender el monitoreo como servicio por niveles** a las firmas.

Este documento describe las capacidades de seguridad que se están incorporando. No es marketing: cada
punto lleva su estado real.

---

## 2. Endurecimiento del puesto de trabajo (Módulo de Seguridad)

El área de agentes de servicio maneja información sensible de las firmas. El Módulo de Seguridad cierra
las vías de fuga en el equipo, al estilo de un piso de BPO.

**Controles [DISEÑADO/DEV — el reporte de estado ya funciona en DEV, el enforcement necesita el agente elevado]:**

| Control | Efecto |
|---|---|
| Bloqueo de descargas del navegador | Impide bajar archivos |
| Bloqueo de dominios (URLBlocklist en HKLM) | Sobrevive a VPN y a DNS cifrado; actúa dentro del navegador |
| Bloqueo de extensiones | Cierra las VPN por extensión, vía de evasión conocida |
| Bloqueo de DevTools (F12) | Impide extraer datos de la interfaz sin descargarlos |
| Bloqueo de cuenta personal / sincronización | Cierra la fuga por perfil personal de Chrome |
| Bloqueo de USB y almacenamiento extraíble | Cierra la copia física |
| SRP (restricción de ejecutables) | Impide correr navegadores o VPN "portables" |
| Endurecimiento de UAC | Auto-deniega elevación al usuario estándar |

**Lo que se aprendió y corrigió:** tres intentos previos de bloqueo web (2024-2025) fallaron porque
operaban **sin privilegios**. El diseño nuevo usa un **servicio elevado** (`AZCKeeperAgent`) que escribe
las políticas donde el sistema operativo las hace cumplir de verdad. Y —crítico— **reporta el estado
aplicado**: antes, un bloqueo podía fallar en silencio y nadie se enteraba durante semanas. Ahora el panel
distingue *aplicado*, *no aplicado* y *sin reportar*.

**Parametrizable por persona.** El candado no es todo-o-nada: se asigna por agente, con excepciones
nominales que quedan justificadas y con vencimiento. Un abogado de la misma firma no recibe el candado del
agente.

---

## 3. Trazabilidad: quién hizo qué y quién vio qué

Dos capacidades distintas, ambas nuevas:

**Bitácora con actor [DEV].** Cada acción administrativa (cambiar una política, otorgar una excepción,
aprobar un enrolamiento) registra **quién la ejecutó**, no solo a quién afectó. La versión anterior tenía
el sujeto pero no el actor: podía decir "a esta persona le cambiaron el candado", nunca "quién se lo
cambió". Sin el actor, una bitácora no responde lo que un auditor pregunta.

**Auditoría de acceso a datos sensibles [DEV, verificado].** Consultar la actividad de un empleado —los
procesos y ventanas que usó— deja un rastro: quién consultó, a quién, y en qué rango de fechas. Ya
funciona: cada consulta a la vista de procesos escribe una fila `data_access` en la bitácora. Esto
protege a la empresa y a TI: demuestra que el acceso a datos de vigilancia está controlado, no es libre.

---

## 4. Protección del dato más sensible: el título de ventana

Los títulos de las ventanas que usa un agente pueden contener nombres de casos, contrapartes y clientes de
las firmas — información potencialmente amparada por **secreto profesional**.

**Enmascarado por rol [DEV, verificado].** La vista de procesos muestra el proceso y el tiempo, pero el
título completo solo lo ve quien tiene el permiso específico; para el resto se trunca. Verificado en DEV:
un título *"Caso Rodríguez vs Empresa - expediente confidencial"* llega como *"...expediente c"*.

---

## 5. Integridad de los datos: que el sistema no mienta

Un sistema de monitoreo que reporta datos falsos es peor que no tener ninguno, porque se toman decisiones
sobre ellos. Keeper 4 corrige tres defectos de integridad del sistema anterior:

- **Banderas de cobertura [DEV].** Si un módulo estaba apagado, el sistema anterior fabricaba una
  puntuación de productividad del 90% —el equipo sin monitorear aparecía como el mejor de la flota—.
  Ahora cada métrica declara si tuvo datos; "no medido" no puede disfrazarse de "buen resultado".
- **Fin de la corrupción acumulada [DEV].** Un defecto sumaba el total del día dos veces y lo hacía
  imborrable. El diseño nuevo lo elimina de raíz.
- **Clasificación correcta de personas [DEV].** El sistema anterior asignaba a la gente a áreas y cargos
  con identificadores que "funcionaban por coincidencia". Keeper 4 lo hace verificable a nivel de base de
  datos: es imposible clasificar a un agente en el área equivocada.

---

## 6. Multi-tenant y niveles de servicio (dimensión de ventas)

Keeper pasa de herramienta interna a **servicio que se vende a las firmas**. La arquitectura lo soporta
con aislamiento y control comercial:

**Modelo de cuatro capas [DEV, el recorte verificado end-to-end]:**

1. **Catálogo** — qué módulos existen.
2. **Tier** — a qué tiene derecho la firma (lo que compró).
3. **Política** — qué está encendido operativamente.
4. **Estado** — qué corre de verdad en el equipo.

El backend **recorta la política contra el tier**: si una firma no compró screenshots, el módulo nunca
llega al equipo, aunque una política lo pida. Verificado en DEV: una firma en tier básico recibe
`screenshots=false` y `location=false` aunque la política los encienda. **Un cliente no puede activarse
una función que no pagó.**

**Gestión parametrizable [DEV]:**
- Interruptor global de tiers: encendido = se cobra por nivel; apagado = todos los módulos (uso interno).
- Override por firma: conceder un módulo suelto (add-on) o revocarlo, sin cambiar de tier.
- Tiers editables desde el panel: agregar y modificar niveles sin tocar código.

**Aislamiento por firma.** Cada firma ve solo a sus agentes y solo lo que le corresponde. Los datos de un
cliente no se mezclan con los de otro — es un requisito estructural, no un filtro que se pueda olvidar.

---

## 7. Nuevas capacidades premium [DISEÑADO — esquema listo, cliente por construir]

Tienen su lugar en la base de datos y en el modelo de tiers; falta construir el agente en el cliente:

- **Capturas de pantalla** — con el archivo en almacenamiento de objetos (no en la base), hash de
  integridad para probar que no se alteraron, y acceso auditado.
- **Ubicación** — con la fuente (GPS/wifi/IP) para no dar por exacta una estimación.
- **Apagado remoto** — orden con acuse y **vencimiento** (un apagado no recogido no se dispara días
  después).
- **Diagnóstico de red bajo demanda** — el supervisor lo pide y el equipo responde en segundos.

Los dos primeros son datos sensibles: están en el tier más alto y su consulta se audita.

---

## 8. Diseño a prueba de fallos

- **Fail-closed:** si el equipo pierde contacto con el servidor, conserva las restricciones vigentes en
  vez de soltarlas.
- **Kill-switch:** un interruptor revierte todo el endurecimiento si algo tumba la operación.
- **Vencimiento de comandos y de excepciones:** nada peligroso queda vivo indefinidamente por olvido.
- **El plano de control sobrevive al fallo de un módulo:** el canal que apaga un módulo no viaja por el
  mismo circuito que el módulo puede romper.

---

## 9. Cumplimiento normativo (Ley 1581 / habeas data)

La gerencia jurídica revisó el marco. El sistema aporta las piezas que la norma exige demostrar:

- **Finalidad y proporcionalidad:** cada control es el mínimo para el fin; el candado se asigna por
  puesto, no de forma indiscriminada.
- **Trazabilidad:** quién accedió a qué dato y cuándo (§3).
- **Seguridad del dato:** enmascarado de lo sensible, hash de integridad en capturas, aislamiento por
  cliente.
- **Rol de encargado/responsable:** la arquitectura permite declarar, por firma, qué controles protegen
  su información — evidencia para el contrato con cada cliente.

---

## 10. Estado del proyecto

- **Esquema de datos de Keeper 4:** completo, 36 tablas, verificado en desarrollo. Cimiento de todo.
- **API — corte vertical (handshake con tier, ingesta, vista de procesos):** construido y verificado
  end-to-end en desarrollo.
- **Resto de la API** (comandos, capturas, ubicación, doble empleo, enrolamiento): en construcción.
- **Cliente C#** (agente elevado, captura, GPS, ejecución de comandos): por construir; requiere pruebas en
  hardware real antes de tocar la flota.
- **Panel de administración:** por reconstruir sobre la base nueva.

El orden es deliberado: primero la base de datos correcta, luego la API que la hace cumplir, luego el
cliente y el panel. Cada capa se verifica antes de la siguiente.
