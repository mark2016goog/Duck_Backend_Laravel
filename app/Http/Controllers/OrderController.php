<?php

namespace App\Http\Controllers;

use App\Groupbuy;
use App\Model\Customer;
use App\Order;
use App\Product;
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Query\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Mockery\Exception;
use Log;

require_once app_path() . "/lib/Wxpay/WxPay.Api.php";

class OrderController extends Controller
{
    public $menu = 'order';
    public $viewBaseParams;

    public function __construct()
    {
        $this->viewBaseParams = ['menu' => $this->menu];
    }

    /**
     * 打开订单列表
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getOrderList(Request $request) {
        $queryOrder = Order::with('product')
            ->with('spec')
            ->orderBy('created_at', 'desc');

        // 开始日期
        $dateStart = $request->input('start_date');
        if (!empty($dateStart)) {
            $queryOrder->whereDate('created_at', '>=', $dateStart);
        }

        // 结束日期
        $dateEnd = $request->input('end_date');
        if (!empty($dateEnd)) {
            $queryOrder->whereDate('created_at', '<=', $dateEnd);
        }

        // 商品
        $product = $request->input('product');
        if (!empty($product)) {
            $queryOrder->whereHas('product', function($query) use ($product) {
                $query->where('name', 'like', '%' . $product. '%');
            });
        }

        // 配送渠道
        $channel = $request->input('channel');
        if ($channel != null) {
            if ($channel == Order::DELIVER_EXPRESS || $channel == Order::DELIVER_SELF) {
                $queryOrder->where('channel', $channel);
            }
        }

        // 是否拼团
        $groupbuy = $request->input('gropubuy');
        if (!empty($groupbuy)) {
            // 拼团
            if ($groupbuy == 1) {
                $queryOrder->whereNotNull('groupbuy_id');
            }
            // 非拼团
            else {
                $queryOrder->whereNull('groupbuy_id');
            }
        }

        // 门店
        $store = $request->input('store');
        if (!empty($store)) {
            $queryOrder->whereHas('store', function($query) use ($store) {
                $query->where('name', 'like', '%' . $store. '%');
            });
        }

        // 用户名
        $name = $request->input('name');
        if (!empty($name)) {
            $queryOrder->where('name', 'like', '%' . $name. '%');
        }

        // 手机号
        $phone = $request->input('phone');
        if (!empty($phone)) {
            $queryOrder->where('phone', 'like', '%' . $phone. '%');
        }

        // 订单状态
        $status = $request->input('status');
        if (!empty($status)) {
            $queryOrder->where('status', $status);
        }

        $orders = $queryOrder->paginate();

        return view('order.list', array_merge($this->viewBaseParams, [
            'page' => $this->menu . '.list',

            // 筛选字段
            'start_date' => $dateStart,
            'end_date' => $dateEnd,
            'produdct' => $product,
            'channel' => $channel,
            'groupbuy' => $groupbuy,
            'store' => $store,
            'name' => $name,
            'phone' => $phone,
            'status' => $status,

            // 数据
            'orders'=> $orders,
        ]));
    }

    /**
     * 打开订单详情页面
     * @param Request $request
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showOrder(Request $request, $id) {
        $order = Order::find($id);

        // 此订单不存在
        if (empty($order)) {
            abort(404);
        }

        return view('order.detail', array_merge($this->viewBaseParams, [
            'page' => $this->menu . '.list',
            'order'=>$order
        ]));
    }

    /**
     * 输入快递单号设置发货状态
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateOrder(Request $request, $id) {
        $order = Order::find($id);
        $errMsg = '';

        if ($order->status == Order::STATUS_INIT || $order->status == Order::STATUS_SENT) {
            if ($request->has('deliver_code')) {
                $order->deliver_code = $request->input('deliver_code');
            }

            // 状态历史只有变活的时候才添加
            if ($order->status == Order::STATUS_INIT) {
                if ($order->channel == Order::DELIVER_EXPRESS) {
                    $order->status = Order::STATUS_SENT;

                    // 推送消息，发货
                    $nctrl = new NotificationController();

                    $strToken = $nctrl->getAccessToken();
                    $params = array();
                    $params["keyword1"] = [
                        "value" => $order->product->name,
                    ];
                    $params["keyword2"] = [
                        "value" => $order->number,
                    ];
                    $params["keyword3"] = [
                        "value" => $order->address,
                    ];
                    $params["keyword4"] = [
                        "value" => $order->name,
                    ];
                    $params["keyword5"] = [
                        "value" => $order->deliver_code,
                    ];

                    $nctrl->sendPushNotification($strToken, [
                        "touser" => $order->customer->wechat_id,
                        "template_id" => "4vzFUADZnrupzQqnpUBAW7F8GjQqNT8sL1he0aQ9R3E",
                        "form_id" => $order->formid,
                        "data" => $params,
                    ]);
                }
                else {
                    $order->status = Order::STATUS_RECEIVED;
                }

                $order->addStatusHistory();
            }

            $order->save();
        }
        else if ($order->status == Order::STATUS_REFUND_REQUESTED) {
            // 确认退款
            $refundInfo = $order->refundOrder($this);

            if (!empty($refundInfo['err_code_des'])) {
                $errMsg = $refundInfo['err_code_des'];
            }
        }

        return view('order.detail', array_merge($this->viewBaseParams, [
            'page' =>$this->menu . '.list',
            'order'=>$order,
            'errMsg'=>$errMsg
        ]));
    }

    /**
     * 退款
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function refundOrder(Request $request, $id) {
        $order = Order::find($id);
        $refundInfo = $order->refundOrder($this);

        $errMsg = '';
        if (!empty($refundInfo['err_code_des'])) {
            $errMsg = $refundInfo['err_code_des'];
        }

        return response()->json([
            'status' => $refundInfo['result_code'],
            'errMsg' => $errMsg,
        ]);
    }

    /**
     * 预支付处理
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function prepareOrderApi(Request $request) {
        $nProductId = $request->input('product_id');
        $dPrice = $request->input('price');
        $nCustomerId = $request->input('customer_id');

        $strTradeNo = time() . uniqid();

        $product = Product::find($nProductId);
        $customer = Customer::find($nCustomerId);

        // 预支付
        $worder = new \WxPayUnifiedOrder();

        $worder->SetBody($product->name);
        $worder->SetOut_trade_no($strTradeNo);
        $worder->SetTotal_fee(intval($dPrice * 100));
        $worder->SetNotify_url("http://paysdk.weixin.qq.com/example/notify.php");
        $worder->SetTrade_type("JSAPI");

        $worder->SetOpenid($customer->wechat_id);

        $payOrder = \WxPayApi::unifiedOrder($worder);

        return response()->json([
            'status' => 'success',
            'result' => $payOrder,
            'trade_no' => $strTradeNo
        ]);
    }

    /**
     * 下单API
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function makeOrderApi(Request $request) {
        $nProductId = $request->input('product_id');
        $nCount = $request->input('count');
        $product = Product::find($nProductId);
        $nChannel = $request->input('channel');
        $nSpecId = $request->input('spec_id');

        try {
            $order = new Order();

            // 设置基础参数
            $order->customer_id = $request->input('customer_id');
            $order->product_id = $nProductId;
            $order->count = $nCount;
            $order->name = $request->input('name');
            $order->phone = $request->input('phone');
            if (!empty($nSpecId)) {
                $order->spec_id = $nSpecId;
            }
            $order->channel = $nChannel;
            $order->desc = $request->input('desc');
            $order->price = $request->input('price');
            $order->trade_no = $request->input('trade_no');

            $order->formid = $request->input('formid');
            $order->formid_group = $request->input('formid_group');

            $order->pay_status = Order::STATUS_PAY_PAID;
            $order->status = Order::STATUS_INIT;

            // 门店自提
            if ($request->has('store_id')) {
                $order->store_id = $request->input('store_id');
            }
            // 快递
            if ($request->has('address')) {
                $order->address = $request->input('address');
                $order->area = $request->input('area');
                $order->zipcode = $request->input('zipcode');
            }

            // 拼团设置
            $nGroupBuy = intval($request->input('groupbuy_id'));
            if ($nGroupBuy > 0) {
                // 拼团已无效
                $group = Groupbuy::find($nGroupBuy);
                if (empty($group)) {
                    // 退款
                    $this->refundOrderCore($request->input('trade_no'), floatval($request->input('price')));
                    Log::info("Refund in unavailable groupbuy: " . $request->input('trade_no'));
                    Log::info("Order Failed");

                    return response()->json([
                        'status' => 'fail',
                        'message' => '此拼团已无效'
                    ]);
                }

                $order->groupbuy_id = $request->input('groupbuy_id');
                $order->status = Order::STATUS_GROUPBUY_WAITING;
            } else if ($nGroupBuy == 0) {
                // 计算到期时间
                $timeCurrent = new DateTime("now");
                $timeCurrent->add(new \DateInterval('PT' . $product->gb_timeout . 'H'));

                $aryParam = [
                    'end_at' => getStringFromDateTime($timeCurrent)
                ];
                $groupBuy = Groupbuy::create($aryParam);
                $order->groupbuy_id = $groupBuy->id;

                $order->status = Order::STATUS_GROUPBUY_WAITING;
            }

            $order->save();

            //
            // 生成订单编号
            //
            $dateCurrent = new DateTime("now");
            $strNumber = "p";
            if ($nGroupBuy < 0) {
                $strNumber = "l";
            }
            if ($nChannel == Order::DELIVER_EXPRESS) {
                $strNumber .= "k";
            } else {
                $strNumber .= "z";
            }

            $strNumber .= $dateCurrent->format('ymdHis');
            $strNumber .= intToString($nProductId, 3);
            $strNumber .= intToString($order->id, 4);

            $order->number = $strNumber;
            $order->save();

            // 添加订单状态历史
            $order->addStatusHistory();

            // 查看拼团状况
            if ($order->checkGroupBuy()) {
                // 减少库存
                $product->remain -= $nCount;
                $product->save();
            }
            else {
                Log::info("Refund after check groupbuy: " . $order->id);
                Log::info("Order Failed");

                // 拼团失败
                $order->refundOrder($this);
                $order->delete();

                return response()->json([
                    'status' => 'fail',
                    'message' => '来晚了，此拼团已无效'
                ]);
            }
        }
        catch (Exception $e) {
            // 退款
            $this->refundOrderCore($request->input('trade_no'), floatval($request->input('price')));
            Log::info("Refund in exception handler in makeorder: " . $request->input('trade_no'));
            Log::info("Order Failed");

            return response()->json(['status' => 'fail'], 401);
        }

        Log::info("Order Success: " . $order->id);

        return response()->json([
            'status' => 'success',
            'result' => $order->id
        ]);
    }

    /**
     * 退款核心
     * @param $tradeNo
     * @param $price
     * @return \成功时返回，其他抛异常
     */
    public function refundOrderCore($tradeNo, $price) {
        $strRefundNo = time() . uniqid();

        $input = new \WxPayRefund();
        $input->SetOut_trade_no($tradeNo);
        $input->SetTotal_fee($price * 100);
        $input->SetRefund_fee($price * 100);
        $input->SetOut_refund_no($strRefundNo);
        $input->SetOp_user_id(\WxPayConfig::MCHID);

        $result = \WxPayApi::refund($input);
        $json = response()->json($result);

        Log::info("Refund " . $tradeNo . " result: " . $json);

        return $result;
    }

