@extends('layout.master')

@section('content')
    <?php
        $menu = 'product';
        $page = 'product.list';
    ?>
    @include('layout.header')
    @include('layout.sidemenu')

    <section class="Hui-article-box">
        <nav class="breadcrumb">
            <i class="Hui-iconfont">&#xe67f;</i> 首页 <span class="c-gray en">&gt;</span> 产品管理 <span class="c-gray en">&gt;</span> 产品列表
            <a class="btn btn-success radius r" style="line-height:1.6em;margin-top:3px" href="javascript:location.replace(location.href);" title="刷新" ><i class="Hui-iconfont">&#xe68f;</i></a>
        </nav>
        <div class="Hui-article">
            <div class="pos-a" style="width:150px; padding-top: 20px; height:100%; border-right:1px solid #e5e5e5; background-color:#f5f5f5">
                <ul id="treeDemo" class="ztree">
                </ul>
            </div>
            <div style="margin-left:150px;">
                <div class="pd-20">
                    <div class="cl pd-5 bg-1 bk-gray">
					<span class="l">
						<a class="btn btn-primary radius" href="{{url('/product/new')}}">
						    <i class="Hui-iconfont">&#xe600;</i> 添加产品
                        </a>
					</span>
                        <span class="r">共有数据：<strong>{{count($products)}}</strong> 条</span>
                    </div>
                    <div class="mt-20">
                        <table class="table table-border table-bordered table-bg table-hover table-sort">
                            <thead>
                                <tr class="text-c">
                                    <th width="20" rowspan="2">ID</th>
                                    <th rowspan="2">名称</th>
                                    <th rowspan="2">分类</th>
                                    <th rowspan="2">原价</th>
                                    <th rowspan="2">运费</th>
                                    <th colspan="3">拼团信息</th>
                                    <th rowspan="2">库存</th>
                                    <th width="50" rowspan="2">操作</th>
                                </tr>
                                <tr class="text-c">
                                    <th>人数底线</th>
                                    <th>拼团价</th>
                                    <th>倒计时周期</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($products as $p)
                                <tr class="text-c va-m">
                                    <td>{{$p->id}}</td>
                                    <td>{{$p->name}}</td>
                                    <td>{{$p->category->name}}</td>
                                    <td><span class="price">{{$p->price}}</span></td>
                                    <td><span class="price">{{$p->deliver_cost}}</span></td>
                                    <td>{{$p->gb_count}}</td>
                                    <td>{{$p->gb_price}}</td>
                                    <td>{{$p->gb_timeout}}</td>
                                    <td>{{$p->remain}}</td>
                                    <td class="td-manage">
                                        <a style="text-decoration:none" class="ml-5" href="{{url('/product')}}/{{$p->id}}/edit" title="编辑">
                                            <i class="Hui-iconfont">&#xe6df;</i>
                                        </a>
                                        <a style="text-decoration:none" class="ml-5" onClick="product_del(this,'{{$p->id}}')" href="javascript:;" title="删除">
                                            <i class="Hui-iconfont">&#xe6e2;</i>
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
    <script type="text/javascript" src="<?=asset('lib/datatables/1.10.0/jquery.dataTables.min.js') ?>"></script>
    <script type="text/javascript">
        var setting = {
            view: {
                dblClickExpand: false,
                showLine: false,
                selectedMulti: false
            },
            data: {
                simpleData: {
                    enable:true,
                    idKey: "id",
                    pIdKey: "pId",
                    rootPId: ""
                }
            },
            callback: {
                beforeClick: function(treeId, treeNode) {
                    var zTree = $.fn.zTree.getZTreeObj("tree");
                    if (treeNode.isParent) {
                        zTree.expandNode(treeNode);
                        return false;
                    } else {
                        location.href=treeNode.file;
//                        demoIframe.attr("src",treeNode.file + ".html");
                        return true;
                    }
                }
            }
        };

        var zNodes =[
                @foreach($categories as $c)
            {  id:"{{$c->id}}", pId:0, name:"{{$c->name}}", file:"{{url('/products')}}?cat={{$c->id}}"},
                @endforeach
        ];

        $(document).ready(function(){
            var t = $("#treeDemo");
            t = $.fn.zTree.init(t, setting, zNodes);
            demoIframe = $("#testIframe");
            demoIframe.bind("load", loadReady);
            var zTree = $.fn.zTree.getZTreeObj("tree");
            //zTree.selectNode(zTree.getNodeByParam("id",'1'));

            $('.table-sort').dataTable({
                'ordering': false
            });
        });

        function loadReady(){
        }

        /*图片-删除*/
        function product_del(obj,id){
            layer.confirm('确认要删除吗？',function(index){

                // 提交
                $.ajax({
                    type: 'DELETE',
                    url: '{{url('/product')}}',
                    data: {
                        'product_id': id,
                        "_token": "{{ csrf_token() }}",
                    },
                    success: function (data) {
                        $(obj).parents("tr").remove();
                        layer.msg('已删除!',{icon:1,time:1000});
                    },
                    error: function (data) {
                        console.log(data);
                    }
                });
            });
        }
    </script>
@endsection