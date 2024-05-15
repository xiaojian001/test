<?php

namespace app\api\service\ship;


use app\common\model\Order;
use app\common\model\Quota;
use app\common\model\ShipOrderExpand;
use app\otaapi\common\OrderUtil;
use function EasyWeChat\Kernel\Support\get_client_ip;
use function Hprose\Future\value;
use think\Db;
use app\common\service\Sms;
use think\Exception;
use think\facade\Cache;
use app\otaapi\common\CommonUtil;
use think\facade\Request;
use app\common\service\Queue;
use app\common\model\Order as OrderModel;
use app\common\model\Ship as ShipModel;
use app\otaapi\controller\ShipUtils;
class TicketTypeUp extends \app\common\service\Loger
{
    public static function instance(){
        return new TicketTypeUp();
    }
    //获取升票型价格
    public function getUpTicketPrice($intTicketId){
        try {
            if($intTicketId==""){
                throw new Exception('票的编号不存在');
            }
            $arrTicket = Db::name("order_ship_tickets")->where(Array("id"=>$intTicketId))->find();
            if(!$arrTicket){
                throw new Exception('票的编号不正确');
            }
            if($arrTicket['ticket_type']==1 ||$arrTicket['ticket_type']==1354625395564797953){
                throw new Exception('已经是成人票型，不允许升票型。');
            }
            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "cabinId"=>$arrTicket['flight_id'],
                "ticketTypeId"=>$arrTicket['ticket_type'],
            );
            $arrResult = $objShipApi->getCompensationTicketPrice($arrParamApi);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrTicketList = $arrResult['data'];
            $upTicketType = 1;
            if(date("Y",strtotime($arrTicket['use_date']))=="2019" &&$arrTicket['start_port_id']!=18&&$arrTicket['arrive_port_id']!=18){
                $upTicketType = "1190061018774695937";
            }
            if($arrTicket['ticket_type']=="1354625587642949633"){
                //成人半票
                $upTicketType = "1354625395564797953";
            }
            $floFullPrice = 0;
            $strTicketTypeName = "全票";
            $floAdultPrice = 0;
            foreach ($arrTicketList as $v){
                //2020年之前是冬游优惠票型
                if($v['ticketTypeId']==$upTicketType){
                    $floFullPrice = $v['price'];
                    $strTicketTypeName = $v['ticketTypeName'];
                }
                if($v['ticketTypeId']==1){
                    $floAdultPrice = $v['price'];
                }
            }
            //$floFullPrice = number_format($arrResult['data'][0]['price'],2);
            if($floFullPrice<0){
                throw new Exception("获取失败");
            }

            //半票额度
            $order = Db::name("order")->where("id",$arrTicket['order_id'])->find();
            if (!$order) {
                throw new Exception('错误的升舱编号。');
            }
            $strUpMsg = '';
            if($order['is_half'] && $order['quota'] > 0){
                $quota = Quota::balance($order['user_id'],1); //返回值额度
                if(!$quota || $quota < $floFullPrice){
                    //额度不足 升成人票
                    $strTicketTypeName = "全票";
                    $strUpMsg = "注意：半票额度不足{$floFullPrice}出成人半票，确定要升为{$floAdultPrice}全价票吗？";
                    $floFullPrice = $floAdultPrice;
                }
            }

            $arrReturn = Array(
                "old_ticket_type"=>ShipModel::$ticketTypes[$arrTicket['ticket_type']],
                "old_ticket_type_id"=>$arrTicket['ticket_type'],
                "old_price"=>round($arrTicket['price'],2),
                "new_price"=>round($floFullPrice,2),
                "pay_price"=>round($floFullPrice,2),
                "tui_price"=>round($arrTicket['price'],2),
                "new_ticket_type"=>$strTicketTypeName,
                "new_ticket_type_id"=>$upTicketType,
                "ticket_id"=>$arrTicket['id'],
                "order_id"=>$arrTicket['order_id'],
                "is_half"=>$order['is_half'],
                'up_msg'=>$strUpMsg
            );
            return Array("code"=>1,"msg"=>"获取成功","data"=>$arrReturn);
        } catch (Exception $e){
            return Array("code"=>0,"msg"=>$e->getMessage());
        }
    }

    //升票型
    public function compensationApply($arrParam){
        Db::startTrans();
        try {
            //测试
            $request = Request::instance();
            $strIp = $request->ip();
            if($arrParam['ticket_id']==""){
                throw new Exception('票的编号不存在');
            }
            $arrTicket = Db::name("order_ship_tickets")->where(Array("id"=>$arrParam['ticket_id']))->find();
            if(!$arrTicket){
                throw new Exception('票的编号不正确');
            }
            $arrOrder = Db::name("order")->where(Array("id"=>$arrTicket['order_id']))->find();
            if($arrOrder["is_seckill"]??0){
                throw new Exception('秒杀订单不支持升票型');
            }
            if($arrOrder["is_admin_change"]??0){
                throw new Exception('后台改签订单不支持升票型');
            }
            $arrLimitTicketType = $this->getLimitTicketType();
            if(!in_array($arrTicket['ticket_type'],$arrLimitTicketType)){
                throw new Exception('该票型不允许升票型[1]');
            }

            $upTicketType = 1;
            //成人半票
            if($arrTicket['ticket_type']=="1354625587642949633"){
                $upTicketType = "1354625395564797953";
            }
            if($arrTicket['ticket_type']==$upTicketType){
                throw new Exception('已经是成人票型，不允许升票型。');
            }

            $objCommon = new Common();
            $booSaleLimitTime = $objCommon->returnSaleLimitTime();
            if($booSaleLimitTime){
                return Array("code" => 0, "msg" => "抱歉，系统维护时间不允许升舱!");
            }

            //驻岛家属
            $arrLandTicket = Db::name("order_ship_tickets")
                ->where("order_id",$arrTicket['order_id'])
                ->where("id","in",$arrParam['ticket_id'])
                ->where("ticket_type","1441313766673534977")
                ->order("id")
                ->select();
            if($arrLandTicket){
                throw new Exception("驻岛家属票不允许升票型。");
            }

            //导游免票不允许改签
            $objTeamLimit = new TeamLimit();
            $arrResult = $objTeamLimit->returnIsGuide($arrTicket['order_id']);
            if($arrResult['code']==1){
                throw new Exception('导游免票不允许升票型');
            }

            //大团不允许改签
            $isTeamOrder = TeamRateLimit::instance()->returnTeamOrder($arrOrder['id']);
            if($isTeamOrder){
                return Array("code" => 0, "msg" => "不允许升舱。");
            }

            $objLaterPay = new LaterPay();
            $arrResult = $objLaterPay->returnLaterPay($arrTicket['order_id']);
            $isLaterOrder = $arrResult['code'];
            if($isLaterOrder){
                throw new Exception('后付票不允许升票型');
            }

            $arrResult = LadderBalance::instance()->ladderShipTypeUpLimit($arrTicket);
            if($arrResult['code']!=1){
                throw new Exception($arrResult['msg']);
            }

            $isWf99 = BhYearLimit::instance()->returnBhYearOrder($arrOrder['id']);
            if($isWf99){
                throw new Exception('抱歉，99元游涠洲票不允许升票型');
            }
            //半价额度
            $intLockId = 0;
            if(!empty($arrOrder['is_half']) && $arrOrder['is_half']==1 ){
                $objShipApi = new ShipApi();
                $arrTicketPrice = $objShipApi->returnTicketPrice($arrTicket['flight_id']);
                if(empty($arrTicketPrice)){
                    throw new Exception('接口异常');
                }
                $floUpTypePrice = 0;
                foreach ($arrTicketPrice as $v) {
                    if ($v['ticketTypeId'] == $upTicketType) {
                        $floUpTypePrice = $v['price'];
                    }
                }
                $quota = Quota::balance($arrOrder['user_id'],1); //返回值额度
                if(!$quota || $quota < $floUpTypePrice){
//                    return Array('code' => 0, "msg" => "[1]半票额度不足");
                    $upTicketType = 1;
                }

                //锁定额度
                if($floUpTypePrice > 0 &&$upTicketType!=1){
                    $quotaReturnRs =  Quota::lockVoucher($arrOrder['user_id'],$floUpTypePrice);
                    if(!$quotaReturnRs || $quotaReturnRs['status'] != 1){
                        return Array('code' => 0, "msg" => "半票额度不足");
                    }
                    $intLockId = $quotaReturnRs['lock_id'];
                }
            }

            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "ticketId"=>$arrTicket['ticket_num'],
                "ticketTypeId"=>$upTicketType,
                "operationType"=>"RECEIPT"
            );
            $bbdUserName = "1831";
            $arrUserNameResult = Service::instance()->queryApiUserName($arrOrder['id']);
            if($arrUserNameResult['code']==1){
                $arrParamApi['bbdUserName'] = $arrUserNameResult['data'];
                $bbdUserName = $arrUserNameResult['data'];
            }
            $arrResult = $objShipApi->compensationShipApply($arrParamApi);
            $arrData = Array(
                "title" => "升票型申请接口",
                "order_num" => $arrTicket['order_key'],
                "add_time" => date("Y-m-d H:i:s"),
                "type" => 91,
                "request" => json_encode($arrParamApi, 128|256),
                "response" => json_encode($arrResult, 128|256),
                "ticket_list" => $arrTicket['ticket_num'],
            );
            CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrApplyResult = $arrResult['data'];
            $floTuiPrice = $arrApplyResult['pricePay'];
            if($floTuiPrice<0){
                throw new Exception("获取失败");
            }
            $floFullPrice = $floTuiPrice+$arrTicket['price'];

            //插入order
            $strOrderNum = date('YmdHis') . str_pad(mt_rand(100000, 999999), 4, '0', STR_PAD_LEFT);
            $strPrefix = substr($arrOrder['order_no'],0,3);
//            $arrUser = Db::name("user")->where(Array("id" => $arrTicket['user_id']))->find();
//            if ($arrUser['type_id'] == 3) {
//                $strPrefix = "VSH";
//            } else {
//                $strPrefix = "DSH";
//            }
            $objShipNew = new Ship();
            $arrChangShipInfo = $objShipNew->shipDetail($arrTicket['flight_id']);
            $arrChangShipInfo = $arrChangShipInfo['data'];
            $strOrderNum = $strPrefix . $strOrderNum;
            if(!empty($arrParam['order_no'])){
                $strOrderNum = $arrParam['order_no'];
            }
            $strProductName = $arrChangShipInfo['code'] . $arrChangShipInfo['lineName'] . " " . $arrChangShipInfo['shipName'];
            $strTradeNo = $arrParam['trade_no'];
            //todo 虚拟航班
            $isVirtuaShip = OrderUtil::isVirtuaShip($arrChangShipInfo['shipName']);
            if($isVirtuaShip==1){
                $strProductName = $arrChangShipInfo['code'] . $arrChangShipInfo['lineName'];
            }

            $arrOrder = Db::name("order")->where(Array("id"=>$arrTicket['order_id']))->find();
            $arrOrderData = Array(
                "order_key" => $arrOrder['order_key'],
                "order_no" => $strOrderNum,
                "trade_no" => $strTradeNo,
                "estate" => 1,
                "related_type" => 1,
                "related_title" => $strProductName,
                "user_id" => $arrOrder['user_id'],
                "ota_id" => $arrOrder['ota_id'],
                "user_name" => $arrOrder['user_name'],
                "user_mobile" => $arrOrder['user_mobile'],
                "book_mobile" => $arrOrder['book_mobile'],
                "user_remark" => $arrOrder['user_remark'],
                "use_time" => strtotime($arrChangShipInfo['shipDate']),
                "use_date" => $arrChangShipInfo['shipDate'],
                "amount" => $floFullPrice,
                "number" => 1,
                "create_time" => time(),
                "update_time" => time(),
                "channel" => $arrOrder['channel'],
                "ship_code" => $arrChangShipInfo['code'],
                "ship_name" => $arrChangShipInfo['shipName'],
                "start_port_id" => $arrChangShipInfo['departureHarbourId'],
                "end_port_id" => $arrChangShipInfo['arrivalHarbourId'],
                "related_id" => $arrChangShipInfo['lineId'],
                "item_title" => $arrChangShipInfo['cabinName'],
                "item_id" => $arrChangShipInfo['cabinId'],
                "order_estate" => 1,
                "is_change" => 3,
                "pay_type" => $arrOrder['pay_type'],
                "flight_id"=>$arrChangShipInfo['id'],
                "distributor_id" => $arrOrder['distributor_id'],
                "rate" => $arrOrder['rate'],
                "is_employee" => $arrOrder['is_employee'],
                "ip_address"=>$strIp,
                'is_virtua'=>$isVirtuaShip,
                'ota_ext_trade_no'=>!empty($arrParam['ext_trade_no'])?$arrParam['ext_trade_no']:''
            );

            if(!empty($arrOrder['is_half']) && $arrOrder['is_half']==1 &&$upTicketType!=1){
                $arrOrderData['is_half'] = 1;
                $arrOrderData['quota'] = $floFullPrice;
                $arrOrderData['quota_lock_id'] = $intLockId;
            }
            $arrOrderData = \app\core\common\DataCrypt::order($arrOrderData);
            $intOrderId = Db::name("order")->insertGetId($arrOrderData);
            if (!$intOrderId) {
                throw new Exception('插入订单表失败');
            }
            //记录船票扩展表
            $arrShipOrderExtend = Array(
                "order_id"=>$intOrderId,
                "ship_order_key"=>$arrOrder['order_key'],
                "bbd_user_name"=>$bbdUserName,
            );
            ShipOrderExpand::insertGetId($arrShipOrderExtend);

            //记录购买平台
            OrderUtil::addOrderPlatformByorderid($intOrderId);

            //更新旧票信息
            $arrData = Array(
                "ticket_status" => "补差申请",
                "update_time" => time(),
                "order_estate" => 15,
                "change_refund_price" => $floTuiPrice,
            );
            $result = Db::name("order_ship_tickets")->where(Array("id" => $arrTicket['id']))->update($arrData);
            if (!$result) {
                throw new Exception('申请失败,更新旧票失败。');
            }
            //加入新票
            $arrTicketData = Array(
                "order_key" => $arrTicket['order_key'],//票务系统订单编号
                "order_id" => $intOrderId,
                "user_id" => $arrTicket['user_id'],
                "flight_id" => $arrTicket['flight_id'],
                "start_port_id" => $arrTicket['start_port_id'],
                "arrive_port_id" => $arrTicket['arrive_port_id'],
                "use_day" => $arrTicket['use_day'],
                "use_date" => $arrTicket['use_date'],
                "name" => $arrTicket['name'],
                "idcard" => $arrTicket['idcard'],
                "ticket_type" => $upTicketType,
                "price" => $floFullPrice,
                "ticket_price" => $floFullPrice,
                "create_time" => time(),
                "ticket_num" => $arrTicket['ticket_num'],
                "old_ticket_id" => $arrTicket['id'],
                "child_idcard" => $arrTicket['child_idcard'],
                "child_name" => $arrTicket['child_name'],
                "ship_type_name"=>$arrChangShipInfo['typeName'],
                "extra_child_name"=>$arrTicket['extra_child_name'],
                "extra_child_idcard"=>$arrTicket['extra_child_idcard'],
                'is_virtua'=>$isVirtuaShip
            );
            $arrTicketData = \app\core\common\DataCrypt::orderShipTickets($arrTicketData);
            $intTicketNewId = Db::name("order_ship_tickets")->insertGetId($arrTicketData);
            if(!$intTicketNewId){
                throw new Exception('申请失败,插入新票失败。');
            }

            //补差申请表
            $arrTicketTypeUp = Array(
                "order_id"=>$arrTicket['order_id'],
                "ticket_id"=>$arrTicket['id'],
                "order_key"=>$arrTicket['order_key'],
                "ticket_num"=>$arrTicket['ticket_num'],
                "token"=>$arrApplyResult['token'],
                "old_ticket_price"=>$arrTicket['price'],
                "new_ticket_price"=>$floFullPrice,
                "up_order_id"=>$intOrderId,
                "up_ticket_id"=>$intTicketNewId,
                "pay_money"=>$floFullPrice,
                "tui_money"=>$arrTicket['price'],
                "payment"=>$floFullPrice,
            );
            $intChangeId = Db::name("ship_ticket_type_up")->insertGetId($arrTicketTypeUp);
            if(!$intChangeId){
                throw new Exception('申请失败,插入改签表失败。');
            }
            Db::name("order")->where(Array("id" => $arrOrder['id']))->setField("change_id", $intChangeId);

            $arrChangeData['order_id'] = $intOrderId;
            $arrChangeData['new_order_no'] = $strOrderNum;
            $arrChangeData['change_id'] = $intChangeId;
            $arrChangeData['trade_no'] = $strTradeNo;
            $arrChangeData['token'] = $arrApplyResult['token'];
            $arrChangeData['old_ticket_type'] = $arrTicket['ticket_type'];
            $arrChangeData['old_ticket_price'] = $arrTicket['price'];
            $arrChangeData['new_ticket_type'] = $upTicketType;
            $arrChangeData['new_ticket_price'] = $floFullPrice;


            //需要支付
            $paymentData = [
                'trade_no' => $strTradeNo,
                'orders' => $intOrderId,
                'amount' => $floFullPrice,
                'user_id' => $arrOrder['user_id'],
                'distributor_id' => $arrOrder['distributor_id'],
                'create_time' => time(),
                'update_time' => time(),
            ];
            $paymentTip = Db::name("order_payment")->insertGetId($paymentData);
            if (!$paymentTip) {
                throw new Exception('生成付款表失败！');
            }

            //自动取消消息队列
            $expireTime = config('system.order_expire_time_for_bwship');
            Queue::later('Order', ['action' => 'auto_cancel', 'trade_no' => $strTradeNo], $expireTime);
            Db::commit();
            return Array("code"=>1,"msg"=>"申请成功","data"=>$arrChangeData);
        } catch (Exception $e){
            Db::rollback();
            return Array("code"=>0,"msg"=>$e->getMessage());
        }
    }

    public function upTypeCancel($arrParam)
    {
        Db::startTrans();
        $intNewTicketId = $arrParam['ticket_id'];
        $arrShipTicket = Db::name("order_ship_tickets")->where(Array("id" => $intNewTicketId))->find();
        if (empty($arrShipTicket)) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }
        $arrUp = Db::name("ship_ticket_type_up")->where(Array("up_ticket_id"=>$intNewTicketId,"up_order_id"=>$arrShipTicket['order_id']))->find();
        if (empty($arrUp)) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }
        //解锁半票额度
        $arrOrderInfo = Db::name("order")->where("id",$arrUp['up_order_id'])->find();
        if (!$arrOrderInfo) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }
        if(!empty($arrOrderInfo['is_half']) &&!empty($arrOrderInfo['quota'])&&$arrOrderInfo['is_half'] && $arrOrderInfo['quota'] > 0){
            if(empty($arrOrderInfo['quota_lock_id'])){
                return Array("code" => 0, "msg" => "半票额度锁定异常。");
            }
            $rs = Quota::unlockVoucher($arrOrderInfo['quota_lock_id']);
            if(empty($rs) || $rs['status'] != 1){
                return Array("code" => 0, "msg" => "解锁半票额度失败。");
            }
        }
        $objShipApi = new ShipApi();
        $arrParamApi = Array(
            "ticketId"=>$arrUp['ticket_num'],
            "token"=>$arrUp['token'],
        );
        $arrUserNameResult = Service::instance()->queryApiUserName($arrOrderInfo['id']);
        if($arrUserNameResult['code']==1){
            $arrParamApi['bbdUserName'] = $arrUserNameResult['data'];
        }
        $arrResult = $objShipApi->compensationCancel($arrParamApi);
        $arrData = Array(
            "title" => "取消升票型接口",
            "order_num" => $arrParam['ticket_id'],
            "add_time" => date("Y-m-d H:i:s"),
            "type" => 93,
            "request" => json_encode($arrParamApi, 128|256),
            "response" => json_encode($arrResult, 128|256)
        );
        CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
        if ($arrResult['code'] != 1 ) {
            return Array("code" => $arrResult['code'], "msg" => $arrResult['message']);
        }
        //更新订单
        Db::name("order")->where(Array("id" => $arrShipTicket['order_id']))->setField("order_estate", OrderModel::STATUS_CANCELED);
        //更新旧票
        $arrData = Array(
            "ticket_status" => "已售",
            "order_estate" => 1,
        );
        Db::name("order_ship_tickets")->where(Array("id" => $arrShipTicket['old_ticket_id']))->update($arrData);

        //更新新票
        $arrData = Array(
            "ticket_status" => "取消补差",
            "order_estate" => 7,
        );
        Db::name("order_ship_tickets")->where(Array("id" => $arrShipTicket['id']))->update($arrData);

        //更新申请表
        $arrData = Array(
            "status" => 2,
            "save_time" => time()
        );
        Db::name("ship_ticket_type_up")->where(Array("id" => $arrShipTicket['up_id']))->update($arrData);
        Db::commit();
        return Array("code" => $arrResult['code'], "msg" => $arrResult['message']);
    }

    public function compensationConfirm($arrParam)
    {
        Db::startTrans();
        $intShipTicketId = $arrParam['ticket_id'];
        $arrShipTicket = Db::name("order_ship_tickets")->where(Array("id" => $intShipTicketId))->find();
        if (empty($arrShipTicket)) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }
        $arrUp = Db::name("ship_ticket_type_up")->where(Array("up_ticket_id"=>$intShipTicketId,"up_order_id"=>$arrShipTicket['order_id']))->find();
        if (empty($arrUp)) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }

        //vip半票额度
        $order = Db::name("order")->where("id",$arrUp['up_order_id'])->find();
        if (!$order) {
            throw new Exception('错误的升舱编号。');
        }
        if($order['is_half'] && $order['quota'] > 0){
            //已在 useVoucher 里解锁
            //使用额度
            $res = Quota::useVoucher($order['user_id'],$order['related_type'],$order,$order['quota'],$order['ota_id']);
            if(!$res || !$res['status']){
                throw new Exception('使用半票额度失败 '.$res['msg']);
            }
        }
        $objShipApi = new ShipApi();
        $intPayType = !empty($arrParam['pay_type'])?$arrParam['pay_type']:$order['pay_type'];
        $payMethodDetail = OrderModel::bbdPaymentName($intPayType);
        $amountExchange = empty($order['is_half'])?false:true;
        $arrParamApi = Array(
            "ticketId"=>$arrUp['ticket_num'],
            "token"=>$arrUp['token'],
            "payType"=>"LYB_ONLINE_PAY",
            "payMethodDetail"=>$payMethodDetail,
            "amountExchange"=>$amountExchange,
            "operationType"=>"RECEIPT",
            "paymentMethod"=>"LYB_ONLINE_PAY"
        );
        $arrUserNameResult = Service::instance()->queryApiUserName($order['id']);
        if($arrUserNameResult['code']==1){
            $arrParamApi['bbdUserName'] = $arrUserNameResult['data'];
        }
        $arrResult = $objShipApi->compensationShipConfirm($arrParamApi);
        $arrData = Array(
            "title" => "确认升票型接口",
            "order_num" => $arrUp['order_key'],
            "add_time" => date("Y-m-d H:i:s"),
            "type" => 94,
            "request" => json_encode($arrParamApi, 128|256),
            "response" => json_encode($arrResult, 128|256)
        );
        CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
        if ($arrResult['code'] != 1) {
            return Array("code" => $arrResult['code'], "msg" => $arrResult['message']);
        }
        $arrUpData = $arrResult['data'];

        //生成退款记录
        // $arrOrder = Db::name("order")->where(Array("id" => $arrUp['order_id']))->find();
        $arrOrder = \app\common\model\Order::where(Array("id" => $arrUp['order_id']))->find();
        $arrOrderRefund = Array(
            "trade_no" => $arrOrder['trade_no'],
            "order_id" => $arrOrder['id'],
            "order_expand_id" => $arrUp['ticket_id'],
            "related_type" => 1,
            "user_id" => $arrOrder['user_id'],
            "user_mobile" => $arrOrder['user_mobile'],
            "user_remark" => "升票型退款",
            "business_id" => $arrOrder['business_id'],
            "business_remark" => $arrOrder['business_remark'],
            "finance_estate" => 1,
            "estate" => 1,
            "refund_no" => $arrOrder['order_no'] ."_". rand(1000, 9999),
            "refund_price" => $arrUp['tui_money'],
            "check_type" => 3,
            "check_estate" => 2,
            "create_time" => time(),
            "refund_type" => 13,
        );
        // Db::name("order_refund_apply")->insert($arrOrderRefund);
        $result = \app\common\model\OrderRefundApply::create($arrOrderRefund);
        $ticket_ids = explode(",", $arrUp["ticket_id"]);
        \app\common\model\OrderRefundDetail::addDetail($arrOrder, $ticket_ids, $result["id"], 3);

        //返回退款记录
        $otaRefundApply = ShipUtils::transOtaRefundApply($result["id"]);
        $arrUpData['refund_apply'] = $otaRefundApply;
        
        //更新信息
        $arrOrderData = Array(
            "pay_estate"=>1,
            "order_estate"=>OrderModel::STATUS_PAID
        );
        Db::name("order")->where(Array("id" => $arrShipTicket['order_id']))->update($arrOrderData);

        //更新旧票
        $arrData = Array(
            "order_estate" => 16,
            "ticket_status" => "已补差",
            "refund_rate" => 0,
            "refund_price" => $arrUp['tui_money'],
            "refund_remark" => "补差",
            "deal_operator" => "用户",
        );
        Db::name("order_ship_tickets")->where(Array("id" => $arrShipTicket['old_ticket_id']))->update($arrData);

        //更新旧票订单状态
        $arrWhere =Array(
            "order_estate"=>7,
            "order_id"=>$arrUp['order_id']
        );
        $arrOldTicket = Db::name("order_ship_tickets")->where($arrWhere)->find();
        if(empty($arrOldTicket)){
            //存在退票的 不更改主订单状态
            $intOldStatus = 16;//已升舱
            $arrWhere =Array(
                ["order_estate","not in","16"],
                ['order_id','=',$arrUp['order_id']]
            );
            $arrOldTicket = Db::name("order_ship_tickets")->where($arrWhere)->find();
            if($arrOldTicket){
                $intOldStatus = 15;//部分已升舱
            }
            $arrOrderData = Array(
                "order_estate" => $intOldStatus
            );
            Db::name("order")->where(Array("id" =>$arrUp['order_id']))->update($arrOrderData);
        }

        //更新新票信息
        $arrData = Array(
            "ticket_key" => $arrUpData['ticketNo'],
            "qrcode_content" => $arrUpData['qrCodeContent'],
            "seat_info" => $arrUpData['seatMemo'],
            "ticket_status" => "已售",
            "ticket_num"=>$arrUpData['id']
        );
        Db::name("order_ship_tickets")->where(Array("id" => $arrShipTicket['id']))->update($arrData);
        //更新改签申请表
        $arrData = Array(
            "status"=>1,
            "save_time"=>time()
        );
        Db::name("ship_ticket_type_up")->where(Array("id" => $arrShipTicket['up_id']))->update($arrData);
        //发送改签短信
        Db::commit();
        return Array("code" => $arrResult['code'], "msg" => $arrResult['message'],"data"=>$arrUpData);
    }

    public function getQuota($intTicketId){
        try{
            if($intTicketId<0){
                throw new Exception('缺少票号。');
            }
            $arrTicket = Db::name("order_ship_tickets")->where(Array("id"=>$intTicketId))->find();
            if(!$arrTicket){
                throw new Exception('票的编号不正确');
            }
            if($arrTicket['ticket_type']!="1354625587642949633"){
                throw new Exception('不是儿童半票不限制');
            }
            //半票额度
            $quota = Quota::balance($arrTicket['user_id'],1); //返回值额度
            $objShipApi = new \app\api\service\ship\ShipApi();
            $arrParamApi = Array(
                "cabinId"=>$arrTicket['flight_id'],
                "ticketTypeId"=>$arrTicket['ticket_type'],
            );
            $arrResult = $objShipApi->getCompensationTicketPrice($arrParamApi);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrTicketList = $arrResult['data'];
            $floFullPrice =  $floAdultPrice = 0;
            foreach ($arrTicketList as $v){
                //2020年之前是冬游优惠票型
                if($v['ticketTypeId']=="1354625395564797953"){
                    $floFullPrice = $v['price'];
                }
                if($v['ticketTypeId']==1){
                    $floAdultPrice = $v['price'];
                }
            }
            if(!$quota || $quota < $floFullPrice){
                return Array("code"=>1,"msg"=>"半票额度不足{$floFullPrice}出成人半票，确定要升为{$floAdultPrice}全价票吗","data"=>[]);
            }
            return Array("code"=>0,"msg"=>"确定要升票型吗","data"=>[]);
        }catch (Exception $e){
            return Array("code"=>-1,"msg"=>$e->getMessage());
        }


    }

    //批量升票型
    public function getBathTicketTypeTicketPrice($intOrderId,$intTicketId){
        try {
            if($intOrderId==""){
                throw new Exception('订单号不能空');
            }
            if($intTicketId==""){
                throw new Exception('票的编号不存在');
            }
            $arrOrder = Db::name("order")->where("id",$intOrderId)->find();
            if(!$arrOrder){
                throw new Exception('订单号不存在');
            }
            if($arrOrder['is_area_order']==0){
                throw new Exception('不是区域订单,不允许批量升票型');
            }
            $arrTicket = Db::name("order_ship_tickets")->where("order_id",$intOrderId)->where("id","in",$intTicketId)->select();
            if(!$arrTicket){
                throw new Exception('票的编号不正确');
            }
            $intTicketType = "1288034823257034754";
            foreach ($arrTicket as $t){
                if($t['ticket_type']=!"1288034823257034754"){
                    throw new Exception('不是区域团优票，不支持批量升票型。');
                }
            }

            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "cabinId"=>$arrOrder['item_id'],
                "ticketTypeId"=>$intTicketType,
            );
            $arrResult = $objShipApi->getCompensationTicketPrice($arrParamApi);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrTicketList = $arrResult['data'];
            $upTicketType = 1;
            $floFullPrice = 0;
            $strTicketTypeName = "全票";
            $floAdultPrice = 0;
            foreach ($arrTicketList as $v){
                //2020年之前是冬游优惠票型
                if($v['ticketTypeId']==$upTicketType){
                    $floFullPrice = $v['price'];
                    $strTicketTypeName = $v['ticketTypeName'];
                }
            }
            if($floFullPrice<0){
                throw new Exception("获取失败");
            }


            $floTotalTui = 0;
            $floPayPrice = 0;
            foreach ($arrTicket as $t){
                $floTotalTui+=$t['price'];
                $floPayPrice+=$floFullPrice;
            }


            $arrReturn = Array(
                "old_ticket_type"=>"区域团优票",
                "old_ticket_type_id"=>"1288034823257034754",
                "old_price"=>$floTotalTui,
                "new_price"=>$floPayPrice,
                "pay_price"=>$floPayPrice,
                "tui_price"=>$floTotalTui,
                "new_ticket_type"=>$strTicketTypeName,
                "new_ticket_type_id"=>$upTicketType,
                "order_id"=>$arrOrder['id'],
            );
            return Array("code"=>1,"msg"=>"获取成功","data"=>$arrReturn);
        } catch (Exception $e){
            return Array("code"=>0,"msg"=>$e->getMessage());
        }
    }

    public function bathTicketTypeUpApply($arrParam){
        Db::startTrans();
        try {
            throw new Exception('暂不支持批量升票型');
            $request = Request::instance();
            $strIp = $request->ip();
            if($arrParam['ticket_id']==""){
                throw new Exception('票的编号不存在');
            }
            $arrOrder = Db::name("order")->where(Array("id"=>$arrParam['order_id']))->find();
            if($arrOrder['is_area_order']==0){
                throw new Exception('不是区域订单,不允许批量升票型');
            }

            $arrTicket = Db::name("order_ship_tickets")->where("order_id",$arrOrder['id'])->where("id","in",$arrParam['ticket_id'])->order("id")->select();
            if(!$arrTicket){
                throw new Exception('票的编号不正确');
            }

            $upTicketType = 1;
            $intTicketType = "1288034823257034754";
            $arrTicketNumIds = [];
            $floTotalTui = 0;
            $arrTicketIds = [];
            foreach ($arrTicket as $t){
                if($t['ticket_type']!="1288034823257034754"){
                    throw new Exception('不是区域团优票，不支持批量升票型。');
                }
                $arrTicketNumIds[] = $t['ticket_num'];
                $arrTicketIds[] = $t['id'];
                $floTotalTui+=$t['price'];
            }

            $objCommon = new Common();
            $booSaleLimitTime = $objCommon->returnSaleLimitTime();
            if($booSaleLimitTime){
                return Array("code" => 0, "msg" => "抱歉，系统维护时间不允许升舱!");
            }

            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "cabinId"=>$arrOrder['item_id'],
                "ticketTypeId"=>$intTicketType,
            );
            $arrResult = $objShipApi->getCompensationTicketPrice($arrParamApi);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrTicketList = $arrResult['data'];
            $upTicketType = 1;
            $floTicketFullPrice = 0;
            foreach ($arrTicketList as $v){
                //2020年之前是冬游优惠票型
                if($v['ticketTypeId']==$upTicketType){
                    $floTicketFullPrice = $v['price'];
                }
            }
            if($floTicketFullPrice<0){
                throw new Exception("获取失败");
            }


            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "ticketIds"=>$arrTicketNumIds,
                "ticketTypeId"=>$upTicketType,
            );
            $arrResult = $objShipApi->compensationApply($arrParamApi);
            $arrData = Array(
                "title" => "升票型申请接口",
                "order_num" => $arrOrder['order_key'],
                "add_time" => date("Y-m-d H:i:s"),
                "type" => 911,
                "request" => json_encode($arrParamApi, 128|256),
                "response" => json_encode($arrResult, 128|256),
                "ticket_list" => json_encode($arrTicketNumIds, 128|256),
            );
            CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
            if (empty($arrResult['code'])) {
                throw new Exception('获取失败');
            }
            if ($arrResult['code'] != 1 ||empty($arrResult['data'])) {
                throw new Exception($arrResult['message']);
            }
            $arrApplyResult = $arrResult['data'];
            $floTuiPrice = $arrApplyResult['pricePay'];
            if($floTuiPrice<0){
                throw new Exception("获取失败");
            }
            $floFullPrice = $floTuiPrice+$floTotalTui;

            //插入order
            $strOrderNum = date('YmdHis') . str_pad(mt_rand(100000, 999999), 4, '0', STR_PAD_LEFT);
            $strPrefix = substr($arrOrder['order_no'],0,3);
