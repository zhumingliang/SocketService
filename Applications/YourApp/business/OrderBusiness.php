<?php

namespace app\business;

class OrderBusiness
{
    public function checkWebSocketReceive($redis, $websocketCode)
    {
        $set = "webSocketReceiveCode";
        $redis->srem($set, $websocketCode);

    }

}