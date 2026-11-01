<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class HorizonConfigTest extends TestCase
{
    private function loadConfig($linfo, $limit = null, $restricted = false)
    {
        $root = dirname(__DIR__, 2);
        $code = 'namespace Linfo { class Linfo { ' . $linfo . ' } } namespace { '
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$config = require ' . var_export($root . '/config/horizon.php', true) . '; '
            . 'echo $config["environments"]["local"]["V2board"]["maxProcesses"]; }';
        $command = [PHP_BINARY];
        if ($restricted) {
            $command = array_merge($command, ['-d', 'open_basedir=' . $root . PATH_SEPARATOR . '/tmp/']);
        }
        $command = array_merge($command, ['-r', $code]);
        $process = new Process($command, $root, ['HORIZON_MAX_PROCESSES' => $limit === null ? false : (string)$limit]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        return $process->getOutput();
    }

    public function testRestrictedDirectoriesSkipLinfoAndUseFallback()
    {
        $this->assertSame('6', $this->loadConfig('public function __construct() { echo "unexpected Linfo initialization"; }', null, true));
        $this->assertSame('2', $this->loadConfig('public function __construct() { echo "unexpected Linfo initialization"; }', 2, true));
        $this->assertSame('6', $this->loadConfig('', 20, true));
    }

    public function testSystemInformationFailuresUseFallback()
    {
        $this->assertSame('6', $this->loadConfig('public function __construct() { throw new \RuntimeException("unavailable"); }'));
        $this->assertSame('6', $this->loadConfig('public function getParser() { return $this; } public function getRam() { throw new \RuntimeException("unavailable"); }'));
        $this->assertSame('3', $this->loadConfig('public function getParser() { throw new \Error("unavailable"); }', 3));
    }

    public function testDetectedMemoryRetainsConfiguredAndDefaultCaps()
    {
        $linfo = 'public function getParser() { return $this; } public function getRam() { return ["total" => 64 * 1024 * 1024 * 1024]; }';
        $this->assertSame('128', $this->loadConfig($linfo));
        $this->assertSame('4', $this->loadConfig($linfo, 4));
    }
}