//            $arrUser = Db::name("user")->where(Array("id" => $arrOrder['user_id']))->find();
//            if ($arrUser['type_id'] == 3) {
//                $strPrefix = "VSH";
//            } else {
//                $strPrefix = "DSH";
//            }
            $objShipNew = new Ship();
            $arrChangShipInfo = $objShipNew->shipDetail($arrOrder['item_id']);

            $arrChangShipInfo = $arrChangShipInfo['data'];
            $strOrderNum = $strPrefix . $strOrderNum;
            if(!empty($arrParam['order_no'])){
                $strOrderNum = $arrParam['order_no'];
            }
            $strProductName = $arrChangShipInfo['code'] . $arrChangShipInfo['lineName'] . " " . $arrChangShipInfo['shipName'];
            $strTradeNo = $arrParam['trade_no'];
            //todo 虚拟航班
            $isVirtuaShip = OrderUtil::isVirtuaShip($arrChangShipInfo['shipName']);
            if($isVirtuaShip==1){
                $strProductName = $arrChangShipInfo['code'] . $arrChangShipInfo['lineName'];
            }

            $arrOrderData = Array(
                "order_key" => $arrOrder['order_key'],
                "order_no" => $strOrderNum,
                "trade_no" => $strTradeNo,
                "estate" => 1,
                "related_type" => 1,
                "related_title" => $strProductName,
                "user_id" => $arrOrder['user_id'],
                "ota_id" => $arrOrder['ota_id'],
                "user_name" => $arrOrder['user_name'],
                "user_mobile" => $arrOrder['user_mobile'],
                "book_mobile" => $arrOrder['book_mobile'],
                "user_remark" => $arrOrder['user_remark'],
                "use_time" => strtotime($arrChangShipInfo['shipDate']),
                "use_date" => $arrChangShipInfo['shipDate'],
                "amount" => $floFullPrice,
                "number" => count($arrTicket),
                "create_time" => time(),
                "update_time" => time(),
                "channel" => $arrOrder['channel'],
                "ship_code" => $arrChangShipInfo['code'],
                "ship_name" => $arrChangShipInfo['shipName'],
                "start_port_id" => $arrChangShipInfo['departureHarbourId'],
                "end_port_id" => $arrChangShipInfo['arrivalHarbourId'],
                "related_id" => $arrChangShipInfo['lineId'],
                "item_title" => $arrChangShipInfo['cabinName'],
                "item_id" => $arrChangShipInfo['cabinId'],
                "order_estate" => 1,
                "is_change" => 3,
                "pay_type" => $arrOrder['pay_type'],
                "flight_id"=>$arrChangShipInfo['id'],
                "distributor_id" => $arrOrder['distributor_id'],
                "rate" => $arrOrder['rate'],
                "is_employee" => $arrOrder['is_employee'],
                "ip_address"=>$strIp,
                'is_virtua'=>$isVirtuaShip,
                'ota_ext_trade_no'=>!empty($arrParam['ext_trade_no'])?$arrParam['ext_trade_no']:''
            );

            $arrOrderData = \app\core\common\DataCrypt::order($arrOrderData);
            $intOrderId = Db::name("order")->insertGetId($arrOrderData);
            if (!$intOrderId) {
                throw new Exception('插入订单表失败');
            }

            //记录购买平台
            OrderUtil::addOrderPlatformByorderid($intOrderId);

            //更新旧票信息
            foreach ($arrTicket as $t){
                $arrData = Array(
                    "ticket_status" => "补差申请",
                    "update_time" => time(),
                    "order_estate" => 15,
                );
                $result = Db::name("order_ship_tickets")->where(Array("id" => $t['id']))->update($arrData);
                if (!$result) {
                    throw new Exception('申请失败,更新旧票失败。');
                }

                //加入新票
                $arrTicketData = Array(
                    "order_key" => $t['order_key'],//票务系统订单编号
                    "order_id" => $intOrderId,
                    "user_id" => $t['user_id'],
                    "flight_id" => $t['flight_id'],
                    "start_port_id" => $t['start_port_id'],
                    "arrive_port_id" => $t['arrive_port_id'],
                    "use_day" => $t['use_day'],
                    "use_date" => $t['use_date'],
                    "name" => $t['name'],
                    "idcard" => $t['idcard'],
                    "ticket_type" => $upTicketType,
                    "price" => $floTicketFullPrice,
                    "ticket_price" => $floTicketFullPrice,
                    "create_time" => time(),
                    "ticket_num" => $t['ticket_num'],
                    "old_ticket_id" => $t['id'],
                    "child_idcard" => $t['child_idcard'],
                    "child_name" => $t['child_name'],
                    "ship_type_name"=>$t['ship_type_name'],
                    "extra_child_name"=>$t['extra_child_name'],
                    "extra_child_idcard"=>$t['extra_child_idcard'],
                    'is_virtua'=>$isVirtuaShip
                );
                $arrTicketData = \app\core\common\DataCrypt::orderShipTickets($arrTicketData);
                $intTicketNewId = Db::name("order_ship_tickets")->insertGetId($arrTicketData);
                if(!$intTicketNewId){
                    throw new Exception('申请失败,插入新票失败。');
                }
            }


            //补差申请表
            $arrTicketTypeUp = Array(
                "order_id"=>$arrOrder['id'],
                "ticket_id"=>$arrParam['ticket_id'],
                "order_key"=>$arrOrder['order_key'],
                "ticket_num"=>implode(",",$arrTicketNumIds),
                "token"=>$arrApplyResult['token'],
                "old_ticket_price"=>$floTotalTui,
                "new_ticket_price"=>$floFullPrice,
                "up_order_id"=>$intOrderId,
                "pay_money"=>$floFullPrice,
                "tui_money"=>$floTotalTui,
                "payment"=>$floFullPrice,
            );
            $intChangeId = Db::name("ship_type_up")->insertGetId($arrTicketTypeUp);
            if(!$intChangeId){
                throw new Exception('申请失败,插入改签表失败。');
            }
            Db::name("order")->where(Array("id" => $arrOrder['id']))->setField("change_id", $intChangeId);

            $arrChangeData['order_id'] = $intOrderId;
            $arrChangeData['new_order_no'] = $strOrderNum;
            $arrChangeData['change_id'] = $intChangeId;
            $arrChangeData['trade_no'] = $strTradeNo;
            $arrChangeData['token'] = $arrApplyResult['token'];
            $arrChangeData['old_ticket_type'] = $intTicketType;
            $arrChangeData['old_ticket_price'] = $floTotalTui;
            $arrChangeData['new_ticket_type'] = $upTicketType;
            $arrChangeData['new_ticket_price'] = $floFullPrice;
            $arrChangeData['pay_money'] = $floFullPrice;
            $arrChangeData['tui_money'] = $floTotalTui;


            //需要支付
            $paymentData = [
                'trade_no' => $strTradeNo,
                'orders' => $intOrderId,
                'amount' => $floFullPrice,
                'user_id' => $arrOrder['user_id'],
                'distributor_id' => $arrOrder['distributor_id'],
                'create_time' => time(),
                'update_time' => time(),
            ];
            $paymentTip = Db::name("order_payment")->insertGetId($paymentData);
            if (!$paymentTip) {
                throw new Exception('生成付款表失败！');
            }


            //自动取消消息队列
            $expireTime = config('system.order_expire_time_for_bwship');
            Queue::later('Order', ['action' => 'auto_cancel', 'trade_no' => $strTradeNo], $expireTime);
            Db::commit();
            return Array("code"=>1,"msg"=>"申请成功","data"=>$arrChangeData);
        } catch (Exception $e){
            Db::rollback();
            return Array("code"=>0,"msg"=>$e->getMessage());
        }
    }

    public function bathUpTypeCancel($arrParam)
    {
        Db::startTrans();
        try{
            $intUpId = $arrParam['upId'];
            $arrShipUp = Db::name("ship_type_up")->where(Array("id"=>$intUpId))->find();
            if (!$arrShipUp) {
                throw new Exception('错误的升票型编号。');
            }
            if($arrShipUp['status']==1){
                return Array("code" => 1, "msg" => "已经升票型成功");
            }
            if($arrShipUp['status']==2){
                return Array("code" => 1, "msg" => "已取消升票型");
            }

            $arrOrderInfo = Db::name("order")->where("id",$arrShipUp['up_order_id'])->find();
            if (!$arrOrderInfo) {
                throw new Exception('错误的升舱编号。');
            }

            $objShipApi = new ShipApi();
            $arrParamApi = Array(
                "ticketId"=>'',
                "token"=>$arrShipUp['token'],
            );
            $arrResult = $objShipApi->upgradeCancel($arrParamApi);
            $arrData = Array(
                "title" => "取消升票型接口",
                "order_num" => $arrShipUp['order_key'],
                "add_time" => date("Y-m-d H:i:s"),
                "type" => 77,
                "request" => json_encode($arrParamApi, 128|256),
                "response" => json_encode($arrResult, 128|256),
            );
            CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
            if ($arrResult['code'] != 1 &&$arrResult['code']!=99062) {
                return Array("code" => $arrResult['code'], "msg" => $arrResult['message']);
            }

            //更新旧订单订单
            Db::name("order")->where(Array("id" => $arrShipUp['order_id']))->setField("order_estate", OrderModel::STATUS_PAID);
            //更新新订单订单
            Db::name("order")->where(Array("id" => $arrShipUp['up_order_id']))->setField("order_estate", OrderModel::STATUS_CANCELED);

            $arrTicketList = Db::name("order_ship_tickets")->where(Array(["id","in",$arrShipUp['ticket_id']]))->order("id")->select();
            if(!$arrTicketList){
                throw new Exception('升舱失败,code:3。');
            }
            foreach ($arrTicketList as $v){
                //更新旧票
                $arrData = Array(
                    "ticket_status" => "已售",
                    "order_estate" => 1,
                );
                $result = Db::name("order_ship_tickets")->where(Array("id" => $v['id']))->update($arrData);
                if($result===false){
                    throw new Exception('更新旧票失败。');
                }
            }
            //更新新票
            $arrData = Array(
                "ticket_status" => "取消升票型",
                "order_estate" => 7,
            );
            $result = Db::name("order_ship_tickets")->where(Array("order_id" => $arrShipUp['up_order_id']))->update($arrData);
            if($result===false){
                throw new Exception('更新新票失败。');
            }

            //更新申请表
            $arrData = Array(
                "status" => 2,
                "save_time" => time()
            );
            Db::name("ship_type_up")->where(Array("id" => $arrShipUp['id']))->update($arrData);
            Db::commit();
            return Array("code" => 1, "msg" => "取消成功");
        }catch (Exception $e){
            Db::rollback();
            return Array("code"=>$e->getCode(),"msg"=>$e->getMessage());
        }

    }

    public function bathTypeUpConfirm($arrParam)
    {
        Db::startTrans();
        $intUpId = $arrParam['up_id'];
        $arrShipTypeUp = Db::name("ship_type_up")->where(Array("id" => $intUpId))->find();
        if (empty($arrShipTypeUp)) {
            return Array("code" => 0, "msg" => "错误的票编号。");
        }
        $order = Db::name("order")->where("id",$arrShipTypeUp['up_order_id'])->find();

        $objShipApi = new ShipApi();
        $intPayType = !empty($arrParam['pay_type'])?$arrParam['pay_type']:$order['pay_type'];
        $payMethodDetail = OrderModel::bbdPaymentName($intPayType);
        $amountExchange = empty($order['is_half'])?false:true;
        $arrParamApi = Array(
            "ticketIds"=>explode(',',$arrShipTypeUp['ticket_num']),
            "token"=>$arrShipTypeUp['token'],
            "payType"=>"LYB_ONLINE_PAY",
            "payMethodDetail"=>$payMethodDetail,
            "amountExchange"=>$amountExchange,
        );
        $arrResult = $objShipApi->compensationConfirm($arrParamApi);
        $arrData = Array(
            "title" => "确认升票型接口",
            "order_num" => $arrShipTypeUp['ticket_num'],
            "add_time" => date("Y-m-d H:i:s"),
            "type" => 94,
            "request" => json_encode($arrParamApi, 128|256),
            "response" => json_encode($arrResult, 128|256)
        );
        CommonUtil::redisLogRecord("u8_bbd_api_log", $arrData);
        if ($arrResult['code'] != 1) {
            return Array("code" => $arrResult['code'], "msg" => $arrResult['message']);
        }

        $arrUpData = $arrResult['data'];

        //生成退款记录
        $arrOrder = \app\common\model\Order::where(Array("id" => $arrShipTypeUp['order_id']))->find();
        $arrOrderRefund = Array(
            "trade_no" => $arrOrder['trade_no'],
            "order_id" => $arrOrder['id'],
            "order_expand_id" => $arrShipTypeUp['ticket_id'],
            "related_type" => 1,
            "user_id" => $arrOrder['user_id'],
            "user_mobile" => $arrOrder['user_mobile'],
            "user_remark" => "升票型退款",
            "business_id" => $arrOrder['business_id'],
            "business_remark" => $arrOrder['business_remark'],
            "finance_estate" => 1,
            "estate" => 1,
            "refund_no" => $arrOrder['order_no'] ."_". rand(1000, 9999),
            "refund_price" => $arrShipTypeUp['tui_money'],
            "check_type" => 3,
            "check_estate" => 2,
            "create_time" => time(),
            "refund_type" => 13,
        );
        // Db::name("order_refund_apply")->insert($arrOrderRefund);
        $result = \app\common\model\OrderRefundApply::create($arrOrderRefund);
        $ticket_ids = explode(",", $arrShipTypeUp["ticket_id"]);
        \app\common\model\OrderRefundDetail::addDetail($arrOrder, $ticket_ids, $result["id"], 3);

        //返回退款记录
        $otaRefundApply = ShipUtils::transOtaRefundApply($result["id"]);


        //更新信息
        $arrOrderData = Array(
            "pay_estate"=>1,
            "order_estate"=>OrderModel::STATUS_PAID
        );
        Db::name("order")->where(Array("id" => $arrShipTypeUp['up_order_id']))->update($arrOrderData);

        $arrTicket = Db::name("order_ship_tickets")->where("id","in",$arrShipTypeUp['ticket_id'])->order("id")->select();
        foreach ($arrTicket as $t){
            //更新旧票
            $arrData = Array(
                "order_estate" => 16,
                "ticket_status" => "已补差",
                "refund_rate" => 0,
                "refund_price" => $t['price'],
                "refund_remark" => "补差",
                "deal_operator" => "用户",
            );
            Db::name("order_ship_tickets")->where("id",$t['id'])->update($arrData);
        }


        //更新旧票订单状态
        $arrWhere =Array(
            "order_estate"=>7,
            "order_id"=>$arrShipTypeUp['order_id']
        );
        $arrOldTicket = Db::name("order_ship_tickets")->where($arrWhere)->find();
        if(empty($arrOldTicket)){
            //存在退票的 不更改主订单状态
            $intOldStatus = 16;//已升舱
            $arrWhere =Array(
                ["order_estate","not in","16"],
                ['order_id','=',$arrShipTypeUp['order_id']]
            );
            $arrOldTicket = Db::name("order_ship_tickets")->where($arrWhere)->find();
            if($arrOldTicket){
                $intOldStatus = 15;//部分已升舱
            }
            $arrOrderData = Array(
                "order_estate" => $intOldStatus
            );
            Db::name("order")->where(Array("id" =>$arrShipTypeUp['order_id']))->update($arrOrderData);
        }

        //更新新票信息
        $arrTicketIds = [];
        foreach ($arrUpData as $v){
            if(in_array($v['id'],$arrTicketIds)){
                continue;
            }
            $arrTicketIds[] =$v['id'];
            $arrData = Array(
                "ticket_key" => $v['ticketNo'],
                "qrcode_content" => $v['qrCodeContent'],
                "seat_info" => $v['seatMemo'],
                "ticket_status" => "已售",
                "order_estate"=>1,
            );
            Db::name("order_ship_tickets")->where(Array("ticket_num" => $v['id'],"order_id"=>$arrShipTypeUp['up_order_id']))->update($arrData);
        }

        //更新改签申请表
        $arrData = Array(
            "status"=>1,
            "save_time"=>time()
        );
        Db::name("ship_type_up")->where(Array("id" => $arrShipTypeUp['id']))->update($arrData);
        $arrUpData['refund_apply'] = $otaRefundApply;
        //发送改签短信
        Db::commit();
        return Array("code" => $arrResult['code'], "msg" => $arrResult['message'],"data"=>$arrUpData);
    }

    //允许升票型-票型
    public function getLimitTicketType(){
        $arrTicketType = [
            "1000203",//儿童票
            "1000103",//北海市民票
            "1113013698546757634",//北海市民儿童票
            "1000205",//北海老人票老人票
            "1000207",//军残票
            "1209451194018344962",//普残票
            "1417433523284283393",//英烈遗属票
            "1000201",//岛民票
            "1000211",//岛民儿童票
            "1000210",//岛民优惠票
            "1000305",//岛民儿童优惠票
        ];
        return $arrTicketType;
    }
}