<?php

namespace Tests\Unit;

use App\Utils\Helper;
use PHPUnit\Framework\TestCase;

class EchConfigTest extends TestCase
{
    public function testGeneratedKeysMatchPublicConfig()
    {
        $pair = Helper::generateEchKeyPair('public.example');
        $config = base64_decode($pair['ech_config'], true);
        $keys = base64_decode($pair['ech_key'], true);
        $this->assertSame(strlen($config) - 2, unpack('n', substr($config, 0, 2))[1]);
        $this->assertSame(32, unpack('n', substr($keys, 0, 2))[1]);
        $private = substr($keys, 2, 32);
        $rawConfig = substr($config, 2);
        $this->assertSame(strlen($rawConfig), unpack('n', substr($keys, 34, 2))[1]);
        $this->assertSame($rawConfig, substr($keys, 36));
        $this->assertSame(sodium_crypto_scalarmult_base($private), substr($rawConfig, 9, 32));
        $this->assertSame($pair['ech_config'], Helper::echConfigBase64(base64_encode($rawConfig)));
        $pem = Helper::echConfigPem($pair['ech_config']);
        $this->assertStringContainsString('BEGIN ECH CONFIGS', $pem[0]);
        $this->assertSame($pair['ech_config'], Helper::echConfigBase64($pem));
    }

    public function testInvalidOuterNameIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::generateEchKeyPair('https://invalid.example/path');
    }

    public function testNormalizationPreservesListsAndAllArrayEntries()
    {
        $first = Helper::generateEchKeyPair('public.example')['ech_config'];
        $second = Helper::generateEchKeyPair('other.example')['ech_config'];
        $this->assertSame($first, Helper::echConfigBase64($first));
        $combined = Helper::echConfigBase64([$first, $second]);
        $raw = base64_decode($combined, true);
        $this->assertSame(strlen($raw) - 2, unpack('n', substr($raw, 0, 2))[1]);
        $this->assertSame(substr(base64_decode($first), 2) . substr(base64_decode($second), 2), substr($raw, 2));
        $this->assertSame($combined, Helper::echConfigBase64(Helper::echConfigPem([$first, $second])));
    }

    public function testTruncatedInnerConfigIsRejectedEvenWithCorrectListLength()
    {
        $raw = base64_decode(Helper::generateEchKeyPair('public.example')['ech_config']);
        $raw = substr($raw, 2, -1);
        $this->expectException(\InvalidArgumentException::class);
        Helper::echConfigBase64(base64_encode(pack('n', strlen($raw)) . $raw));
    }

    public function testMalformedConfigIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::echConfigBase64('not valid base64');
    }

    public function testSaveAcceptsLegacyBase64AndCompletePemWithoutRotatingKeys()
    {
        $pair = Helper::generateEchKeyPair('public.example');
        foreach ([$pair['ech_config'], Helper::echConfigPem($pair['ech_config'])[0], base64_encode(substr(base64_decode($pair['ech_config']), 2))] as $config) {
            $settings = Helper::prepareEchSettings([
                'ech' => 'custom', 'ech_server_name' => 'public.example',
                'ech_key' => $pair['ech_key'], 'ech_config' => $config,
            ]);
            $this->assertSame($pair['ech_key'], $settings['ech_key']);
            $this->assertSame($pair['ech_config'], $settings['ech_config']);
        }
    }

    public function testSaveRejectsIncompleteKeyPairInsteadOfRotatingIt()
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::prepareEchSettings(['ech' => 'custom', 'ech_server_name' => 'public.example', 'ech_key' => 'existing-key']);
    }

    public function testIncompletePemIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::echConfigBase64("-----BEGIN ECH CONFIGS-----\n" . Helper::generateEchKeyPair('public.example')['ech_config']);
    }

    public function testSaveRejectsDamagedConfigWithExplicitValidationError()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效的 ECH 配置');
        Helper::prepareEchSettings([
            'ech' => 'custom', 'ech_server_name' => 'public.example',
            'ech_key' => 'existing-private-key', 'ech_config' => 'invalid',
        ]);
    }
}
