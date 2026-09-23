<?php

namespace App\Payments;

class EPay
{
    protected $config;
    public function __construct($config){$this->config=$config;}
    public function form(){return [
        'url'=>['label'=>'支付网关URL','description'=>'支付系统接口地址','type'=>'input'],
        'pid'=>['label'=>'商户PID','description'=>'支付平台分配的商户ID','type'=>'input'],
        'key'=>['label'=>'签名密钥','description'=>'支付平台分配的通信密钥','type'=>'input'],
        'type'=>['label'=>'支付方式','description'=>'可选值：alipay/wxpay/USDT_TRC20','type'=>'input'],
        'return_domain'=>['label'=>'自定义跳转域名','description'=>'留空使用默认域名','type'=>'input'],
        'min_amount'=>['label'=>'最小付款金额（元）','description'=>'留空或0表示不限制','type'=>'input'],
        'max_amount'=>['label'=>'最大付款金额（元）','description'=>'留空或0表示不限制','type'=>'input'],
    ];}
    public function pay($order){
        $money=round(((int)$order['total_amount'])/100,2); $this->checkAmount($money);
        $returnUrl=$order['return_url']; if(!empty($this->config['return_domain'])){ $p=parse_url($returnUrl); $returnUrl=rtrim($this->config['return_domain'],'/').($p['path']??'').(isset($p['query'])?'?'.$p['query']:'').(isset($p['fragment'])?'#'.$p['fragment']:''); }
        $params=['money'=>number_format($money,2,'.',''),'name'=>$order['trade_no'],'notify_url'=>$order['notify_url'],'return_url'=>$returnUrl,'out_trade_no'=>$order['trade_no'],'pid'=>$this->config['pid']]; if(!empty($this->config['type']))$params['type']=$this->config['type']; ksort($params); $params['sign']=md5(urldecode(http_build_query($params)).$this->config['key']); $params['sign_type']='MD5'; return ['type'=>1,'data'=>rtrim($this->config['url'],'/').'/submit.php?'.http_build_query($params)];
    }
    public function notify($params){$sign=$params['sign']??'';unset($params['sign'],$params['sign_type']);ksort($params);if(!hash_equals(md5(urldecode(http_build_query($params)).$this->config['key']),$sign)||($params['trade_status']??'')!=='TRADE_SUCCESS')return false;return ['trade_no'=>$params['out_trade_no'],'callback_no'=>$params['trade_no']];}
    private function checkAmount($amount){$min=$this->amount('min_amount');$max=$this->amount('max_amount');if($min>0&&$amount<$min)abort(500,'最小支付金额是'.$this->fmt($min).'元');if($max>0&&$amount>$max)abort(500,'最大支付金额是'.$this->fmt($max).'元');if($min>0&&$max>0&&$min>$max)abort(500,'支付金额限制配置错误');}
    private function amount($key){$v=$this->config[$key]??0;if($v===''||$v===null)return 0.0;if(!is_numeric($v)||(float)$v<0)abort(500,'支付金额限制配置错误：'.$key);return round((float)$v,2);}
    private function fmt($v){return rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');}
}
