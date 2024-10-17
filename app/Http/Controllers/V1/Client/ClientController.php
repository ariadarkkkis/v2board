<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if (!strpos($flag, 'sing')) {
                $this->setSubscribeInfoToServers($servers, $user);
            }
            if ($flag) {
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        return $class->handle();
                    }
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        $infoServerDetail = [
            "name" => "",
            "network" => "tcp",
            "host" => "edge.microsoft.com",
            "port" => "80",
            "security" => "none",
            "tls" => 0,
            "flow" => "",
            "type" => "vless"
        ];
        // $infoServer = Helper::buildUri($user->uuid, $infoServerDetail);
        array_push($servers, array_merge($infoServerDetail, [
            'name' => "Expire: {$expiredDate}",
        ]));
        if ($resetDay) {
            array_push($servers, array_merge($infoServerDetail, [
                'name' => "Reset day: {$resetDay} 天",
            ]));
        }
        array_push($servers, array_merge($infoServerDetail, [
            'name' => "Remaining：{$remainingTraffic}",
        ]));

        array_push($servers, array_merge($infoServerDetail, [
            'name' => "UserID：{$user->id}",
        ]));
    }
}
