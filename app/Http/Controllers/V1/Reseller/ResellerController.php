<?php

namespace App\Http\Controllers\V1\Reseller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Plan;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Models\Order;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\ServerGroup;
use App\Models\CommissionLog;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderFetch;

class ResellerController extends Controller
{
    // User
    private function userFilter(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $user = User::where('email', $filter['condition'], $filter['value'])->first();
                    $inviteUserId = isset($user->id) ? $user->id : 0;
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                if ($filter['key'] === 'group_id') {
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function userFetch(UserFetch $request)
    {
        $user = User::find($request->user['id']);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        $this->userFilter($request, $userModel);
        $userModel->where('group_id', $user->group_id);
        $userModel->where('id', '!=', $user->id);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $plan = Plan::where('group_id', $user->group_id)->get();
        // $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
            //统计在线设备
            $countalive = 0;
            $ips = [];
            $ips_array = Cache::get('ALIVE_IP_USER_'. $res[$i]['id']);
            if ($ips_array) {
                $countalive = $ips_array['alive_ip'];
                foreach($ips_array as $nodetypeid => $data) {
                    if (!is_int($data) && isset($data['aliveips'])) {
                        foreach($data['aliveips'] as $ip_NodeId) {
                            $ip = explode("_", $ip_NodeId)[0];
                            $ips[] = $ip . '_' . $nodetypeid;
                        }
                    }
                }
            }
            $res[$i]['alive_ip'] = $countalive;
            $res[$i]['ips'] = implode(', ', $ips);
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function userGenerate(UserGenerate $request)
    {
        $resellerUser = User::find($request->user['id']);
        if ($request->input('email_prefix')) {
            if ($request->input('plan_id')) {
                $plan = Plan::find($request->input('plan_id'));
                if (!$plan) {
                    abort(500, '订阅计划不存在');
                }
            }
            $user = [
                'email' => $request->input('email_prefix') . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : $resellerUser->group_id,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 1 * 1073741824,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : 1,
                'expired_at' => isset($plan->days) ? time() + ($plan->days * 24 * 60 * 60) : time() + (1 * 24 * 60 * 60),
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            if (User::where('email', $user['email'])->first()) {
                abort(500, '邮箱已存在于系统中');
            }
            $user['password'] = password_hash(Helper::randomChar(16, true), PASSWORD_DEFAULT);
            if (!User::create($user)) {
                abort(500, '生成失败');
            }

            if ($request->input('plan_id')){
                $this->setManualOrder($user['email'], $plan->id);
            }

            return response([
                'data' => true
            ]);
        }

        if ($request->input('generate_count')) {
            $this->userMultiGenerate($request, $resellerUser);
        }
    }

    private function userMultiGenerate(Request $request, User $resellerUser)
    {
        if ($request->input('plan_id')) {
            $plan = Plan::find($request->input('plan_id'));
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
        }
        $users = [];
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $user = [
                'email' => Helper::randomChar(6) . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : $resellerUser->group_id,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 1 * 1073741824,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : 1,
                'expired_at' => isset($plan->days) ? time() + ($plan->days * 24 * 60 * 60) : time() + (1 * 24 * 60 * 60),
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $user['password'] = password_hash(Helper::randomChar(16, true), PASSWORD_DEFAULT);
            array_push($users, $user);
        }
        DB::beginTransaction();
        if (!User::insert($users)) {
            DB::rollBack();
            abort(500, '生成失败');
        }
        DB::commit();
        $data = "账号,密码,过期时间,UUID,创建时间,订阅地址\r\n";
        foreach($users as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $createDate = date('Y-m-d H:i:s', $user['created_at']);
            $password = $user['password'];
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);

            if ($request->input('plan_id')){
                $this->setManualOrder($user['email'], $plan->id);
            }

            $data .= "{$user['email']},{$password},{$expireDate},{$user['uuid']},{$createDate},{$subscribeUrl}\r\n";
        }
        echo $data;
    }

    public function userBan(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function userResetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, '用户不存在');
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save()
        ]);
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $user = User::find($request->input('id'));
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }
        return response([
            'data' => $user
        ]);
    }

    // Order
    private function orderFilter(Request $request, &$builder)
    {
        if ($request->input('filter')) {
            foreach ($request->input('filter') as $filter) {
                if ($filter['key'] === 'email') {
                    $user = User::where('email', "%{$filter['value']}%")->first();
                    if (!$user) continue;
                    $builder->where('user_id', $user->id);
                    continue;
                }
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function orderDetail(Request $request)
    {
        $order = Order::find($request->input('id'));
        if (!$order) abort(500, '订单不存在');
        $order['commission_log'] = CommissionLog::where('trade_no', $order->trade_no)->get();
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function orderFetch(OrderFetch $request)
    {
        $user = User::find($request->user['id']);
        $plan = Plan::where('group_id', $user->group_id)->get();
        $planIds = $plan->pluck('id')->toArray();
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $orderModel = Order::orderBy('created_at', 'DESC');
        if ($request->input('is_commission')) {
            $orderModel->where('invite_user_id', '!=', NULL);
            $orderModel->whereNotIn('status', [0, 2]);
            $orderModel->where('commission_balance', '>', 0);
        }
        $this->orderFilter($request, $orderModel);
        $orderModel->whereIn('plan_id', $planIds);
        $total = $orderModel->count();
        $res = $orderModel->forPage($current, $pageSize)
            ->get();
        // $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function orderAssign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::where('email', $request->input('email'))->first();

        if (!$user) {
            abort(500, '该用户不存在');
        }

        if (!$plan) {
            abort(500, '该订阅不存在');
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            abort(500, 'There is a pending order, please wait for 1 minute');
        }

        DB::beginTransaction();
        $order = new Order();
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::guid();
        $order->total_amount = 0;

        // Update user
        // $user->plan_id = $plan->id;
        // $user->group_id = $plan->group_id;
        // $user->transfer_enable = $plan->transfer_enable * 1073741824;
        // $user->u = 0;
        // $user->d = 0;
        // $user->device_limit = $plan->device_limit;
        // $user->speed_limit = $plan->speed_limit;

        // $timestamp = time();
        // $user->expired_at = match ($order->period) {
        //     'month_price' => strtotime('+1 month', $timestamp),
        //     'two_month_price' => strtotime('+2 month', $timestamp),
        //     'quarter_price' => strtotime('+3 month', $timestamp),
        //     default => $timestamp
        // };
        // $user->expired_at = time() + ($plan->days * 24 * 60 * 60);

        if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
            $order->type = 3;
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = 2;
        } else {
            $order->type = 1;
        }

        $order->status = 1;

        if (!$order->save()) {
            DB::rollback();
            abort(500, '订单创建失败');
        }

        // if (!$user->save()) {
        //     DB::rollback();
        //     abort(500, '订单创建失败');
        // }

        DB::commit();

        return response([
            'data' => $order->trade_no
        ]);
    }

    private function setManualOrder($userEmail, $planId)
    {
        $user = User::where('email', $userEmail)->first();
        DB::beginTransaction();
        $order = new Order();
        $order->user_id = $user->id;
        $order->plan_id = $planId;
        $order->period = 'onetime_price';
        $order->trade_no = Helper::guid();
        $order->total_amount = 0;

        if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
            $order->type = 3;
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = 2;
        } else {
            $order->type = 1;
        }

        $order->status = 3;

        if (!$order->save()) {
            DB::rollback();
            abort(500, '订单创建失败');
        }

        DB::commit();
    }

    // Plan
    public function planFetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('group_id', $user->group_id)->orderBy('sort', 'ASC')->get();
        foreach ($plans as $k => $v) {
            $plans[$k]->count = 0;
            foreach ($counts as $kk => $vv) {
                if ($plans[$k]->id === $counts[$kk]->plan_id) $plans[$k]->count = $counts[$kk]->count;
            }
        }
        return response([
            'data' => $plans
        ]);
    }

    // Server
    public function serverGroupFetch(Request $request)
    {
        $user = User::find($request->user['id']);
        // if ($request->input('group_id')) {
            return response([
                'data' => [ServerGroup::find($user->group_id)]
            ]);
        // }
        // $serverGroups = ServerGroup::get();
        // $serverService = new ServerService();
        // $servers = $serverService->getAllServers();
        // foreach ($serverGroups as $k => $v) {
        //     $serverGroups[$k]['user_count'] = User::where('group_id', $v['id'])->count();
        //     $serverGroups[$k]['server_count'] = 0;
        //     foreach ($servers as $server) {
        //         if (in_array($v['id'], $server['group_id'])) {
        //             $serverGroups[$k]['server_count'] = $serverGroups[$k]['server_count']+1;
        //         }
        //     }
        // }
        // return response([
        //     'data' => $serverGroups
        // ]);
    }
}
