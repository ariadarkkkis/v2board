<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ResellerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'reseller',
            'middleware' => 'reseller'
        ], function ($router) {
            // User
            $router->get ('/user/fetch', 'V1\\Reseller\\ResellerController@userFetch');
            $router->post('/user/ban', 'V1\\Reseller\\ResellerController@userBan');
            $router->post('/user/resetSecret', 'V1\\Reseller\\ResellerController@userResetSecret');
            $router->post('/user/generate', 'V1\\Reseller\\ResellerController@userGenerate');
            $router->get ('/user/getUserInfoById', 'V1\\Reseller\\ResellerController@getUserInfoById');
            // Order
            $router->get ('/order/fetch', 'V1\\Reseller\\ResellerController@orderFetch');
            $router->post('/order/detail', 'V1\\Reseller\\ResellerController@orderDetail');
            $router->post('/order/assign', 'V1\\Reseller\\ResellerController@orderAssign');
            // Plan
            $router->get ('/plan/fetch', 'V1\\Reseller\\ResellerController@planFetch');
            // Server
            $router->get ('/server/group/fetch', 'V1\\Reseller\\ResellerController@serverGroupFetch');
        });
    }
}

