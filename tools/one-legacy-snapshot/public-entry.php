<?php
// Entrada publica MINIMA del puente One -> Keeper (README §3). Es lo unico que va bajo public/: se copia como
// public/<carpeta no adivinable>/index.php. serve.php, RosterBuilder.php, los mapas, la config con el hash del token y la
// cache viven FUERA de public/. Para apagar el puente basta con borrar este archivo (y su carpeta).
const ONE_BRIDGE_CONFIG = '/home/www/keep.azclegal.com/one-bridge/serve.config.php';
require '/home/www/keep.azclegal.com/one-bridge/serve.php';
