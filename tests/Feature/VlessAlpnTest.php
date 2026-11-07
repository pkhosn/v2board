<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\Server\VlessController;
use App\Http\Controllers\V1\Admin\Server\V2nodeController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\ServerVless;
use App\Models\ServerV2node;
use App\Protocols\ClashMeta;
use App\Protocols\ClashVerge;
use App\Protocols\ClashNyanpasu;
use App\Protocols\Stash;
use App\Protocols\QuantumultX;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VlessAlpnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['v2_server_vless', 'v2_server_v2node'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->increments('id');
                foreach (['group_id', 'route_id', 'name', 'host', 'port', 'server_port', 'tls', 'tls_settings', 'flow', 'network', 'network_settings', 'rate', 'protocol', 'disable_sni', 'zero_rtt_handshake'] as $column) {
                    $table->text($column)->nullable();
                }
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function server()
    {
        return [
            'type' => 'vless', 'name' => 'ALPN test', 'host' => 'node.example', 'port' => 443,
            'server_port' => 443, 'tls' => 1, 'flow' => '', 'network' => 'tcp',
            'group_id' => [1], 'rate' => 1, 'network_settings' => [],
            'tls_settings' => ['server_name' => 'node.example', 'alpn' => ['h3']],
        ];
    }

    private function save($server, $unified = false)
    {
        if ($unified) {
            $server += ['protocol' => 'vless', 'disable_sni' => 0, 'zero_rtt_handshake' => 0];
        }
        $request = Request::create('/save', 'POST', $server);
        return $unified ? (new V2nodeController())->save($request) : (new VlessController())->save($request);
    }

    public function testSaveReadClearAndAuthenticatedNodeApiPreserveAlpn()
    {
        foreach ([false, true] as $unified) {
            $this->save($this->server(), $unified);
            $model = $unified ? ServerV2node::first() : ServerVless::first();
            $this->assertSame(['h3'], $model->tls_settings['alpn']);
            $this->assertSame('node.example', $model->tls_settings['server_name']);
            if (!$unified) {
                config(['v2board.server_token' => 'local-test-node-token']);
                $request = Request::create('/config', 'GET', ['node_type' => 'vless', 'node_id' => $model->id, 'token' => 'local-test-node-token']);
                $response = (new UniProxyController($request))->config($request);
                $this->assertSame(['h3'], $response->getOriginalContent()['tls_settings']['alpn']);
            }
            $server = $this->server();
            $server['id'] = $model->id;
            $server['tls_settings']['alpn'] = [];
            $this->save($server, $unified);
            $this->assertSame([], $model->fresh()->tls_settings['alpn']);
        }
    }

    public function testSaveRejectsInvalidAlpnOnBothEndpoints()
    {
        foreach ([false, true] as $unified) {
            foreach (['h3', null, [3], [''], [str_repeat('a', 256)], ["h3\n"]] as $invalid) {
                $server = $this->server();
                $server['tls_settings']['alpn'] = $invalid;
                try {
                    $this->save($server, $unified);
                    $this->fail('Invalid ALPN accepted: ' . json_encode($invalid));
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
    }

    public function testShareLinkEncodingAndEmptyOrDisabledTlsBehavior()
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $server = $this->server();
        foreach ([['h3'], ['h2', 'http/1.1']] as $protocols) {
            $server['tls_settings']['alpn'] = $protocols;
            $uri = Helper::buildVlessUri($uuid, $server);
            parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
            $this->assertSame(implode(',', $protocols), $query['alpn']);
            $this->assertStringContainsString('alpn=' . urlencode(implode(',', $protocols)), $uri);
        }
        $server['tls_settings']['alpn'] = [];
        $withEmpty = Helper::buildVlessUri($uuid, $server);
        unset($server['tls_settings']['alpn']);
        $this->assertSame($withEmpty, Helper::buildVlessUri($uuid, $server));
        $server['tls_settings']['alpn'] = ['h3'];
        $server['tls'] = 0;
        $this->assertStringNotContainsString('alpn=', Helper::buildVlessUri($uuid, $server));
    }

    public function testSupportedSubscriptionsCarryExplicitArraysAndNoDefaults()
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        foreach ([['h3'], ['h2', 'http/1.1'], []] as $protocols) {
            $server = $this->server();
            $server['tls_settings']['alpn'] = $protocols;
            foreach ([ClashMeta::class, ClashVerge::class, ClashNyanpasu::class, Stash::class] as $class) {
                $node = $class::buildVless($uuid, $server);
                $this->assertSame($protocols ?: null, $node['alpn'] ?? null);
            }
            foreach ([Singbox::class, SingboxOld::class] as $class) {
                $build = new \ReflectionMethod($class, 'buildVless');
                $build->setAccessible(true);
                $node = $build->invoke(new $class([], []), $uuid, $server);
                $this->assertSame($protocols ?: null, $node['tls']['alpn'] ?? null);
            }
            $qx = QuantumultX::buildVless($uuid, $server);
            if ($protocols) {
                $hex = '';
                foreach ($protocols as $protocol) $hex .= bin2hex(chr(strlen($protocol)) . $protocol);
                $this->assertStringContainsString('tls-alpn=' . $hex, $qx);
            } else {
                $this->assertStringNotContainsString('tls-alpn=', $qx);
            }
        }
    }
}
