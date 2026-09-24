"""Development only: python + PyYAML; no YAML parser required by PHP at runtime."""
import hashlib
import json
from pathlib import Path

import yaml

backend = Path(__file__).resolve().parents[1]
source = backend.parent / "docs/architecture/openapi-v4.yaml"
raw = source.read_bytes()
contract = yaml.safe_load(raw)
admin_roots = {"auth", "tenants", "organization", "schedules", "users", "devices", "policies", "roles", "permissions", "reports", "tiers", "releases", "release-deployments", "audit", "enrollments", "migration"}
excluded = {"/devices/{id}/transfer", "/users/{id}/dashboard", "/users/{id}/check-ins"}
paths = {key: value for key, value in contract["paths"].items()
         if key.startswith(("/client/", "/admin/", "/ext/v1/")) or key == "/oauth/token" or (key.split("/")[1] in admin_roots and key not in excluded)}
names = set()


def visit(value):
    if isinstance(value, dict):
        reference = value.get("$ref", "")
        if reference.startswith("#/components/schemas/"):
            name = reference.rsplit("/", 1)[-1]
            if name not in names:
                names.add(name)
                visit(contract["components"]["schemas"][name])
        for child in value.values():
            visit(child)
    elif isinstance(value, list):
        for child in value:
            visit(child)


visit(paths)
names.add("Problem")
result = {
    "source_sha256": hashlib.sha256(raw).hexdigest(),
    "paths": paths,
    "parameters": contract["components"]["parameters"],
    "schemas": {name: contract["components"]["schemas"][name] for name in sorted(names)},
}
(backend / "config/contract.json").write_text(
    json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
)
print(f"Exported {len(paths)} paths and {len(names)} schemas")
