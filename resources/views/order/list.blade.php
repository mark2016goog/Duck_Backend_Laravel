@extends('layout.master')

@section('content')

    @include('layout.header')
    @include('layout.sidemenu')

    <section class="Hui-article-box">
        <nav class="breadcrumb">
            <i class="Hui-iconfont">&#xe67f;</i> 首页 <span class="c-gray en">&gt;</span> 订单管理 <span class="c-gray en">&gt;</span> 订单列表
            <a class="btn btn-success radius r" style="line-height:1.6em;margin-top:3px" href="javascript:location.replace(location.href);" title="刷新" >
                <i class="Hui-iconfont">&#xe68f;</i>
            </a>
        </nav>
        <div class="Hui-article">
            <div>
                <div class="pd-20">
                    <div class="cl pd-5 bg-1 bk-gray">
                        <span class="r">共有数据：<strong>{{count($orders)}}</strong> 条</span>
                    </div>
                    <div class="mt-20">
                        <table class="table table-border table-bordered table-bg table-hover table-sort">
                            <thead>
                            <tr class="text-c">
                                <th width="40">订单号</th>
                                <th>商品</th>
                                <th width="60">数量</th>
                                <th width="100">规格</th>
                                <th width="60">姓名</th>
                                <th width="100">手机号</th>
                                <th width="100">配送方式</th>
                                <th width="100">金额</th>
                                <th width="100">订单状态</th>
                                <th width="100">操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($orders as $o)
                            <tr class="text-c va-m">
                                <td>{{$o->id}}</td>
                                <td>{{$o->product->name}}</td>
                                <td class="text-l">{{$o->count}}</td>
                                <td class="text-l">{{$o->spec->name}}</td>
                                <td class="text-l">{{$o->name}}</td>
                                <td class="text-l">{{$o->phone}}</td>
                                <td class="text-l">{{$o->getDeliveryName()}}</td>
                                <td><span class="price">{{$o->price}}</span> 元</td>
                                <td class="td-status">{{\App\Order::getStatusName($o->status, $order->channel)}}</td>
                                <td class="td-manage">
                                    <a style="text-decoration:none"
                                       class="ml-5"
                                       href="{{url('/order/detail')}}/{{$o->id}}"
                                       title="编辑">
                                        <i class="Hui-iconfont">&#xe6df;</i>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
<!--请在下方写此页面业务相关的脚本-->
<script type="text/javascript" src="<?=asset('lib/datatables/1.10.0/jquery.dataTables.min.js') ?>"></script>
<script type="text/javascript">

    $(document).ready(function(){
    });

    $('.table-sort').dataTable({
        'ordering': false
    });

</script>
@endsection