    /**
     * 获取基础query
     * @param Request $request
     * @return Builder
     */
    private function getBaseOrderQuery(Request $request) {
        $nCustomerId = $request->input('customer_id');

        return Order::with('product')
            ->with('spec')
            ->where('customer_id', $nCustomerId)
            ->orderBy('created_at', 'desc');
    }

    /**
     * 获取我的拼团列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGroupbuysApi(Request $request) {
        $query = $this->getBaseOrderQuery($request);

        $orders = $query->whereNotNull('groupbuy_id')
            ->get();

        $result = [];
        foreach ($orders as $order) {
            $orderInfo = $this->getOrderInfoSimple($order);

            $customers = array();

            $orderGroups = $order->groupBuy->orders()->with('customer')->get();
            foreach ($orderGroups as $og) {
                $customers[] = $og->customer;
            }

            $orderInfo['groupbuy'] = [
                'persons' => $customers,
                'remain_time' => $order->groupBuy->getRemainTime(),
            ];

            // 添加拼团信息
            $result[] = $orderInfo;
        }

        return response()->json([
            'status' => 'success',
            'result' => $result,
        ]);
    }

    /**
     * 设置订单信息
     * @param Order $order
     * @return array
     */
    private function getOrderInfoSimple(Order $order) {
        $orderInfo = [];

        // 普通价格或拼团价格？
        $dPrice = $order->product->price;
        if (!empty($order->groupbuy_id)) {
            $dPrice = $order->product->gb_price;
        }

        $orderInfo['id'] = $order->id;
        $orderInfo['number'] = $order->number;
        $orderInfo['status_val'] = $order->status;
        $orderInfo['refund_reason'] = $order->refund_reason;
        $orderInfo['status'] = Order::getStatusName($order->status, $order->channel);
        $orderInfo['product_id'] = $order->product->id;
        $orderInfo['product_image'] = $order->product->getThumbnailUrl();
        $orderInfo['product_name'] = $order->product->name;
        $orderInfo['product_price'] = $dPrice;
        $orderInfo['deliver_cost'] = $order->product->deliver_cost;
        $orderInfo['count'] = $order->count;

        $orderInfo['is_groupbuy'] = !empty($order->groupbuy_id);

        if (!empty($order->spec)) {
            $orderInfo['spec'] = $order->spec->name;
        }
        $orderInfo['price'] = $order->price;
        $orderInfo['created_at'] = getStringFromDateTime($order->created_at);
        $orderInfo['channel'] = $order->channel;
        if ($order->store) {
            $orderInfo['store_name'] = $order->store->name;
        }

        return $orderInfo;
    }

