<?php

namespace App\Services\Pbx;

use App\Models\Extension;
use App\Models\SipTrunk;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PbxConfigGenerator
{
    public function generate(): void
    {
        $directory = config('pbx.runtime_path');
        File::ensureDirectoryExists($directory, 0700, true);

        // The recordings volume is shared by the PHP and Asterisk containers,
        // whose service users intentionally have different numeric UIDs.
        $recordingRoot = storage_path('app/pbx-recordings');
        File::ensureDirectoryExists($recordingRoot, 0777, true);
        @chmod($recordingRoot, 0777);
        Tenant::query()->pluck('id')->each(function ($tenantId) use ($recordingRoot) {
            $tenantDirectory = $recordingRoot.'/tenant-'.$tenantId;
            File::ensureDirectoryExists($tenantDirectory, 0777, true);
            @chmod($tenantDirectory, 0777);
        });

        $this->write($directory.'/pjsip_endpoints.conf', $this->endpoints());
        $this->write($directory.'/pjsip_trunks.conf', $this->trunks());
        $this->write($directory.'/extensions_tenants.conf', $this->dialplan());
    }

    private function endpoints(): string
    {
        return Extension::query()->where('status', '!=', 'disabled')->get()->map(function (Extension $extension) {
            $username = $this->value($extension->sip_username);
            $id = $username;
            $secret = $this->value($extension->sip_secret);

            return "[{$id}-auth]\ntype=auth\nauth_type=userpass\nusername={$username}\npassword={$secret}\n\n"
                ."[{$id}]\ntype=aor\nmax_contacts=1\nremove_existing=yes\n\n"
                ."[{$id}]\ntype=endpoint\ntransport=transport-ws\ncontext=extension-{$extension->id}\naors={$id}\nauth={$id}-auth\nidentify_by=username,auth_username\nset_var=SPYGROUP=extension-{$extension->id}\ndisallow=all\nallow=ulaw,alaw\nwebrtc=yes\ndirect_media=no\nforce_rport=yes\nrewrite_contact=yes\nrtp_symmetric=yes\nice_support=yes\nmedia_encryption=dtls\ndtls_auto_generate_cert=yes\n\n";

        })->implode('');
    }

    private function trunks(): string
    {
        return SipTrunk::query()->where('is_active', true)->get()->map(function (SipTrunk $trunk) {
            $id = 'trunk-'.$trunk->id;
            $host = $this->value($trunk->host);
            $port = (int) $trunk->port;
            $auth = '';
            $authLine = '';
            if ($trunk->auth_mode === 'userpass') {
                $auth = "[{$id}-auth]\ntype=auth\nauth_type=userpass\nusername={$this->value($trunk->username)}\npassword={$this->value($trunk->password)}\n\n";
                $authLine = "outbound_auth={$id}-auth\n";
            }
            $fromDomain = $trunk->from_domain ? 'from_domain='.$this->value($trunk->from_domain)."\n" : '';
            $fromUser = $trunk->from_user ? 'from_user='.$this->value($trunk->from_user)."\n" : '';
            $proxy = $trunk->outbound_proxy ? 'outbound_proxy='.$this->value($trunk->outbound_proxy)."\n" : '';
            $codecs = collect($trunk->codecs ?: ['ulaw', 'alaw'])->map(fn ($codec) => $this->value($codec))->implode(',');

            return $auth
                ."[{$id}-aor]\ntype=aor\ncontact=sip:{$host}:{$port}\nqualify_frequency=30\nqualify_timeout=3.0\n\n"
                ."[{$id}]\ntype=endpoint\ntransport=transport-udp\naors={$id}-aor\n{$authLine}{$fromDomain}{$fromUser}{$proxy}disallow=all\nallow={$codecs}\ndirect_media=no\n\n";
        })->implode('');
    }

    private function dialplan(): string
    {
        $tenants = Tenant::query()->with(['trunks' => fn ($query) => $query->wherePivot('is_active', true)->where('sip_trunks.is_active', true)->orderBy('tenant_sip_trunks.priority'), 'extensions'])->get();
        $tenantContexts = $tenants->map(function (Tenant $tenant) {
            if ($tenant->trunks->isEmpty()) {
                return "[tenant-{$tenant->id}]\nexten => _X.,1,NoOp(No outbound route for tenant {$tenant->id})\n same => n,Congestion(10)\n same => n,Return()\n\n";
            }

            $recording = $tenant->record_calls
                ? " same => n,Set(CALL_RECORDING_FILE=tenant-{$tenant->id}/\${UNIQUEID}.wav)\n same => n,MixMonitor(\${RECORDING_ROOT}/\${CALL_RECORDING_FILE},ab)\n"
                : '';
            $routes = $tenant->trunks->values()->map(function (SipTrunk $trunk, int $index) {
                $tech = $this->value($trunk->tech_prefix ?? '');
                $trunkName = 'trunk-'.$trunk->id;
                $next = 'route-'.($index + 1);
                $label = $index === 0 ? '' : "({$next})";

                return " same => n{$label},NoOp(Outbound route {$trunk->id} with configured TECH)\n"
                    ." same => n,Dial(PJSIP/{$tech}\${TH_DEST}@{$trunkName},40,g)\n"
                    ." same => n,GotoIf(\$[\"\${DIALSTATUS}\"=\"ANSWER\"]?done)\n";
            })->implode('');

            // The browser is intentionally unaware of the carrier TECH. It sends
            // a Brazilian destination and the PBX builds TECH + 55 + DDD + number.
            return "[tenant-{$tenant->id}]\nexten => _X.,1,NoOp(Outbound tenant {$tenant->id})\n"
                ." same => n,Set(TH_DEST=\${FILTER(0-9,\${EXTEN})})\n"
                ." same => n,ExecIf(\$[\${LEN(\${TH_DEST})}=10]?Set(TH_DEST=55\${TH_DEST}))\n"
                ." same => n,ExecIf(\$[\${LEN(\${TH_DEST})}=11]?Set(TH_DEST=55\${TH_DEST}))\n"
                .$recording.$routes
                .($tenant->record_calls
                    ? " same => n,StopMixMonitor()\n same => n,System(rm -f \"\${RECORDING_ROOT}/\${CALL_RECORDING_FILE}\")\n"
                    : '')
                ." same => n(done),Return()\n\n";
        })->implode('');
        $allExtensions = $tenants->flatMap(fn (Tenant $tenant) => $tenant->extensions);
        $extensionContexts = $allExtensions->map(function (Extension $extension) use ($allExtensions) {
            $targets = ! $extension->user?->canManageOperation() ? collect() : ($extension->user->isSuperAdmin()
                ? $allExtensions
                : $allExtensions->where('tenant_id', $extension->tenant_id));
            $supervision = $targets->flatMap(fn (Extension $target) => [
                "exten => *81{$target->id},1,NoOp(Listen {$target->id} by {$extension->id})\n same => n,Answer()\n same => n,ChanSpy(PJSIP,qbg(extension-{$target->id}))\n same => n,Hangup()\n",
                "exten => *82{$target->id},1,NoOp(Whisper {$target->id} by {$extension->id})\n same => n,Answer()\n same => n,ChanSpy(PJSIP,qbwg(extension-{$target->id}))\n same => n,Hangup()\n",
                "exten => *83{$target->id},1,NoOp(Barge {$target->id} by {$extension->id})\n same => n,Answer()\n same => n,ChanSpy(PJSIP,qbBg(extension-{$target->id}))\n same => n,Hangup()\n",
            ])->implode('');
            $outbound = $extension->user?->isTenantAdmin()
                ? "exten => _X.,1,NoOp(Outbound blocked for tenant administrator {$extension->id})\n same => n,Hangup(21)\n"
                : "exten => _X.,1,NoOp(Extension {$extension->id})\n same => n,Set(__TH_EXTENSION_ID={$extension->id})\n same => n,Set(__TH_TENANT_ID={$extension->tenant_id})\n same => n,Set(SPYGROUP=extension-{$extension->id})\n same => n,Gosub(tenant-{$extension->tenant_id},\${EXTEN},1)\n same => n,Hangup()\n";

            return "[extension-{$extension->id}]\n{$supervision}{$outbound}\n";
        })->implode('');

        return $tenantContexts.$extensionContexts;
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path.'.tmp';
        if (File::put($temporary, $contents, true) === false || ! @rename($temporary, $path)) {
            throw new RuntimeException("Não foi possível gerar {$path}.");
        }
        // Asterisk runs under its own container user and reads this shared Docker volume.
        // Production must use a dedicated protected volume; local bind mounts need group/world read.
        @chmod($path, 0644);
    }

    private function value(?string $value): string
    {
        $value ??= '';
        if (preg_match('/[\r\n;#]/', $value)) {
            throw new RuntimeException('Valor inválido para configuração SIP.');
        }

        return $value;
    }
}
