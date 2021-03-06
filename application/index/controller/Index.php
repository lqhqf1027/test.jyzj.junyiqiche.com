<?php

namespace app\index\controller;

use app\admin\model\WxPublicUser;
use app\common\controller\Frontend;
use app\common\library\Token;
use app\admin\model\Order;
use app\admin\model\OrderDetails;
use think\Cache;
use think\Controller;
use think\Config;
use think\Db;
use think\Env;
use think\Exception;
use think\Request;
use think\Session;
use wechat\Wx;

class Index extends Frontend
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';
    protected $Wxapis = '';


    public function _initialize()
    {
        parent::_initialize();
//        $this->Wxapis = new Wx(Env::get('wx_public.appid'), Env::get('wx_public.secret'));
//        $token = Session::get('rslt')['access_token'];
//
//        $r = gets("https://api.weixin.qq.com/cgi-bin/user/info?access_token={$token}&openid=" . Session::get('MEMBER')['openid']);
//        if (!$r['subscribe']) {
//            alert('请先关注公众号，点击logo头像即可关注！', 'jump', 'https://mp.weixin.qq.com/mp/profile_ext?action=home&__biz=MzIyODAyNjE3NA==&scene=126&bizpsid=0&subscene=0#wechat_redirect');
////                header('Location:https://mp.weixin.qq.com/mp/profile_ext?action=home&__biz=MzIyODAyNjE3NA==&scene=126&bizpsid=0&subscene=0#wechat_redirect');
//        }
    }


    public function index()
    {

        $order_id = Request::instance()->param('order_id');
        $uid = Session::get('MEMBER');
        if ($order_id) {   //判断是否扫码进入；
            $s = self::isApplyDriver($order_id);
//            pr($s);die;
            if ($s['wx_public_user_id'] && $s['wx_public_user_id'] !== $uid['id']) die('<h1 style="margin-top: 20%;color: red;"><center> 该车辆已被 ' . $s['username'] . ' 授权</center></h1>');
            if ($s['wx_public_user_id'] == $uid['id']){
                header('Location:https://jyzj.junyiqiche.com/index');
//                alert('您已授权过，无需再次授权!', '', 'https://jyzj.junyiqiche.com/index/index/apply');
            }
            Db::startTrans();
            try {

                if (Order::update(['id' => $order_id, 'wx_public_user_id' => $uid['id']]) && WxPublicUser::update(['id' => $uid['id'], 'is_apply' => 1])) {
                    $order_details = OrderDetails::get(['order_id' => $order_id])->getData();
                    $data[] = [
                        'hphm' => mb_substr($order_details['licensenumber'], 0, 2),
                        'hphms' => $order_details['licensenumber'],//车牌号，必传
                        'engineno' => $order_details['engine_number'],//发动机号，需要的城市必传
                        'classno' => $order_details['frame_number'],//车架号，需要的城市必传
                        'order_id' => $order_id
                    ];
                    illegal($data);

                }
                else{
                    throw new Exception('认证失败');
                }

                Db::commit();

            } catch (Exception $e) {
                Db::rollback();
                die($e->getMessage());
            }
            alert('认证成功!！', '', 'https://jyzj.junyiqiche.com/index');
        }
        $userinfo = WxPublicUser::get(['openid' => $uid['openid']])->getData();
        $this->model = new \app\admin\model\Order();
        $order_details = collection($this->model->where(['wx_public_user_id' => $uid['id']])->field('username,phone,wx_public_user_id,models_name')
            ->with(['orderdetails' => function ($q) {
                $q->withField('id,licensenumber,frame_number,total_deduction,total_fine,violation_details,engine_number');
            }])->select())->toArray();
        foreach ($order_details as $value) {
            $order_details = $value;
        }
        if ($order_details) $detail = json_decode($order_details['orderdetails']['violation_details'], true);
        $service = self::serverSexclusive($uid['id']);
//用户头像,用户的查询次数
        $this->view->assign([
            'order_details' => $order_details,
            'detail' => $detail,
            'query_time' => $userinfo['query_time'] ? date('Y-m-d H:i:s', $userinfo['query_time']) : '从未更新',
            'count' => $detail ? count($detail) : 0,
            'userinfo' => $userinfo,
            'id' => $order_details['orderdetails']['id'],
            'licensenumber' => $order_details['orderdetails']['licensenumber'],
            'server' => $service//专属客服二维码
        ]);

        return Order::get(['wx_public_user_id' => $uid['id']]) ? $this->view->fetch('apply') : $this->view->fetch();

    }

    /**
     * 司机专属客服二维码
     * @param $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function serverSexclusive($uid)
    {
//        $service_id = Order::get(['wx_public_user_id']);
        return collection(Order::field('id,service_id')->with(['service'])->where(['wx_public_user_id' => $uid])->select())->toArray()[0]['service'];
    }

    /**
     * 定时任务，每周日23：59 全部重置可查询,违章
     */
    public function resetQuery_number()
    {
        $m = new WxPublicUser();
        $res = $m->all();
        $list = [];
        foreach ($res as $row) {
            array_push($list, ['id' => $row->id, 'query_number' => 1]);
        }
        return $m->saveAll($list) ? true : false;
//        return $list

    }

    /**
     * 判断该条订单是否 被认证过
     * @param $order_id
     * @return bool
     * @throws \think\exception\DbException
     */
    public static function isApplyDriver($order_id)
    {
        return Order::get($order_id)->getData();
    }

    /**
     * 查询司机信息
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getDriverInfo()
    {
        if ($this->request->isAjax()) {
            if ($this->request->isPost()) {
                $params = $this->request->post('');

                $id_card = Order::get(['id_card' => trim($params['id_card'])]);

                $licensenumber = OrderDetails::get(['licensenumber' => trim($params['licensenumber'])]);

                if (!$id_card || !$licensenumber) {
                    $this->error('未查询到客户信息');
                };
                if ($licensenumber->order_id !== $id_card->id) {
                    $this->error('车牌号与身份信息不符合');
                } else {
                    $res = Order::field(['id,username,id_card,models_name'])->with(['orderdetails' => function ($q) {
                        $q->withField(['frame_number', 'licensenumber', 'engine_number']);
                    }])->select(['id' => $id_card->id]);
                    $res = collection($res)->toArray()[0];
                    $this->success('查询成功', '', $res);
                }


            }
            $this->error('非法请求');

        }
        $this->error('非法请求');

    }

    /**
     * 认证君忆司机[已填写表单-》提交认证]
     */
    public function applyDriverInfo()
    {
        $uid = Session::get('MEMBER');

        $this->model = new \app\admin\model\Order();

        if ($this->request->isAjax()) {

            $params = $this->request->post('');

            $order_details = $this->model->field('username,phone,wx_public_user_id,models_name')
                ->with(['orderdetails' => function ($q) {
                    $q->withField('id,licensenumber,frame_number,engine_number,total_deduction,total_fine,violation_details,is_it_illegal');
                }])->find($params['order_id']);

            $data[] = [
                'hphm' => mb_substr($order_details['orderdetails']['licensenumber'], 0, 2),
                'hphms' => $order_details['orderdetails']['licensenumber'],//车牌号，必传
                'engineno' => $order_details['orderdetails']['engine_number'],//发动机号，需要的城市必传
                'classno' => $order_details['orderdetails']['frame_number'],//车架号，需要的城市必传
                'order_id' => $params['order_id'],
                'username' => $order_details['username'],
            ];
            // pr($data);
            // die;
            $result = illegal($data);

            // pr($result);
            // die;

            if ($result['query_record'][0]['status'] == 'success') {

                Db::startTrans();
                try {
                    if (self::isApplyDriver($params['order_id'])['wx_public_user_id']) throw new Exception('该车型已被认证过');
                    WxPublicUser::update(['id' => Session::get('MEMBER')['id'], 'is_apply' => $params['is_apply']]);
                    Order::update(['id' => $params['order_id'], 'wx_public_user_id' => Session::get('MEMBER')['id']]);

                    Db::commit();
                } catch (Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage(), '', '');
                }
                $this->success('认证成功', '', '');


                $this->error('非法请求', '', '');

            } else $this->error($result['query_record'][0]['msg']);

        }
        $this->error('非法请求', '', '');

    }

    /**
     * 已认证公众号君忆司机
     * @return string
     * @throws Exception
     */
    public function apply()
    {

        return $this->view->fetch();
    }

    /**卡片分享
     * @return false|string
     */
    public function sharedata()
    {
        $url = input('urll');//获取当前页面的url，接收请求参数

        $root['url'] = $url;
        //获取access_token，并缓存
        $file = RUNTIME_PATH . '/access_token';//缓存文件名access_token
        $appid = Env::get('wx_public.appid'); // 填写自己的appid
        $secret = Env::get('wx_public.secret'); // 填写自己的appsecret
        $expires = 3600;//缓存时间1个小时
        if (file_exists($file)) {
            $time = filemtime($file);
            if (time() - $time > $expires) {
                $token = null;
            } else {
                $token = file_get_contents($file);
            }
        } else {
            fopen("$file", "w+");
            $token = null;
        }
        if (!$token || strlen($token) < 6) {
            $res = file_get_contents("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=" . $appid . "&secret=" . $secret . "");

            $res = json_decode($res, true);
            $token = $res['access_token'];
            // write('access_token', $token, 3600);
            @file_put_contents($file, $token);
        }

        //获取jsapi_ticket，并缓存
        $file1 = RUNTIME_PATH . '/jsapi_ticket';
        if (file_exists($file1)) {
            $time = filemtime($file1);
            if (time() - $time > $expires) {
                $jsapi_ticket = null;
            } else {
                $jsapi_ticket = file_get_contents($file1);
            }
        } else {
            fopen("$file1", "w+");
            $jsapi_ticket = null;
        }
        if (!$jsapi_ticket || strlen($jsapi_ticket) < 6) {
            $ur = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=$token&type=jsapi";
            $res = file_get_contents($ur);
            $res = json_decode($res, true);
            $jsapi_ticket = $res['ticket'];
            @file_put_contents($file1, $jsapi_ticket);
        }

        $timestamp = time();//生成签名的时间戳
        $metas = range(0, 9);
        $metas = array_merge($metas, range('A', 'Z'));
        $metas = array_merge($metas, range('a', 'z'));
        $nonceStr = '';
        for ($i = 0; $i < 16; $i++) {
            $nonceStr .= $metas[rand(0, count($metas) - 1)];//生成签名的随机串
        }

        $string1 = "jsapi_ticket=" . $jsapi_ticket . "&noncestr=" . $nonceStr . "&timestamp=" . $timestamp . "&url=" . $url . "";
        $signature = sha1($string1);
        $root['appid'] = $appid;
        $root['nonceStr'] = $nonceStr;
        $root['timestamp'] = $timestamp;
        $root['signature'] = $signature;

        return json_encode($root);
    }


    public function illegalFun()
    {

    }

    /**
     * 点击按钮查询违章
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function selCarInfo()
    {
        $uid = Session::get('MEMBER');

        $userinfo = WxPublicUser::get(['openid' => $uid['openid']])->getData();
        if ($userinfo['query_number'] == 0) $this->error('请于下周再来更新！');

        $this->model = new \app\admin\model\Order();
        $order_details = $this->model->where(['wx_public_user_id' => $uid['id']])->field('username,phone,wx_public_user_id,models_name')
            ->with(['orderdetails' => function ($q) {
                $q->withField('id,licensenumber,frame_number,engine_number,total_deduction,total_fine,violation_details,is_it_illegal,order_id');
            }])->find();

        $data[] = [
            'hphm' => mb_substr($order_details['orderdetails']['licensenumber'], 0, 2),
            'hphms' => $order_details['orderdetails']['licensenumber'],//车牌号，必传
            'engineno' => $order_details['orderdetails']['engine_number'],//发动机号，需要的城市必传
            'classno' => $order_details['orderdetails']['frame_number'],//车架号，需要的城市必传
            'order_id' => $order_details['orderdetails']['order_id'],
            'username' => $order_details['username'],
        ];
        $result = illegal($data);

        // pr($result);
        // die;

        if ($result['query_record'][0]['status'] == 'success') {

            WxPublicUser::update(['id' => $uid['id'], 'query_number' => 0, 'query_time' => time()]);

            $this->success('查询成功', '',
                [
                    'lists' => $result['lists'],
                    'total_fraction' => $result['query_record'][0]['total_deduction'],
                    'total_money' => $result['query_record'][0]['total_fine'],
                    'counts' => count($result['lists']),
                    'upTime' => '上一次更新时间：' . date('Y-m-d H:i:s', time())
                ]

            );

        } else $this->error($result['query_record'][0]['msg']);

    }

    /**
     *
     * curl Post数据
     * @param $url http地址
     * @param $data &链接的字符串或者数组
     * @param $timeout 默认请求超时
     * 成功返回字符串
     */
    static function curl_post_contents($url, $data = array(), $timeout = 10)
    {
        $userAgent = 'xx5.com PHP5 (curl) ' . phpversion();
        $referer = $url;
        if (!is_array($data) || !$url) return '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);            //设置访问的url地址
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);            //设置超时
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);   //用户访问代理 User-Agent
        curl_setopt($ch, CURLOPT_REFERER, $referer);      //设置 referer
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);      //跟踪301
        curl_setopt($ch, CURLOPT_POST, 1);             //指定post数据
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);      //添加变量
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);      //返回结果
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
    }

    //修改车架号
    public function frame_number()
    {
        if ($this->request->isAjax()) {
            $params = $this->request->post();
            // pr($params);
            // die;
            $result = OrderDetails::where('id', $params['id'])->setField(['frame_number' => $params['frame_number']]);
            if ($result) {
                $this->success('修改成功');
            } else {
                $this->error();
            }
        }

    }

    //修改发动机号
    public function engine_number()
    {
        if ($this->request->isAjax()) {
            $params = $this->request->post();
            // pr($params);
            // die;
            $result = OrderDetails::where('id', $params['id'])->setField(['engine_number' => $params['engine_number']]);
            if ($result) {
                $this->success('修改成功');
            } else {
                $this->error();
            }
        }

    }

}