    /**
     * 获取快递订单
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExpressesApi(Request $request) {
        return $this->getOrdersByDeliverApi($request, Order::DELIVER_EXPRESS);
    }

    /**
     * 获取自提订单
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSelfsApi(Request $request) {
        return $this->getOrdersByDeliverApi($request, Order::DELIVER_SELF);
    }

    /**
     * 获取订单列表
     * @param Request $request
     * @param $deliveryMode
     * @return \Illuminate\Http\JsonResponse
     */
    private function getOrdersByDeliverApi(Request $request, $deliveryMode) {
        $query = $this->getBaseOrderQuery($request);

        $orders = $query->where('status', '>=', Order::STATUS_INIT)
            ->where('channel', $deliveryMode)
            ->get();

        $result = [];
        foreach ($orders as $order) {
            $orderInfo = $this->getOrderInfoSimple($order);

            // 添加拼团信息
            $result[] = $orderInfo;
        }

        return response()->json([
            'status' => 'success',
            'result' => $result,
        ]);
    }

    /**
     * 获取订单详情
     * @param $orderId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderDetailApi($orderId) {
        $order = Order::with('product')
            ->with('spec')
            ->with('store')
            ->find($orderId);

        $result = $this->getOrderInfoSimple($order);

        // 买家留言
        $result['desc'] = $order->desc;

        // 配送信息
        $result['address'] = $order->address;
        $result['area'] = $order->area;
        $result['zipcode'] = $order->zipcode;
        $result['name'] = $order->name;
        $result['phone'] = $order->phone;
        $result['store'] = $order->store;
        $result['deliver_code'] = getEmptyString($order->deliver_code);
        $result['deliver_cost'] = $order->product->deliver_cost;

        $result['groupbuys'] = array();

        // 拼团
        if (!empty($order->groupBuy)) {
            $orderGroups = Order::with('customer')
                ->where('groupbuy_id', $order->groupbuy_id)
                ->get();

            foreach ($orderGroups as $og) {
                $result['groupbuys'][] = $og->customer;
            }
        }

        return response()->json([
            'status' => 'success',
            'result' => $result,
        ]);
    }

    /**
     * 确认接收API
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receiveProductApi(Request $request) {
        $nOrderId = $request->input('order_id');
        $order = Order::find($nOrderId);

        $order->status = Order::STATUS_RECEIVED;
        $order->save();

        // 添加订单状态历史
        $order->addStatusHistory();

        return response()->json([
            'status' => 'success',
        ]);
    }

    /**
     * 申请退款API
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refundRequestApi(Request $request) {
        $nOrderId = $request->input('order_id');
        $strReason = $request->input('reason');

        $order = Order::find($nOrderId);

        $order->status = Order::STATUS_REFUND_REQUESTED;
        $order->refund_reason = Order::REFUND_OTHER;
        $order->refund_reason_other = $strReason;

        $order->save();

        // 添加订单状态历史
        $order->addStatusHistory();

        return response()->json([
            'status' => 'success',
        ]);
    }


}
