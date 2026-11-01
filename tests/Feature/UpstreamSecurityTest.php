<?php

namespace Tests\Feature;

use App\Http\Middleware\Client;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Payments\Epusdt;
use App\Payments\Paytaro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UpstreamSecurityTest extends TestCase
{
    public function testEmailValidationRejectsUnicodeAndNonStringValues()
    {
        foreach ([new AuthForget(), new AuthRegister(), new CommSendEmailVerify()] as $request) {
            $rules = ['email' => $request->rules()['email']];
            $this->assertTrue(Validator::make(['email' => 'user@gmail.com'], $rules)->passes());
            foreach (['user@gmáil.com', 'usér@gmail.com', ['user@gmail.com'], str_repeat('a', 65) . '@gmail.com'] as $email) {
                $this->assertTrue(Validator::make(['email' => $email], $rules)->fails());
            }
        }
    }

    public function testArraySubscriptionTokenIsRejectedBeforeDatabaseLookup()
    {
        config(['v2board.show_subscribe_method' => 0]);
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => ['unexpected']]);
        try {
            (new Client())->handle($request, function () {
                $this->fail('Invalid token reached subscription controller');
            });
            $this->fail('Invalid token was accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testPaymentCallbacksRequireNonEmptySecretsAndValidSignatures()
    {
        foreach ([Epusdt::class, Paytaro::class] as $class) {
            $epusdt = $class === Epusdt::class;
            $params = $epusdt
                ? ['order_id' => 'test-order', 'trade_id' => 'test-payment', 'status' => 2]
                : ['out_trade_no' => 'test-order', 'trade_no' => 'test-payment', 'trade_status' => 'TRADE_SUCCESS'];
            $key = $epusdt ? 'epusdt_token' : 'key';
            $signatureKey = $epusdt ? 'signature' : 'sign';
            ksort($params);
            $pairs = [];
            foreach ($params as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $plain = implode('&', $pairs);
            $params[$signatureKey] = md5($plain);
            $this->assertFalse((new $class([]))->notify($params));
            $this->assertFalse((new $class([$key => 'secret']))->notify($params));
            $params[$signatureKey] = md5($plain . 'secret');
            $result = (new $class([$key => 'secret']))->notify($params);
            $this->assertSame('test-order', $result['trade_no']);
        }
    }
}
