-- Public identifiers select the tenant before authentication; uniqueness must be global.
ALTER TABLE oauth_clients ADD UNIQUE KEY oauth_client_id_global (client_id);
ALTER TABLE api_keys ADD UNIQUE KEY api_key_prefix_global (public_prefix);
