<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Shadowrocket;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Utils\Helper;
use Tests\TestCase;

class EchSubscriptionTest extends TestCase
{
    private function server($type, $settings)
    {
        return [
            'type' => $type, 'name' => 'ECH test', 'host' => 'node.example', 'port' => 443,
            'tls' => 1, 'flow' => '', 'network' => 'ws', 'networkSettings' => [], 'network_settings' => [],
            'tlsSettings' => $settings, 'tls_settings' => $settings,
        ];
    }

    public function testAllCustomSubscriptionBuildersNormalizeOldConfigsAndExcludePrivateKeys()
    {
        $pair = Helper::generateEchKeyPair('public.example');
        $legacy = base64_encode(substr(base64_decode($pair['ech_config']), 2));
        $uuid = '11111111-1111-4111-8111-111111111111';
        foreach (['vmess', 'vless', 'trojan'] as $type) {
            $server = $this->server($type, [
                'ech' => 'custom', 'ech_config' => $legacy, 'ech_key' => $pair['ech_key'],
                'server_name' => 'node.example',
            ]);
            $method = 'build' . ucfirst($type);
            $uri = Helper::buildUri($uuid, $server);
            parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
            $this->assertSame($pair['ech_config'], $query['ech']);
            $this->assertStringNotContainsString($pair['ech_key'], rawurldecode($uri));

            foreach ([ClashMeta::class, ClashVerge::class, ClashNyanpasu::class] as $class) {
                $result = $class::$method($uuid, $server);
                $this->assertSame($pair['ech_config'], $result['ech-opts']['config']);
                $this->assertStringNotContainsString($pair['ech_key'], json_encode($result));
            }
            foreach ([Singbox::class, SingboxOld::class] as $class) {
                // SingboxOld has custom ECH support for VMess only.
                if ($class === SingboxOld::class && $type !== 'vmess') continue;
                $builder = new $class([], []);
                $build = new \ReflectionMethod($class, $method);
                $build->setAccessible(true);
                $result = $build->invoke($builder, $uuid, $server);
                $this->assertSame($pair['ech_config'], Helper::echConfigBase64($result['tls']['ech']['config']));
                $this->assertStringContainsString('BEGIN ECH CONFIGS', $result['tls']['ech']['config'][0]);
                $this->assertStringNotContainsString($pair['ech_key'], json_encode($result));
            }
            if ($type === 'vmess') {
                parse_str(parse_url(trim(Shadowrocket::buildVmess($uuid, $server)), PHP_URL_QUERY), $query);
                $this->assertSame($pair['ech_config'], $query['ech']);
                $this->assertArrayNotHasKey('ech_key', $query);
            }
        }
    }

    public function testCloudflareModeStillUsesDnsConfiguration()
    {
        $server = $this->server('vmess', ['ech' => 'cloudflare']);
        $uuid = '11111111-1111-4111-8111-111111111111';
        parse_str(parse_url(trim(Helper::buildUri($uuid, $server)), PHP_URL_QUERY), $query);
        $this->assertSame('cloudflare-ech.com+https://doh.pub/dns-query', $query['ech']);
        $build = new \ReflectionMethod(Singbox::class, 'buildVmess');
        $build->setAccessible(true);
        $result = $build->invoke(new Singbox([], []), $uuid, $server);
        $this->assertSame(['enabled' => true, 'query_server_name' => 'cloudflare-ech.com'], $result['tls']['ech']);
    }

    public function testMixedSubscriptionRetainsNormalAndValidEchNodesAndReportsBrokenNode()
    {
        $pair = Helper::generateEchKeyPair('public.example');
        $normal = $this->server('vmess', []);
        $normal['id'] = 1;
        $normal['name'] = 'Normal A';
        $other = $normal;
        $other['id'] = 2;
        $other['name'] = 'Normal B';
        $custom = $this->server('vmess', ['ech' => 'custom', 'ech_config' => $pair['ech_config']]);
        $custom['id'] = 3;
        $custom['name'] = 'Custom ECH';
        $broken = $custom;
        $broken['id'] = 4;
        $broken['name'] = 'Broken ECH';
        $broken['tls_settings']['ech_config'] = 'invalid';
        $broken['tlsSettings'] = $broken['tls_settings'];
        $skipped = [];
        $servers = Helper::filterInvalidEchServers([$normal, $other, $custom, $broken], $skipped);
        $this->assertSame(['vmess:4'], $skipped);
        $user = ['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 0, 'd' => 0, 'transfer_enable' => 1000000, 'expired_at' => null];
        foreach ([Singbox::class, SingboxOld::class] as $class) {
            $response = (new $class($user, $servers))->handle();
            $config = json_decode($response->getContent(), true);
            $nodes = array_values(array_filter($config['outbounds'], function ($outbound) {
                return $outbound['type'] === 'vmess';
            }));
            $this->assertSame(['Normal A', 'Normal B', 'Custom ECH'], array_column($nodes, 'tag'));
            $this->assertArrayNotHasKey('ech', $nodes[0]['tls']);
            $this->assertSame($pair['ech_config'], Helper::echConfigBase64($nodes[2]['tls']['ech']['config']));
            $this->assertArrayNotHasKey('ech_key', $nodes[2]['tls']['ech']);
        }
    }
}
