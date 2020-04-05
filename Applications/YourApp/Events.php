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

    /**
     * 进程启动后初始化数据库连接
     */
    public static function onWorkerStart($worker)
    {
        self::$db = new \Workerman\MySQL\Connection('55a32a9887e03.gz.cdb.myqcloud.com',
            '16273', 'cdb_outerroot', 'Libo1234', 'canteen');

        self::$redis = new Redis();
        self::$redis->connect('127.0.0.1', 6379, 60);


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
        $logData = array(
            'type' => "login",
            'client_id' => $client_id
        );
        self::insertLog($logData);
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
            if (empty($message['token']) || empty($message['type'])) {
                if ($message['type'] == "jump") {
                    self::insertJumpLog($message);
                    return '';
                }
                self::returnData($client_id, 10000, '数据格式异常', 'canteen', []);
                return;
            }
            $token = $message['token'];
            $cache = self::$redis->get($token);
            $cache = json_decode($cache, true);
            if (empty($cache) || empty($cache['company_id']) || empty($cache['belong_id'])) {
                self::returnData($client_id, 10001, 'Token已过期或无效Token', 'canteen', []);
                return;
            }
            $type = $message['type'];
            if ($type == 'canteen') {
                $code = $message['code'];
                $company_id = $cache['company_id'];
                $canteen_id = $cache['belong_id'];
                $returnData = self::canteenConsumption($company_id, $canteen_id, $code);
                self::returnData($client_id, $returnData['errorCode'], $returnData['msg'], 'canteen', $returnData['data']);
            }
        } catch (Exception $e) {
            self::returnData($client_id, 3, $e->getMessage(), 'canteen', []);
        }


    }

    private static function canteenConsumption($company_id, $canteen_id, $code)
    {
        $sql = "call canteenConsumption(%s,%s,'%s', @currentOrderID,@currentConsumptionType,@resCode,@resMessage,@returnBalance,@returnDinner,@returnDepartment,@returnUsername,@returnPrice,@returnMoney)";
        $sql2 = "select @currentOrderID,@currentConsumptionType,@resCode,@resMessage,@returnBalance,@returnDinner,@returnDepartment,@returnUsername,@returnPrice,@returnMoney";
        $sql = sprintf($sql, $company_id, $canteen_id, $code);
        self::$db->query($sql);
        $resultSet = self::$db->query($sql2);
        //记录消费日志
        $consu = [
            'sql' => $sql,
            'res' => $resultSet
        ];
        self::insertLog($consu);
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
        $remark = $consumptionType == 1 ? "订餐消费" : "未订餐消费";
        $returnData = [
            'errorCode' => 0,
            'msg' => "success",
            'data' => [
                'create_time' => date('Y-m-d H:i:s'),
                'dinner' => $dinner,
                'price' => $price,
                'money' => $money,
                'department' => $department,
                'username' => $username,
                'type' => $consumptionType,
                'balance' => $balance,
                'remark' => $remark,
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
        $data = array(
            'type' => 'closed',
            'client_id' => $client_id
        );
        self::insertLog($data);
    }

    public static function saveLog($content)
    {
        self::$db->insert('canteen_log_t')->cols(array(
            'content' => $content))->query();
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
        self::insertLog($data);
        Gateway::sendToClient($client_id, json_encode($data));
    }

    public static function insertLog($content)
    {
        $content = json_encode($content);
        self::$db->insert('canteen_consumption_log_t')->cols(
            array(
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'content' => $content,
            )
        )->query();
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
}
