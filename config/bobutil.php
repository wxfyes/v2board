<?php

return [
    // 邮件营销专用邮箱配置（独立于 V2Board 系统邮箱）
    // 建议使用单独的 Gmail 邮箱，避免影响系统邮件额度
    'mail' => [
        'host' => 'smtp.gmail.com',    // smtp.gmail.com
        'port' => '587',    // 587
        'username' => 'tianquee@gmail.com',    // your_marketing@gmail.com
        'password' => 'nluu zrwy ivvx gtlx',    // Gmail 应用专用密码
        'encryption' => 'tls',    // tls
        'from_address' => 'tianquee@gmail.com'     // your_marketing@gmail.com
    ],

    // 用户注册xxx分钟没订单发送邮件
    'register' => [
        'time' => 10,                                 //分钟
        'title' => "赶快购买",                           //标题
        'content' => "你还不快买？还在等什么，等爱情吗？"       //内容
    ],

    // 用户下单后xxx分钟未支付订单
    'unpaid' => [
        'time' => 10,                                    //分钟
        'title' => "赶快付钱",                             //标题
        'content' => "你还不快买？还在等什么，等爱情吗？"         //内容
    ],

    // 用户即将到期提醒
    'user_expire' => [
        [
            'title' => "还有7天到期",                //标题
            'content' => "马上到期了，快点续费哦~"       //内容
        ],
        [
            'title' => "还有1天到期",                //标题
            'content' => "马上到期了，快点续费哦~"       //内容
        ]
    ],

    // 用户流量用尽
    'flow_out' => [
        'title' => "流量用完了",                     //标题
        'content' => "流量用完了，赶快充值",             //内容
    ],

    // 用户已过期提醒
    'user_expired' => [
        [
            'title' => "订单已过期7天",                 //标题
            'content' => "订单已过期7天了哦~"              //内容
        ],
        [
            'title' => "订单已过期15天",                //标题
            'content' => "订单已过期15天了哦~"             //内容
        ]
    ]
];
