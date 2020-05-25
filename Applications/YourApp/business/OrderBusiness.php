<?php

namespace app\business;

class OrderBusiness
{
    public function checkWebSocketReceive($redis, $websocketCode)
    {
        $set = "webSocketReceiveCode";
        $redis->srem($set, $websocketCode);
    }

    public function orderStatusHandel($db, $orderId, $code, $codeType)
    {

        $errMsg = [
            'take' => '取餐失败，二维码不正确',
            'ready' => '备餐失败，二维码不正确'
        ];
        $order = $db->select('id,ready_code,take_code,take,ready')->
        from('canteen_order_t')->where('id= :orderId')
            ->bindValues(array('orderId' => $orderId))->row();
        if (empty($order)) {
            return [
                'errorCode' => 12000,
                'msg' => "订单不存在"
            ];
        }
        if ($order["$codeType" . '_code'] != $code) {
            return [
                'errorCode' => 12001,
                'msg' => $errMsg[$codeType]
            ];
        }
        if ($order[$codeType] == 1) {
            return [
                'errorCode' => 12002,
                'msg' => "状态已经修改，无需重复操作"
            ];
        }

        $row_count = $db->update('canteen_order_t')->cols(array($codeType))
            ->where('id=', $orderId)
            ->bindValue($codeType, 1)->query();
        if (!$row_count) {
            return [
                'errorCode' => 12003,
                'msg' => "更新状态失败"
            ];
        }
        return [
            'errorCode' => 0,
            'msg' => "success"
        ];
    }
}