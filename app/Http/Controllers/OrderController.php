<?php

namespace App\Http\Controllers;

use App\Groupbuy;
use App\Order;
use App\Product;
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $orders = Order::with('product')
            ->with('spec')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('order.list', array_merge($this->viewBaseParams, [
            'page' => $this->menu . '.list',
            'orders'=>$orders,
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
        return view('order.detail', array_merge($this->viewBaseParams, [
            'page' => $this->menu . '.list',
            'order'=>$order
        ]));
    }
    
    public function updateOrder(Request $request, $id) {
        $order = Order::find($id);
        
        if($request->has('deliver_code')) {
            $order->deliver_code = $request->input('deliver_code');
            $order->status = Order::STATUS_SENT;
        }
        
        $order->save();

        return redirect()->to(url('/order')."/detail/".$id);
    }

    /**
     * 下单API
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function makeOrderApi(Request $request) {
        $nProductId = $request->input('product_id');
        $product = Product::find($nProductId);

        // 获取参数
        $aryParam = [
            'customer_id'       => $request->input('customer_id'),
            'product_id'        => $nProductId,
            'count'             => $request->input('count'),
            'name'              => $request->input('name'),
            'phone'             => $request->input('phone'),
            'spec_id'           => $request->input('spec_id'),
            'channel'           => $request->input('channel'),
            'desc'              => $request->input('desc'),
            'price'             => $request->input('price'),
            'pay_status'        => Order::STATUS_PAY_PAID,
            'status'            => Order::STATUS_INIT,
        ];

        $order = Order::create($aryParam);

        if ($request->has('store_id')) {
            $order->store_id = $request->input('store_id');
        }
        if ($request->has('address')) {
            $order->address = $request->input('address');
        }

        // 拼团设置
        $nGroupBuy = intval($request->input('groupbuy_id'));
        if ($nGroupBuy > 0) {
            $order->groupbuy_id = $request->input('address');
            $order->status = Order::STATUS_GROUPBUY_WAITING;
        }
        else if ($nGroupBuy == 0) {
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

        // 添加订单状态历史
        $order->addStatusHistory();

        return response()->json([
            'status' => 'success',
        ]);
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

            $orderInfo['groupbuy'][] = [
                'persons' => $order->groupBuy->getPeopleCount(),
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

        $orderInfo['id'] = $order->id;
        $orderInfo['status'] = Order::getStatusName($order->status);
        $orderInfo['product_image'] = $order->product->getThumbnailUrl();
        $orderInfo['product_name'] = $order->product->name;
        $orderInfo['product_price'] = $order->product->price;
        $orderInfo['count'] = $order->count;
        $orderInfo['is_groupbuy'] = !empty($order->groupbuy_id);
        $orderInfo['spec'] = $order->spec->name;
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

        $orders = $query->where('channel', $deliveryMode)
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
        $result['name'] = $order->name;
        $result['phone'] = $order->phone;
        $result['store'] = $order->store;

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
}
