<?php
/**
 *
 * @author: xaboy<365615158@qq.com>
 * @day: 2017/12/11
 */

namespace basic;


use app\wap\model\user\User;
use app\wap\model\user\WechatUser;
use behavior\wap\WapBehavior;
use service\JsonService;
use think\Controller;
use behavior\wechat\UserBehavior;
use service\HookService;
use service\UtilService;
use service\WechatService;
use think\Cookie;
use think\Request;
use think\Session;
use think\Url;

class WapBasic extends Controller 
{
    protected function _initialize()
    {

        parent::_initialize(); // TODO: Change the autogenerated stub
        $spreadUid = Request::instance()->route('spuid',0);
        if($spreadUid
            && ($userInfo = User::getUserInfo(WechatUser::openidToUid($this->oauth())))
            && !$userInfo['spread_uid']
            && $userInfo['uid'] != $spreadUid
        ) User::edit(['spread_uid'=>$spreadUid],$userInfo['uid'],'uid');
        HookService::listen('wap_init',null,null,false,WapBehavior::class);
    }

    /**
     * 操作失败 弹窗提示
     * @param string $msg
     * @param int $url
     * @param string $title
     */
    protected function failed($msg = '操作失败', $url = 0, $title='信息提示')
    {

        if($this->request->isAjax()){
            exit(JsonService::fail($msg,$url)->getContent());
        }else {
            $this->assign(compact('title', 'msg', 'url'));
            exit($this->fetch('public/error'));
        }
    }

    /**
     * 操作成功 弹窗提示
     * @param $msg
     * @param int $url
     */
    protected function successful($msg = '操作成功', $url = 0, $title='成功提醒')
    {
        if($this->request->isAjax()){
            exit(JsonService::successful($msg,$url)->getContent());
        }else {
            $this->assign(compact('title', 'msg', 'url'));
            exit($this->fetch('public/success'));
        }
    }

    public function _empty($name)
    {
        $url = strtolower($name) == 'index' ? Url::build('Index/index','',true,true) : 0;
        return $this->failed('请求页面不存在!',$url);
    }

    /**
     * 微信用户自动登陆
     * @return string $openid
     */
    protected function oauth($spread_uid=0)
    {
        $openid = Session::get('loginOpenid','wap');
        if($openid) return $openid;
        if(!UtilService::isWechatBrowser()) exit($this->failed('请在微信客户端打开链接'));
        if($this->request->isAjax()) exit($this->failed('请登陆!'));
        $errorNum = (int)Cookie::get('_oen');
        if($errorNum && $errorNum > 3) exit($this->failed('微信用户信息获取失败!!'));
        try{
            dd($this->request->baseUrl(true));
            $wechatInfo = WechatService::oauthService()->user()->getOriginal();
        }catch (\Exception $e){
            Cookie::set('_oen',++$errorNum,900);
            exit(WechatService::oauthService()->scopes(['snsapi_base'])
                ->redirect($this->request->url(true))->send());
        }
        if(!isset($wechatInfo['nickname'])){
            $wechatInfo = WechatService::getUserInfo($wechatInfo['openid']);
            if(!$wechatInfo['subscribe'] && !isset($wechatInfo['nickname']))
                exit(WechatService::oauthService()->scopes(['snsapi_userinfo'])
                    ->redirect($this->request->url(true))->send());
            if(isset($wechatInfo['tagid_list']))
                $wechatInfo['tagid_list'] = implode(',',$wechatInfo['tagid_list']);
        }else{
            if(isset($wechatInfo['privilege'])) unset($wechatInfo['privilege']);
            $wechatInfo['subscribe'] = 0;
        }
        Cookie::delete('_oen');
        $openid = $wechatInfo['openid'];
        $wechatInfo['spread_uid']=$spread_uid;
        HookService::afterListen('wechat_oauth',$openid,$wechatInfo,false,UserBehavior::class);
        Session::set('loginOpenid',$openid,'wap');
        Cookie::set('is_login',1);
        return $openid;
    }

}