<?php
declare(strict_types=1);
namespace Keeper;

final class Audit
{
    public function __construct(private Database $db) {}
    public function record(array $c, Request $r, string $action, string $resource, string $id, array $fields = [], string $outcome = 'allowed'): void
    {
        $this->db->run('INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields) VALUES (?,?,?,?,?,?,?,?,?,?)', [$c['tenant_id'], Util::bin(Util::uuid()), $c['principal_id'], $c['actor_type'] ?? 'device', $action, $resource, $id, Util::bin($r->requestId), $outcome, Util::json($fields)]);
    }
}
