-- La empresa plataforma es AZC Keeper, duena de todo Keeper. Su marca (nombre, logo, colores) es la marca del producto:
-- se cambia desde Empresas como la de cualquier empresa. Solo renombra si conserva el nombre inicial.
UPDATE tenants SET name = 'AZC Keeper', version = version + 1
WHERE tenant_id = 0x00000000000040008000000000000001 AND name = 'AZCKeeper Plataforma';
UPDATE branding SET display_name = 'AZC Keeper'
WHERE tenant_id = 0x00000000000040008000000000000001 AND display_name IN ('AZCKeeper', 'AZCKeeper Plataforma');
