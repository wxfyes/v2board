<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use \Curl\Curl;

class MGateQR {
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'mgate_url' => [
                'label' => 'API地址',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_app_id' => [
                'label' => 'APPID',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_app_secret' => [
                'label' => 'AppSecret',
                'description' => '',
                'type' => 'input',
            ],
            'mgate_source_currency' => [
                'label' => '源币种',
                'description' => '默认CNY',
                'type' => 'input'
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url']
        ];
        if (isset($this->config['mgate_source_currency'])) {
            $params['source_currency'] = $this->config['mgate_source_currency'];
        }
        $params['app_id'] = $this->config['mgate_app_id'];
        ksort($params);
        $str = http_build_query($params) . $this->config['mgate_app_secret'];
        $params['sign'] = md5($str);
        $curl = new Curl();
        $curl->setUserAgent('MGate');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->post($this->config['mgate_url'] . '/v1/gateway/fetch', http_build_query($params));
        $result = $curl->response;
        if (!$result) {
            abort(500, '网络异常');
        }
        if ($curl->error) {
            if (isset($result->errors)) {
                $errors = (array)$result->errors;
                abort(500, $errors[array_keys($errors)[0]][0]);
            }
            if (isset($result->message)) {
                abort(500, $result->message);
            }
            abort(500, '未知错误');
        }
        $curl->close();
        if (!isset($result->data->trade_no)) {
            abort(500, '接口调用失败');
        }
        
        // 尝试获取原生二维码内容，如果没有，则降级使用 pay_url 并在前端强行当做二维码渲染（部分网关的 pay_url 其实是一串 scheme）
        $qrData = $result->data->qrcode ?? ($result->data->qr_code ?? $result->data->pay_url);
        
        return [
            'type' => 0, // 强制改为 0，弹窗出码
            'data' => $qrData
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        ksort($params);
        reset($params);
        $str = http_build_query($params) . $this->config['mgate_app_secret'];
        if ($sign !== md5($str)) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }
}
