<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */

//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{


    /**
     * 新建一个类的静态成员，用来保存数据库实例
     */
    public static $db = null;
    public static $redis = null;
    public static $http = null;


    /**
     * 进程启动后初始化数据库连接
     */
    public static function onWorkerStart($worker)
    {
        $mysql = DataBase::mysql();
        $redisConfig = DataBase::redis();
        self::$db = new \Workerman\MySQL\Connection($mysql['hostname'],
            $mysql['hostport'], $mysql['username'], $mysql['password'], $mysql['database']);
        /*  self::$db = new \Workerman\MySQL\Connection('55a32a9887e03.gz.cdb.myqcloud.com',
                    '16273', 'cdb_outerroot', 'Libo1234', 'canteen');*/

        self::$redis = new Redis();
        self::$redis->connect($redisConfig['host'], $redisConfig['port'], 60);
        self::$redis->auth($redisConfig['auth']);

        self::$http = new \Workerman\Http\Client();
        if ($worker->id === 0) {
            $time_interval = 60 * 60 * 2;
            // $time_interval = 60;
            \Workerman\Lib\Timer::add($time_interval, function () use ($worker) {
                self::handelOrderUnTake();
            });
        }


    }

    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     *
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {

        $data = [
            'errorCode' => 0,
            'msg' => 'success',
            'type' => 'init',
            'data' => [
                'client_id' => $client_id
            ]

        ];
        Gateway::sendToClient($client_id, json_encode($data));
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message)
    {

        try {
            $message = json_decode($message, true);
            if ($message['type'] == "test") {
                self::test($client_id);
            }
            $cache = self::checkMessage($client_id, $message);
            if (!$cache) {
                return false;
            }
            $company_id = $cache['company_id'];
            $canteen_id = $cache['belong_id'];
            $showCode = $cache['sort_code'];
            $type = $message['type'];
            self::saveConsumptionLog(json_encode($message));
            switch ($type) {
                case "canteen"://处理饭堂消费
                    $code = $message['code'];
                    self::prefixCanteen($client_id, $code, $company_id, $canteen_id, $showCode);
                    break;
                case "sort"://接受确认消费排序消费
                    $webSocketCode = $message['websocketCode'];
                    self::checkWebSocketReceive($webSocketCode);
                    break;
                case "sortHandel"://处理确认就餐状态码
                    self::prefixSortHandel($client_id, $message);
                    break;
                case "clearSort"://处理确认就餐状态异常订单
                    self::clearSort($client_id, $message['data']);
                case "reception"://确认就餐
                    self::prefixReception($client_id, $message['code']);
                    break;
                case "test":
                    self::test($client_id);
                    break;
            }
        } catch (Exception $e) {
            self::returnData($client_id, 3, $e->getMessage(), 'canteen', []);
        }
    }

    public static function test($client_id)
    {

        self::$redis->set('name', 'zml');
        $name = self::$redis->get('name');
        Gateway::sendToClient($client_id, $name);
    }

    public static function prefixSortHandel($client_id, $message)
    {
        if (empty($message['orderId']) || empty($message['code']) || empty($message['codeType'])) {
            self::returnData($client_id, 11001, '参数异常，请检查', 'sortHandel', []);
        }
        if (!in_array($message['codeType'], ['take', 'ready'])) {
            self::returnData($client_id, 11001, '操作参数异常，请检查', 'sortHandel', []);
        }

        $checkHandel = self::orderStatusHandel($message['orderId'], $message['code'], $message['codeType']);
        self::returnData($client_id, $checkHandel['errorCode'], $checkHandel['msg'], 'sortHandel',
            ['orderId' => $message['orderId'], 'codeType' => $message['codeType']]);
    }

    private static function prefixCanteen($client_id, $code, $company_id, $canteen_id, $showCode)
    {
        $check = self::$redis->get($code);
        if ($check) {
            self::returnData($client_id, 11001, '8秒内不能重复刷卡', 'canteen', []);
            return;
        }
        $face = empty($message['face']) ? 2 : $message['face'];
        $returnData = self::canteenConsumption($company_id, $canteen_id, $code, $face, $showCode);
        self::returnData($client_id, $returnData['errorCode'], $returnData['msg'], 'canteen', $returnData['data']);
        self::$redis->set($code, $canteen_id, 8);
    }

    //检测数据合法性
    private static function checkMessage($client_id, $message)
    {
        if (empty($message['token']) || empty($message['type'])) {
            if ($message['type'] == "jump") {
                return false;
            }
            self::returnData($client_id, 10000, '数据格式异常', 'canteen', []);
            return false;
        }
        $token = $message['token'];
        $cache = self::$redis->get($token);
        $cache = json_decode($cache, true);
        if (empty($cache) || empty($cache['company_id']) || empty($cache['belong_id'])) {
            self::returnData($client_id, 10001, 'Token已过期或无效Token', 'canteen', []);
            return false;
        }
        return $cache;
    }

    private static function canteenConsumption($company_id, $canteen_id, $code, $face, $showCode)
    {

        if ($face == 1) {
            $sql = "call canteenFaceConsumption(%s,%s,'%s', @currentOrderID,@currentConsumptionType,@resCode,@resMessage,@returnBalance,@returnDinner,@returnDepartment,@returnUsername,@returnPrice,@returnMoney,@returnCount)";

        } else {
            $sql = "call canteenConsumption(%s,%s,'%s', @currentOrderID,@currentConsumptionType,@resCode,@resMessage,@returnBalance,@returnDinner,@returnDepartment,@returnUsername,@returnPrice,@returnMoney,@returnCount)";
        }
        $sql = sprintf($sql, $company_id, $canteen_id, $code);
        $sql2 = "select @currentOrderID,@currentConsumptionType,@resCode,@resMessage,@returnBalance,@returnDinner,@returnDepartment,@returnUsername,@returnPrice,@returnMoney,@returnCount";
        self::$db->query($sql);
        $resultSet = self::$db->query($sql2);
        $errorCode = $resultSet[0]['@resCode'];
        $resMessage = $resultSet[0]['@resMessage'];
        $consumptionType = $resultSet[0]['@currentConsumptionType'];
        $orderID = $resultSet[0]['@currentOrderID'];
        $balance = $resultSet[0]['@returnBalance'];
        $dinner = $resultSet[0]['@returnDinner'];
        $department = $resultSet[0]['@returnDepartment'];
        $username = $resultSet[0]['@returnUsername'];
        $price = $resultSet[0]['@returnPrice'];
        $money = $resultSet[0]['@returnMoney'];
        $count = $resultSet[0]['@returnCount'];
        if (is_null($errorCode)) {
            return [
                'errorCode' => 11000,
                'msg' => "系统异常"
            ];
        }
        if ($errorCode > 0) {
            return [
                'errorCode' => $errorCode,
                'msg' => $resMessage
            ];
        }
        //更新订单排队等信息
        $sortCode = 0;
        if ($showCode == 1) {
            $sortCode = self::prefixSort($company_id, $canteen_id, $dinner, $orderID);
            //发送打印机
            self::sendPrinter($canteen_id, $orderID, $sortCode);
        }

        $remark = $consumptionType == 1 ? "订餐消费" : "未订餐消费";
        $returnData = [
            'errorCode' => 0,
            'msg' => "success",
            'data' => [
                'create_time' => date('Y-m-d H:i:s'),
                'dinner' => $dinner,
                'price' => $price,
                'money' => $money,
                'count' => $count,
                'department' => $department,
                'username' => $username,
                'type' => $consumptionType,
                'balance' => $balance,
                'remark' => $remark,
                'sortCode' => $sortCode,
                'showCode' => $showCode,
                'products' => self::getOrderProducts($orderID, $consumptionType)
            ]
        ];
        return $returnData;

    }

    private static function getOrderProducts($order_id, $consumptionType)
    {

        $products = array();
        if ($consumptionType == 2) {
            return $products;
        }
        $products = self::$db->select('f_id as food_id ,count,name,price')->
        from('canteen_order_detail_t')->where('o_id= :order_id AND state = :state')
            ->bindValues(array('order_id' => $order_id, 'state' => 1))->query();
        return $products;

    }

    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     */
    public static function onClose($client_id)
    {
        // 向所有人发送
        //GateWay::sendToAll("$client_id logout\r\n");
        /* self::$db->insert('drive_socket_closed_t')->cols(
             array(
                 'create_time' => date('Y-m-d H:i:s'),
                 'update_time' => date('Y-m-d H:i:s'),
                 'client_id' => $client_id,
                 'u_id' => self::checkOnline($client_id)
             )
         )->query();*/
    }

    public static function saveLog($content)
    {
        $data = array(
            'content' => $content,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        );
        self::$db->insert('canteen_log_t')->cols($data)->query();
    }

    public static function saveConsumptionLog($content)
    {
        $data = array(
            'content' => $content,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        );
        self::$db->insert('canteen_consumption_log_t')->cols($data)->query();
    }

    public static function returnData($client_id, $errorCode, $msg, $type, $data)
    {
        if (empty($data)) {
            $data = [
                'create_time' => date('Y-m-d H:i:s')
            ];
        }
        $data = [
            'errorCode' => $errorCode,
            'msg' => $msg,
            'type' => $type,
            'data' => $data
        ];
        Gateway::sendToClient($client_id, json_encode($data));
    }

    public static function insertJumpLog($content)
    {
        $content = json_encode($content);
        self::$db->insert('canteen_consumption_jump_log_t')->cols(
            array(
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'content' => $content,
            )
        )->query();
    }

    public static function checkWebSocketReceive($websocketCode)
    {
        $set = "webSocketReceiveCode";
        self::$redis->srem($set, $websocketCode);
    }

    public static function orderStatusHandel($orderId, $code, $codeType)
    {
        try {
            $errMsg = [
                'take' => '取餐失败，二维码不正确',
                'ready' => '备餐失败，二维码不正确'
            ];
            $order = self::$db->select('id,ready_code,take_code,take,ready')->
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

            /*   $row_count = self::$db->update('canteen_order_t')->cols(array($codeType))
                   ->where('id=', $orderId)
                   ->bindValue($codeType, 1)->query();*/

            $row_count = self::$db->update('canteen_order_t')->cols(array($codeType => 1))->where('id=' . $orderId)->query();
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
        } catch (\Exception $e) {
            return [
                'errorCode' => 12004,
                'msg' => $e->getMessage()
            ];
        }
    }

    /**
     * 处理确认消费订单-已确认但是未取餐和未备餐（超出就餐时间）
     */
    public static function handelOrderUnTake()
    {
        //获取所有确认消费但未备餐或者未取餐订单
        $orders = self::$db->select('canteen_order_t.id,canteen_order_t.d_id,canteen_order_t.ordering_date,canteen_dinner_t.meal_time_end')->
        from('canteen_order_t')->leftjoin('canteen_dinner_t', 'canteen_order_t.d_id=canteen_dinner_t.id')
            ->where('canteen_order_t.wx_confirm = 1 and  canteen_order_t.take=2')
            ->query();
        if (!count($orders)) {
            return true;
        }
        $idArr = [];
        foreach ($orders as $k => $v) {
            $end_time = $v['ordering_date'] . ' ' . $v['meal_time_end'];
            if (time() > strtotime($end_time)) {
                array_push($idArr, $v['id']);
            }
        }

        $data = [
            'errorCode' => 0,
            'msg' => "success",
            'type' => "clearSort",
            'data' => $idArr
        ];
        Gateway::sendToAll(json_encode($data));
    }

    public function clearSort($client_id, $ids)
    {
        $updateData = [
            'ready' => 1,
            'take' => 1
        ];
        $idArr = explode(',', $ids);
        if (count($idArr)) {
            foreach ($idArr as $k => $v) {
                self::$db->update('canteen_order_t')->cols($updateData)
                    ->where('id=' . $v)
                    ->query();
            }
        }

    }

    public static function getRandChar($length)
    {
        $str = null;
        $strPol = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz";
        $max = strlen($strPol) - 1;

        for ($i = 0;
             $i < $length;
             $i++) {
            $str .= $strPol[rand(0, $max)];
        }

        return $str;
    }

    public static function saveRedisOrderCode($canteen_id, $dinner_id)
    {
        $day = date('Y-m-d');
        $key = "$canteen_id:$dinner_id:$day";
        $code = self::$redis->get($key);
        if (!$code) {
            $code = 1;
            self::$redis->set($key, $code, 60 * 60 * 24);
        }
        self::$redis->incr($key);
        return str_pad($code, 4, "0", STR_PAD_LEFT);
    }

    private static function prefixSort($company_id, $canteen_id, $dinner_id, $order_id)
    {
        $readyCode = self::getRandChar(8);
        $takeCode = self::getRandChar(8);
        $sortCode = self::saveRedisOrderCode($canteen_id, $dinner_id);
        $row_count = self::$db->update('canteen_order_t')
            ->cols(array('sort_code' => $sortCode,
                'ready_code' => $readyCode,
                'take_code' => $takeCode,
                'qrcode_url' => "$order_id&$readyCode&$takeCode",
                "confirm_time" => date('Y-m-d H:i:s')
            ))
            ->where("id=$order_id")
            ->query();
        return $sortCode;
    }

    private static function sendPrinter($canteenID, $orderID, $sortCode)
    {
        $params = [
            'canteenID' => $canteenID,
            'orderID' => $orderID,
            'sortCode' => $sortCode
        ];
        $config = Config::param();
        $domain = $config['domain'];
        $rule = "$domain/api/v1/service/printer";
        self::$http->post($rule, $params, function ($response) {
            self::saveLog("打印成功:" . $response->getBody());
        }, function ($exception) {
            self::saveLog("打印失败：" . $exception);

        });
    }

    public static function prefixReception($client_id, $code)
    {
        $reception = self::$db->select('id,code,status')->
        from('canteen_reception_qrcode_t')->where("code=$code")
            ->query();
        if (!$reception) {
            $data = [
                'errorCode' => 12100,
                'msg' => "接待票不存在"
            ];
            Gateway::sendToClient($client_id, json_encode($data));
            return '';
        }

        if ($reception[0]['status'] == 1) {
            $data = [
                'errorCode' => 12101,
                'msg' => "接待票已使用"
            ];
            Gateway::sendToClient($client_id, json_encode($data));
            return '';
        }

        $row_count = self::$db->update('canteen_reception_qrcode_t')
            ->cols(array('status' => 1, 'used_time' => date('Y-m-d H:i:s')))
            ->where('code=' . $code)->query();
        if (!$row_count) {
            $data = [
                'errorCode' => 12003,
                'msg' => "更新状态失败"
            ];
            Gateway::sendToClient($client_id, json_encode($data));
            return '';
        }
        $data = [
            'errorCode' => 0,
            'type' => "reception",
            'msg' => "success"
        ];
        Gateway::sendToClient($client_id, json_encode($data));

    }


}
