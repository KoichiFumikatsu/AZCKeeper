<?php
declare(strict_types=1);

namespace Keeper {
    function hash_equals(string $known, string $supplied): bool
    {
        if (\ExternalAuthProbe::$hash !== null) { (\ExternalAuthProbe::$hash)($known,$supplied); }
        return \hash_equals($known,$supplied);
    }
    function fopen(string $filename, string $mode, bool $useIncludePath=false, mixed $context=null): mixed
    {
        if (\ExternalAuthProbe::$io !== null) { (\ExternalAuthProbe::$io)(); }
        return $context===null?\fopen($filename,$mode,$useIncludePath):\fopen($filename,$mode,$useIncludePath,$context);
    }
}

namespace {
    use Keeper\{ApiError,Config,Database,ExternalAuth,OAuthError,RateLimiter,Request,Util};

    final class ExternalAuthProbe extends PDOStatement
    {
        public static ?Closure $hash=null;
        public static ?Closure $io=null;
        public static array $queries=[];
        public function execute(?array $params=null): bool
        {
            self::$queries[]=$this->queryString;
            return parent::execute($params);
        }
    }

    function externalAuthRequest(array $headers, array $operation=[]): Request
    {
        $reflection=new ReflectionClass(Request::class);
        $r=$reflection->newInstanceWithoutConstructor();
        foreach (['headers'=>array_change_key_case($headers,CASE_LOWER),'operation'=>$operation,'body'=>(object)[],'requestId'=>Util::uuid()] as $field=>$value) {
            $reflection->getProperty($field)->setValue($r,$value);
        }
        return $r;
    }

    function externalBucketFiles(array $credential, bool $oauth): array
    {
        $buckets=[['integration','external:'.bin2hex($credential['tenant'].$credential['integration'])],['tenant','external:'.bin2hex($credential['tenant'])]];
        if ($oauth) { $buckets[]=['integration','oauth:'.bin2hex($credential['tenant'].$credential['id'])]; }
        return array_map(static function(array $bucket): string {
            $key=hash('sha256',implode(':',$bucket));
            return Config::get('RATE_DIR').'/'.substr($key,0,2).'/'.$key.'.json';
        },$buckets);
    }

