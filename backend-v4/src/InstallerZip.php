<?php
declare(strict_types=1);

namespace Keeper;

// Zip del instalador armado al vuelo, sin copiar el archivo publicado ni reescribirlo: se envian tal cual los bytes
// [0, cd_offset) del original (la entrada vieja de installation.json queda huerfana; los lectores usan el directorio
// central) y despues una cola nueva: cabecera local + installation.json (stored) + directorio central con el registro
// de installation.json apuntando a la entrada nueva + EOCD. Los demas registros del directorio se copian byte a byte.
// Solo zips sin ZIP64, sin cifrado y de un solo disco; cualquier otra cosa -> null (el llamador responde 409).
final class InstallerZip
{
    private const NAME = 'installation.json';

    // Lee EOCD y directorio central de $h (archivo de $size bytes). Devuelve null si el zip no es de la forma soportada.
    public static function read($h, int $size): ?array
    {
        if ($size < 22) { return null; }
        $span = min($size, 22 + 0xFFFF);
        if (fseek($h, $size - $span) !== 0) { return null; }
        $end = self::exact($h, $span); if ($end === null) { return null; }
        $eocd = null;
        for ($p = $span - 22; $p >= 0; $p--) {
            if (substr($end, $p, 4) !== "PK\x05\x06") { continue; }
            $e = unpack('vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset/vclen', $end, $p + 4);
            if ($p + 22 + $e['clen'] === $span) { $eocd = $e + ['pos' => $size - $span + $p, 'comment' => substr($end, $p + 22)]; break; }
        }
        if ($eocd === null || $eocd['disk'] !== 0 || $eocd['cddisk'] !== 0 || $eocd['diskentries'] !== $eocd['entries'] || $eocd['entries'] === 0xFFFF
            || $eocd['cdsize'] === 0xFFFFFFFF || $eocd['cdoffset'] === 0xFFFFFFFF || $eocd['cdoffset'] + $eocd['cdsize'] !== $eocd['pos']) { return null; }
        // Localizador ZIP64 justo antes del EOCD: no soportado.
        if ($eocd['pos'] >= 20 && (fseek($h, $eocd['pos'] - 20) !== 0 || self::exact($h, 4) === "PK\x06\x07")) { return null; }
        if (fseek($h, $eocd['cdoffset']) !== 0) { return null; }
        $cd = $eocd['cdsize'] === 0 ? '' : self::exact($h, $eocd['cdsize']); if ($cd === null) { return null; }
        $records = []; $target = null; $p = 0;
        while ($p < strlen($cd)) {
            if ($p + 46 > strlen($cd) || substr($cd, $p, 4) !== "PK\x01\x02") { return null; }
            $r = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Voffset', $cd, $p + 4);
            $len = 46 + $r['nlen'] + $r['xlen'] + $r['clen'];
            if ($p + $len > strlen($cd) || ($r['flags'] & 0x41) !== 0 || $r['disk'] !== 0
                || in_array(0xFFFFFFFF, [$r['csize'], $r['usize'], $r['offset']], true) || $r['offset'] >= $eocd['cdoffset']) { return null; }
            if (substr($cd, $p + 46, $r['nlen']) === self::NAME) { if ($target !== null) { return null; } $target = count($records); }
            $records[] = substr($cd, $p, $len); $p += $len;
        }
        if (count($records) !== $eocd['entries']) { return null; }
        if ($target === null && count($records) >= 0xFFFF) { return null; }
        return ['cd_offset' => $eocd['cdoffset'], 'records' => $records, 'target' => $target, 'comment' => $eocd['comment']];
    }

    // Cola que sigue a los bytes [0, cd_offset) del original: entrada nueva, directorio central y EOCD.
    public static function tail(array $zip, string $content, int $time): string
    {
        $offset = $zip['cd_offset']; $crc = crc32($content); $size = strlen($content); $nlen = strlen(self::NAME);
        $d = getdate($time);
        $dosTime = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $dosDate = ((max($d['year'], 1980) - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nlen, 0) . self::NAME;
        // Registro nuevo: conserva "version made by" y atributos del original (si existia); sin extras viejos (fechas o ZIP64 ajenos).
        $made = 20; $iattr = 0; $eattr = 0;
        if ($zip['target'] !== null) { $o = unpack('vmade', $zip['records'][$zip['target']], 4); $a = unpack('viattr/Veattr', $zip['records'][$zip['target']], 36); $made = $o['made']; $iattr = $a['iattr']; $eattr = $a['eattr']; }
        $record = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', $made, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nlen, 0, 0, 0, $iattr, $eattr, $offset) . self::NAME;
        $records = $zip['records'];
        if ($zip['target'] === null) { $records[] = $record; } else { $records[$zip['target']] = $record; }
        $cd = implode('', $records); $cdOffset = $offset + strlen($local) + $size;
        if ($cdOffset + strlen($cd) > 0xFFFFFFFE) { throw new \RuntimeException('installer zip too large'); }
        $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($records), count($records), strlen($cd), $cdOffset, strlen($zip['comment'])) . $zip['comment'];
        return $local . $content . $cd . $eocd;
    }

    private static function exact($h, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) { $chunk = fread($h, $n - strlen($buf)); if ($chunk === false || $chunk === '') { return null; } $buf .= $chunk; }
        return $buf;
    }
}
