<?php

return [
    // 邮件营销专用邮箱配置（独立于 V2Board 系统邮箱）
    // 建议使用单独的 Gmail 邮箱，避免影响系统邮件额度
    'mail' => [
        'host' => 'smtp.gmail.com',    // smtp.gmail.com
        'port' => '587',    // 587
        'username' => 'tianquee@gmail.com',    // your_marketing@gmail.com
        'password' => 'oeusvchgotspqgkb',    // Gmail 应用专用密码
        'encryption' => 'tls',    // tls
        'from_address' => 'tianquee@gmail.com'     // your_marketing@gmail.com
    ],

    // 用户注册xxx分钟没订单发送邮件
    'register' => [
        'time' => 10,                                 //分钟
        'title' => "天阙星河浪漫，不该止于等待。",                           //标题
        'content' => "在天阙的星图里，为您预留的这颗星正在闪烁。若错过了这次相遇，下一次重逢又要绕过好几个星系。趁现在，把这份美好带回家吧! \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"       //内容
    ],

    // 用户下单后xxx分钟未支付订单
    'unpaid' => [
        'time' => 10,                                    //分钟
        'title' => "轻轻地提醒您一下...",                             //标题
        'content' => "您挑选的专属通道已经为您预留好啦。时光匆匆，如果您还没准备好，这份心意可能就要先回仓休息了。在这里静候您的归来，温暖如初。 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"        //内容
    ],

    // 用户即将到期提醒
    'user_expire' => [
        [
            'title' => "🔔 您的星际航线-天阙加速即将到期",                
            'content' => "小主，您的专属航行权仅剩 7 天啦。为了不让信号迷失在深空，建议您提前补充能量（续费），让我们继续陪您探索世界 ✨\n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>",
        ],
        [
            'title' => "⚠️ 信号即将中断（仅剩24小时）",                
            'content' => "紧急播报！您的航线-天阙加速剩余不足 1 天，能量即将耗尽。请尽快接入补给站，别让我们的连接断开哦~ 🚀 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"  
        ]
    ],

    // 用户流量用尽
'flow_out' => [
        'title' => "动力室报告：流量已告罄",                      
        'content' => "报告小主，您的航线-天阙加速流量已消耗完毕。推进器已进入休眠模式，请前往控制台增加负载（重置流量），重启您的全速之旅！ \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
    ],

    // 用户已过期提醒（召回邮件）
'user_expired' => [
        [
            'days' => 7,
            'title' => "💌 一封来自星际的告白-天阙加速",
            'content' => "分开-天阙加速已经 7 天啦，信号塔还在固执地搜索您的频率。没有您的日子里，连连接速度都显得有些落寞。回来看看我们吗？ \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
        ],
        [
            'days' => 15,
            'title' => "🌙 很久没有收到您的信号了...-天阙加速",
            'content' => "半个月的时间里，我们升级了-天阙加速航线，优化了信号。一切准备就绪，唯独少了作为舰长的您。您的专属舱位一直为您保留着。 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
        ],
        [
            'days' => 30,
            'title' => "🍃 这一月的风，都在思念您-天阙加速",
            'content' => "您已经离开天阙 30 天了。如果是因为我们做得不够好，请一定告诉我们。如果只是累了，记得休息好了回来歇脚。 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
        ],
        [
            'days' => 60,
            'title' => "⏳ 岁月漫长，记得当初的约定吗？-天阙加速",
            'content' => "整整两个月，宇宙微波背景辐射里依然残留着您的痕迹。虽然断开了连接，但天阙加速阙的星光依然愿意为您引航。 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
        ],
        [
            'days' => 90,
            'title' => "🌌 山水有相逢，天阙永远是您的家",
            'content' => "三个月啦，或许您已经找到了新的星系落脚。但请记得，那个叫-天阙加速的地方，始终有一扇大门为您敞开。祝您在彼岸一切安好。 \n\n👉 <a href='https://www.tianque.cc' style='color:#415A94;font-weight:bold;'>点击此处立刻前往官网续费</a>"
        ]
    ]
];
