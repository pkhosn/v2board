<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\User\UserTransfer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommissionTransferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('commission_balance');
            $table->integer('balance');
            $table->integer('invite_user_id')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            foreach (['user_id', 'plan_id', 'total_amount', 'type', 'status', 'surplus_amount', 'created_at', 'updated_at'] as $column) {
                $table->integer($column);
            }
            $table->integer('invite_user_id')->nullable();
            $table->string('period');
            $table->string('trade_no');
            $table->string('callback_no');
        });
        DB::table('v2_user')->insert(['id' => 1, 'commission_balance' => 100, 'balance' => 20]);
    }

    protected function tearDown(): void
    {
        User::flushEventListeners();
        Order::flushEventListeners();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function transfer($amount)
    {
        $request = UserTransfer::create('/api/v1/user/transfer', 'POST', ['transfer_amount' => $amount]);
        $request->user = ['id' => 1];
        return (new UserController())->transfer($request);
    }

    private function assertBalances($commission, $balance, $orders)
    {
        $user = DB::table('v2_user')->where('id', 1)->first();
        $this->assertSame($commission, (int)$user->commission_balance);
        $this->assertSame($balance, (int)$user->balance);
        $this->assertSame($orders, DB::table('v2_order')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertTransferFails($amount)
    {
        try {
            $this->transfer($amount);
            $this->fail('Transfer should have failed');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }
    }

    public function testSuccessfulTransferAndInsufficientRemainingBalance()
    {
        $this->assertTrue(json_decode($this->transfer('60')->getContent(), true)['data']);
        $this->assertBalances(40, 80, 1);
        $order = DB::table('v2_order')->first();
        $this->assertSame(60, (int)$order->surplus_amount);
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(3, (int)$order->status);
        $this->assertTransferFails(60);
        $this->assertBalances(40, 80, 1);
    }

    public function testStaleUserSnapshotCannotOverdrawCommission()
    {
        User::retrieved(function ($user) {
            if ($user->id === 1) {
                DB::table('v2_user')->where('id', 1)->update(['commission_balance' => 40, 'balance' => 80]);
            }
        });
        $this->assertTransferFails(60);
        $this->assertBalances(40, 80, 0);
    }

    public function testStaleUserSnapshotDoesNotOverwriteAnotherBalanceCredit()
    {
        User::retrieved(function ($user) {
            if ($user->id === 1) {
                DB::table('v2_user')->where('id', 1)->update(['balance' => 70]);
            }
        });
        $this->assertTrue(json_decode($this->transfer(60)->getContent(), true)['data']);
        $this->assertBalances(40, 130, 1);
    }

    public function testOrderSaveFailureRollsBackBothBalances()
    {
        Order::saving(function () {
            return false;
        });
        $this->assertTransferFails(60);
        $this->assertBalances(100, 20, 0);
    }
}
