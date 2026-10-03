-- "Titulos de ventana" vendia algo que el servidor no guarda (Ingest inserta window_title NULL); lo que existe es el
-- sitio web que se deriva del titulo del navegador. La funcion pasa a ser "Sitios web visitados" (codigo sitios).
UPDATE tenant_features SET feature_code = 'sitios' WHERE feature_code = 'titulos';
UPDATE feature_package_items SET feature_code = 'sitios' WHERE feature_code = 'titulos';
UPDATE person_plan_items SET feature_code = 'sitios' WHERE feature_code = 'titulos';
