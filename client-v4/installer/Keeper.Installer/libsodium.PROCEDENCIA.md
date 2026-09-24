# Procedencia de libsodium.dll

Se incorpora una DLL nativa de terceros que cifra el sobre del escrow. Se deja constancia
de su origen porque **no lleva firma Authenticode** y la confianza depende del hash.

| Dato | Valor |
|---|---|
| Paquete | `libsodium` 1.0.20.1 (NuGet oficial) |
| Autor | Frank Denis — autor de libsodium |
| Sitio | https://libsodium.org/ |
| Descarga | `https://www.nuget.org/api/v2/package/libsodium/1.0.20.1` |
| SHA-256 del `.nupkg` | `C1DFF3DF51516DC6951ABA2048D56379F1185A33714C6CD0774E033A56353C51` |
| Ruta dentro del paquete | `runtimes/win-x64/native/libsodium.dll` |
| **SHA-256 de la DLL** | `1A5690BAADB556E195342CB7C81B20B5D2F2924904E3C8DC80116AEE49D9D732` |
| Tamaño | 344.064 bytes |
| Versión de archivo | 1.0.20.0 (x64) |
| Firma Authenticode | **Ninguna** (normal en los binarios nativos de libsodium) |

## Cómo se protege

1. `Migration.Native.LoadSodium(ruta, sha256Esperado)` **comprueba el SHA-256 antes de cargarla**
   y aborta con `sodium_hash_mismatch` si no coincide.
2. Se carga por ruta absoluta con `LOAD_LIBRARY_SEARCH_APPLICATION_DIR | LOAD_LIBRARY_SEARCH_SYSTEM32`,
   nunca desde el `PATH`, para impedir secuestro de DLL.
3. El hash esperado viaja en `deployment.json`, cubierto por el manifiesto firmado del paquete puente.

Una DLL sustituida podría entregar en claro la contraseña de `azcadmin`: por eso el anclaje por hash
no es opcional.

## Al actualizar la versión

Recalcular el SHA-256, actualizarlo aquí y en `deployment.json`, y volver a firmar el paquete.