    function externalAuthTests(Database $db): void
    {
        $before=$GLOBALS['checks'];
        check($db->one("SELECT filename FROM schema_migrations WHERE filename='0010_external_global_credentials.sql'")!==null,'global credential migration applied after seeds');
        $u=secondTenant($db); $v=secondTenant($db);
        foreach (['oauth2'=>['oauth_clients','client_id','oauth_client_id_global'],'api_key'=>['api_keys','public_prefix','api_key_prefix_global']] as $type=>[$table,$field,$index]) {
            $a=externalCredential($db,$u,$type,['tiers:read']); $b=externalCredential($db,$v,$type,['tiers:read']);
            $columns=$db->run('SELECT COLUMN_NAME AS field_name,NON_UNIQUE AS allows_duplicates FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',[$table,$index])->fetchAll();
            check(count($columns)===1 && $columns[0]['field_name']===$field && $columns[0]['allows_duplicates']===0,$field.' has a single-column global UNIQUE');
            try {
                $db->run("UPDATE $table SET $field=? WHERE tenant_id=? AND id=?",[$a['public'],$b['tenant'],$b['id']]);
                check(false,$field.' duplicate across tenants must fail');
            } catch (PDOException $e) { check(($e->errorInfo[1]??0)===1062,$field.' rejects cross-tenant duplicate'); }
            if ($type==='oauth2') { expect(externalToken($a),200,'first tenant unique OAuth'); expect(externalToken($b),200,'second tenant unique OAuth'); }
            else { expect(externalRequest('/ext/v1/tiers',$a['headers']),200,'first tenant unique API key'); expect(externalRequest('/ext/v1/tiers',$b['headers']),200,'second tenant unique API key'); }
        }

        foreach (['oauth2','api_key'] as $type) {
            $oauth=$type==='oauth2';
            $credential=externalCredential($db,secondTenant($db),$type,['tiers:read']);
            $wrong=$credential;
            $wrong['headers']=$oauth?['Authorization'=>'Basic '.base64_encode($credential['public'].':'.str_repeat('x',43)),'Content-Type'=>'application/x-www-form-urlencoded']:['X-API-Key'=>$credential['public'].'.'.str_repeat('x',43)];
            $send=static fn(array $c): array=>$oauth?externalToken($c):externalRequest('/ext/v1/tiers',$c['headers']);
            for ($i=0;$i<6;$i++) { expect($send($wrong),$i<5?401:429,$type.' isolated abuse counter'); }
            $files=externalBucketFiles($credential,$oauth);
            foreach ($files as $file) { check(!file_exists($file),$type.' invalid secret never creates legitimate quota'); }
            expect($send($credential),200,$type.' valid credential passes after abuse quota exhausted');
            $snapshots=array_map('file_get_contents',$files);
            expect($send($wrong),429,$type.' abuse remains limited after successful auth');
            check(array_map('file_get_contents',$files)===$snapshots,$type.' invalid secret leaves every legitimate bucket unchanged');
            expect($send($credential),200,$type.' legitimate credential still passes');
        }

        $auth=new ExternalAuth($db,new RateLimiter());
        $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ExternalAuthProbe::class]);
        try {
            foreach (['oauth2'=>'oauth_clients','api_key'=>'api_keys'] as $type=>$table) {
                $credential=externalCredential($db,secondTenant($db),$type,[]);
                foreach (['bad_secret','revoked','missing'] as $state) {
                    if ($state==='revoked') { $db->run("UPDATE $table SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?",[$credential['tenant'],$credential['id']]); }
                    $public=$state==='missing'?Util::uuid():$credential['public'];
                    $secret=$state==='bad_secret'?str_repeat('x',43):$credential['secret'];
                    $headers=$type==='oauth2'?['Authorization'=>'Basic '.base64_encode($public.':'.$secret)]:['X-API-Key'=>$public.'.'.$secret];
                    $calls=[]; ExternalAuthProbe::$queries=[];
                    ExternalAuthProbe::$hash=static function(string $known,string $supplied) use (&$calls): void {
                        $calls[]=[strlen($known),strlen($supplied),\hash_equals($known,hash('sha256',str_repeat("\0",32),true))];
                    };
                    try {
                        $r=externalAuthRequest($headers);
                        $type==='oauth2'?$auth->issue($r):$auth->context($r);
                        check(false,$type.' '.$state.' must fail');
                    } catch (OAuthError|ApiError $e) {
                        check($e->status===401 && $e->errorCode===($type==='oauth2'?'invalid_client':'invalid_credentials'),$type.' '.$state.' same authentication error');
                    } finally { ExternalAuthProbe::$hash=null; }
                    check(count($calls)===1 && $calls[0][0]===32 && $calls[0][1]===32,$type.' '.$state.' always compares fixed-length hashes');
                    check($calls[0][2]===($state!=='bad_secret'),$type.' '.$state.' dummy digest selection');
                    check(count(ExternalAuthProbe::$queries)===1 && !str_contains(ExternalAuthProbe::$queries[0],'FOR UPDATE'),$type.' '.$state.' same one-query unlocked failure path');
                }
            }

            $key=externalCredential($db,secondTenant($db),'api_key',[]);
            $context=$auth->context(externalAuthRequest($key['headers']));
            check($context['tenant_id']===$key['tenant'] && $context['scopes']===[],'operation without x-required-scopes authenticates without 500');
            $savedGet=$_GET;
            try {
                $_GET=['include_titles'=>'true'];
                try {
                    $auth->context(externalAuthRequest($key['headers'],['x-conditional-scopes'=>['include_titles'=>['activity-titles:read']]]));
                    check(false,'conditional scope still required without base scopes');
                } catch (ApiError $e) { check($e->status===403 && $e->errorCode==='insufficient_scope','conditional scope without base scopes returns 403, not 500'); }
            } finally { $_GET=$savedGet; }

            $oauth=externalCredential($db,secondTenant($db),'oauth2',[]);
            $io=0;
            ExternalAuthProbe::$io=static function() use ($db,&$io): void { check(!$db->pdo->inTransaction(),'limiter I/O holds no client transaction or lock'); $io++; };
            try { $token=$auth->issue(externalAuthRequest($oauth['headers'])); }
            finally { ExternalAuthProbe::$io=null; }
            check($io===3 && isset($token['access_token']),'all OAuth limiter buckets run outside transaction');

            $other=new Database(); $revoked=false;
            ExternalAuthProbe::$io=static function() use ($db,$other,$oauth,&$revoked): void {
                check(!$db->pdo->inTransaction(),'revocation during limiter I/O has no client lock');
                if (!$revoked) { $revoked=true; $other->run('UPDATE oauth_clients SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$oauth['tenant'],$oauth['id']]); }
            };
            try {
                $auth->issue(externalAuthRequest($oauth['headers'])); check(false,'revocation during limiter must prevent issuance');
            } catch (OAuthError $e) { check($e->status===401 && $e->errorCode==='invalid_client','locked recheck rejects concurrent revocation'); }
            finally { ExternalAuthProbe::$io=null; }
            check($db->one('SELECT COUNT(*) n FROM oauth_access_tokens WHERE tenant_id=? AND client_id=?',[$oauth['tenant'],$oauth['id']])['n']===1,'concurrent revocation creates no extra token');
        } finally {
            ExternalAuthProbe::$hash=null; ExternalAuthProbe::$io=null;
            $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
        }

        $valid=new ReflectionMethod(ExternalAuth::class,'valid');
        while ((float)(new DateTimeImmutable())->format('0.u')>0.25) { usleep(10000); }
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $later=$now->format('Y-m-d H:i:s').'.900000';
        check($valid->invoke($auth,['revoked_at'=>null,'created_at'=>$now->modify('-1 day')->format('Y-m-d H:i:s.u'),'expires_at'=>$later]),'DATETIME(6) expiration later this second remains valid');
        check(!$valid->invoke($auth,['revoked_at'=>null,'created_at'=>$later,'expires_at'=>$now->modify('+1 day')->format('Y-m-d H:i:s.u')]),'DATETIME(6) creation later this second is not yet valid');
        echo 'EXTERNAL AUTH PASS: '.($GLOBALS['checks']-$before)." assertions\n";
    }
}
