<?php
declare(strict_types=1);
// Icono de notificaciones de la barra superior (layout.php y layout-k3.php). notifications.js lo llena: número de
// pendientes y lista agrupada (Alertas · Jornada de hoy) que se despliega sobre el contenido sin desplazarlo.
if (!isset($page)) { http_response_code(404); exit; }
?>
<div class="notify" id="notifications" hidden>
  <button class="icon-button notify-button" id="notify-toggle" type="button" aria-expanded="false" aria-controls="notify-panel" aria-label="Notificaciones">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
    <span class="notify-count" id="notify-count" aria-hidden="true" hidden></span>
  </button>
  <section class="notify-panel" id="notify-panel" aria-labelledby="notify-title" tabindex="-1" hidden>
    <h2 id="notify-title">Notificaciones</h2>
    <div id="notify-body" aria-live="polite"></div>
  </section>
</div>
