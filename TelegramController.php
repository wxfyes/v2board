<?php

namespace App\Http\Controllers\Guest;

use App\Services\TelegramService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        if ($request->query('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        // 添加调试日志
        Log::info('Telegram webhook 收到请求', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'token' => $request->query('access_token')
        ]);
        
        $inputData = $request->input();
        Log::info('Webhook输入数据', [
            'has_message' => isset($inputData['message']),
            'has_chat_join_request' => isset($inputData['chat_join_request']),
            'message_type' => $inputData['message']['new_chat_members'] ?? 'no_new_members',
            'chat_type' => $inputData['message']['chat']['type'] ?? 'unknown'
        ]);
        
        $this->formatMessage($inputData);
        $this->formatChatJoinRequest($inputData);
        $this->formatNewChatMember($inputData);
        $this->handle();
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;
        
        // 添加调试日志
                    Log::info('Telegram消息处理', [
                'message_type' => $msg->message_type ?? 'unknown',
                'chat_id' => $msg->chat_id ?? 'unknown',
                'command' => $msg->command ?? 'none',
                'reply_text' => $msg->reply_text ?? 'none',
                'file_id' => $msg->file_id ?? 'none',
                'message_thread_id' => $msg->message_thread_id ?? 'none'
            ]);
        
        // 安全检查：确保 message_type 属性存在
        if (!isset($msg->message_type)) {
            Log::warning('消息对象缺少 message_type 属性', [
                'chat_id' => $msg->chat_id ?? 'unknown',
                'message_id' => $msg->message_id ?? 'unknown'
            ]);
            return;
        }
        
        // 如果是新成员加入事件，已经在formatNewChatMember中处理过了，跳过命令处理
        if ($msg->message_type === 'new_chat_members') {
            Log::info('新成员事件已在formatNewChatMember中处理，跳过命令处理');
            return;
        }
        
        // 如果是论坛话题创建事件或其他不需要命令处理的事件，直接返回
        if (in_array($msg->message_type, ['forum_topic_created', 'unknown'])) {
            Log::info('跳过不需要命令处理的消息类型', [
                'message_type' => $msg->message_type,
                'chat_id' => $msg->chat_id
            ]);
            return;
        }
        
        $commandName = explode('@', $msg->command ?? '');

        // To reduce request, only commands contains @ will get the bot name
        if (count($commandName) == 2) {
            $botName = $this->telegramService->getBotName();
            if ($commandName[1] === $botName){
                $msg->command = $commandName[0];
            }
        }

        try {
            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                
                // 处理文本消息
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    Log::info('处理文本命令', ['command' => $msg->command]);
                    $instance->handle($msg);
                    return;
                }
                
                // 处理回复消息（文本）
                if ($msg->message_type === 'reply_message') {
                    Log::info('发现文本回复消息', [
                        'command' => $command,
                        'has_regex' => isset($instance->regex),
                        'regex' => $instance->regex ?? 'none',
                        'reply_text' => $msg->reply_text
                    ]);
                    
                    if (!isset($instance->regex)) {
                        Log::info('命令实例没有regex属性，跳过', ['command' => $command]);
                        continue;
                    }
                    
                    $matches = [];
                    if (!preg_match($instance->regex, $msg->reply_text, $matches)) {
                        Log::info('正则表达式匹配失败', [
                            'command' => $command,
                            'regex' => $instance->regex,
                            'reply_text' => $msg->reply_text
                        ]);
                        continue;
                    }
                    
                    Log::info('处理文本回复', [
                        'command' => $command,
                        'reply_text' => $msg->reply_text, 
                        'match' => $matches
                    ]);
                    $instance->handle($msg, $matches);
                    return;
                }
                
                // 处理回复消息（图片）
                if ($msg->message_type === 'reply_photo') {
                    Log::info('发现图片回复消息', [
                        'command' => $command,
                        'has_regex' => isset($instance->regex),
                        'regex' => $instance->regex ?? 'none',
                        'reply_text' => $msg->reply_text,
                        'file_id' => $msg->file_id
                    ]);
                    
                    if (!isset($instance->regex)) {
                        Log::info('命令实例没有regex属性，跳过', ['command' => $command]);
                        continue;
                    }
                    
                    $matches = [];
                    if (!preg_match($instance->regex, $msg->reply_text, $matches)) {
                        Log::info('正则表达式匹配失败', [
                            'command' => $command,
                            'regex' => $instance->regex,
                            'reply_text' => $msg->reply_text
                        ]);
                        continue;
                    }
                    
                    Log::info('处理图片回复', [
                        'command' => $command,
                        'reply_text' => $msg->reply_text, 
                        'match' => $matches,
                        'file_id' => $msg->file_id
                    ]);
                    $instance->handle($msg, $matches);
                    return;
                }
                
                // 处理群组话题消息
                if ($msg->message_type === 'group_topic_message') {
                    Log::info('发现群组话题消息', [
                        'command' => $command,
                        'has_regex' => isset($instance->regex),
                        'regex' => $instance->regex ?? 'none',
                        'text' => $msg->text ?? 'none',
                        'message_thread_id' => $msg->message_thread_id ?? 'none'
                    ]);
                    
                    if (!isset($instance->regex)) {
                        Log::info('命令实例没有regex属性，跳过', ['command' => $command]);
                        continue;
                    }
                    
                    $matches = [];
                    if (!preg_match($instance->regex, $msg->text, $matches)) {
                        Log::info('正则表达式匹配失败', [
                            'command' => $command,
                            'regex' => $instance->regex,
                            'text' => $msg->text
                        ]);
                        continue;
                    }
                    
                    Log::info('处理群组话题消息', [
                        'command' => $command,
                        'text' => $msg->text, 
                        'match' => $matches,
                        'message_thread_id' => $msg->message_thread_id
                    ]);
                    $instance->handle($msg, $matches);
                    return;
                }
                
                // 处理群组话题图片消息
                if ($msg->message_type === 'group_topic_photo') {
                    Log::info('发现群组话题图片消息', [
                        'command' => $command,
                        'has_regex' => isset($instance->regex),
                        'regex' => $instance->regex ?? 'none',
                        'caption' => $msg->caption ?? 'none',
                        'file_id' => $msg->file_id ?? 'none',
                        'message_thread_id' => $msg->message_thread_id ?? 'none'
                    ]);
                    
                    if (!isset($instance->regex)) {
                        Log::info('命令实例没有regex属性，跳过', ['command' => $command]);
                        continue;
                    }
                    
                    $matches = [];
                    // 对于图片消息，使用caption进行正则匹配，如果没有caption则使用空字符串
                    $textToMatch = $msg->caption ?? '';
                    if (!preg_match($instance->regex, $textToMatch, $matches)) {
                        Log::info('正则表达式匹配失败', [
                            'command' => $command,
                            'regex' => $instance->regex,
                            'caption' => $textToMatch
                        ]);
                        continue;
                    }
                    
                    Log::info('处理群组话题图片消息', [
                        'command' => $command,
                        'caption' => $textToMatch, 
                        'match' => $matches,
                        'file_id' => $msg->file_id,
                        'message_thread_id' => $msg->message_thread_id
                    ]);
                    $instance->handle($msg, $matches);
                    return;
                }
            }
            
            Log::info('没有找到匹配的命令处理器', [
                'message_type' => $msg->message_type,
                'command' => $msg->command ?? 'none'
            ]);
        } catch (\Exception $e) {
            Log::error('Telegram消息处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
        }
    }



    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        
        $obj = new \StdClass();
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        $obj->from_id = $data['message']['from']['id']; // 添加发送者ID
        
        // 添加调试信息
        Log::info('Telegram 原始消息数据', [
            'chat_id' => $obj->chat_id,
            'message_id' => $obj->message_id,
            'chat_type' => gettype($obj->chat_id),
            'message_type' => gettype($obj->message_id),
            'raw_message_id' => $data['message']['message_id'] ?? 'not_set'
        ]);
        
        // 添加话题ID支持
        if (isset($data['message']['message_thread_id'])) {
            $obj->message_thread_id = $data['message']['message_thread_id'];
        }
        
        // 检查新成员加入事件
        if (isset($data['message']['new_chat_members'])) {
            $obj->message_type = 'new_chat_members';
            $obj->new_chat_members = $data['message']['new_chat_members'];
            Log::info('检测到新成员加入事件', [
                'chat_id' => $obj->chat_id,
                'new_members_count' => count($data['message']['new_chat_members'])
            ]);
            $this->msg = $obj;
            return;
        }
        
        // 添加原始数据调试
        Log::info('Telegram原始消息数据', [
            'has_text' => isset($data['message']['text']),
            'has_photo' => isset($data['message']['photo']),
            'has_reply_to_message' => isset($data['message']['reply_to_message']),
            'reply_to_message_text' => $data['message']['reply_to_message']['text'] ?? 'none'
        ]);
        
        // 处理图片消息（优先处理，因为图片消息可能同时包含caption文本）
        if (isset($data['message']['photo'])) {
            $obj->photo = $data['message']['photo'];
            $obj->caption = $data['message']['caption'] ?? '';
            
            // 获取最大尺寸的图片
            $maxPhoto = null;
            $maxSize = 0;
            foreach ($data['message']['photo'] as $photo) {
                if (isset($photo['file_size']) && $photo['file_size'] > $maxSize) {
                    $maxSize = $photo['file_size'];
                    $maxPhoto = $photo;
                }
            }
            
            if ($maxPhoto) {
                $obj->file_id = $maxPhoto['file_id'];
                $obj->file_size = $maxPhoto['file_size'] ?? 0;
                $obj->width = $maxPhoto['width'] ?? 0;
                $obj->height = $maxPhoto['height'] ?? 0;
            }
            
            // 检查是否是回复消息
            if (isset($data['message']['reply_to_message']['text'])) {
                $obj->message_type = 'reply_photo';
                $obj->reply_text = $data['message']['reply_to_message']['text'];
            } else {
                $obj->message_type = 'photo';
            }
            
            // 检查是否是群组中的话题消息
            if (!$obj->is_private && isset($obj->message_thread_id)) {
                $obj->message_type = 'group_topic_photo';
            }
        }
        
        // 处理纯文本消息（只有在没有图片的情况下）
        if (isset($data['message']['text']) && !isset($data['message']['photo'])) {
            $text = explode(' ', $data['message']['text']);
            $obj->command = $text[0];
            $obj->args = array_slice($text, 1);
            $obj->message_type = 'message';
            $obj->text = $data['message']['text'];
            
            if (isset($data['message']['reply_to_message']['text'])) {
                $obj->message_type = 'reply_message';
                $obj->reply_text = $data['message']['reply_to_message']['text'];
            }
            
            // 检查是否是群组中的话题消息
            if (!$obj->is_private && isset($obj->message_thread_id)) {
                $obj->message_type = 'group_topic_message';
            }
        }
        
        // 处理论坛话题创建事件
        if (isset($data['message']['forum_topic_created'])) {
            $obj->message_type = 'forum_topic_created';
            $obj->forum_topic = $data['message']['forum_topic_created'];
            Log::info('检测到论坛话题创建事件', [
                'chat_id' => $obj->chat_id,
                'topic_name' => $data['message']['forum_topic_created']['name'] ?? 'unknown'
            ]);
        }
        
        // 处理其他未识别的消息类型，设置默认的 message_type
        if (!isset($obj->message_type)) {
            $obj->message_type = 'unknown';
            Log::info('未识别的消息类型', [
                'chat_id' => $obj->chat_id,
                'message_id' => $obj->message_id,
                'available_keys' => array_keys($data['message'])
            ]);
        }
        
        Log::info('格式化后的消息对象', [
            'message_type' => $obj->message_type ?? 'unknown',
            'chat_id' => $obj->chat_id ?? 'unknown',
            'file_id' => $obj->file_id ?? 'none',
            'reply_text' => $obj->reply_text ?? 'none'
        ]);
        
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }

    /**
     * 处理新成员加入群组事件
     */
    private function formatNewChatMember(array $data)
    {
        // 添加详细的调试日志
        Log::info('检查新成员加入事件', [
            'has_message' => isset($data['message']),
            'has_new_chat_members' => isset($data['message']['new_chat_members']),
            'message_data' => $data['message'] ?? 'no_message'
        ]);
        
        if (!isset($data['message']['new_chat_members'])) {
            Log::info('没有新成员数据，退出处理');
            return;
        }
        
        if (!isset($data['message']['chat']['id'])) {
            Log::info('没有聊天ID，退出处理');
            return;
        }
        
        $chatId = $data['message']['chat']['id'];
        $newMembers = $data['message']['new_chat_members'];
        
        Log::info('发现新成员', [
            'chat_id' => $chatId,
            'new_members_count' => count($newMembers),
            'new_members' => $newMembers
        ]);
        
        // 过滤掉机器人自己
        $botUsername = $this->telegramService->getBotName();
        Log::info('机器人用户名', ['bot_username' => $botUsername]);
        
        $realNewMembers = array_filter($newMembers, function($member) use ($botUsername) {
            $isBot = isset($member['is_bot']) && $member['is_bot'];
            Log::info('检查成员', [
                'member_id' => $member['id'] ?? 'unknown',
                'first_name' => $member['first_name'] ?? 'unknown',
                'is_bot' => $isBot,
                'username' => $member['username'] ?? 'none'
            ]);
            return !$isBot;
        });
        
        Log::info('过滤后的真实新成员', [
            'count' => count($realNewMembers),
            'members' => $realNewMembers
        ]);
        
        if (empty($realNewMembers)) {
            Log::info('没有真实新成员，退出处理');
            return;
        }
        
        foreach ($realNewMembers as $member) {
            Log::info('准备发送欢迎消息', [
                'chat_id' => $chatId,
                'member' => $member
            ]);
            $this->sendWelcomeMessage($chatId, $member);
        }
    }

    /**
     * 发送欢迎消息
     */
    private function sendWelcomeMessage(int $chatId, array $member)
    {
        try {
            Log::info('开始发送欢迎消息', [
                'chat_id' => $chatId,
                'member_data' => $member
            ]);
            
            $firstName = $member['first_name'] ?? '';
            $lastName = $member['last_name'] ?? '';
            $fullName = trim($firstName . ' ' . $lastName);
            
            Log::info('成员姓名信息', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName
            ]);
            
            // 获取最新网址
            $latestUrl = config('v2board.app_url', 'https://qianmo.xin');
            Log::info('配置信息', ['app_url' => $latestUrl]);
            
            // 构建欢迎消息
            $welcomeMessage = "🎉🎉🎉欢迎" . $fullName . "\n\n";
            $welcomeMessage .= "☘️[最新地址](" . $latestUrl . ")\n";
            $welcomeMessage .= "🌳[全平台汉化点击即可](https://t.me/setlanguage/zh-hans-beta)\n";
            $welcomeMessage .= "🌲[点击我绑定账号](tg://resolve?domain=)\n";
            $welcomeMessage .= "*⚠️请不要使用提供的AppID登录iCloud！*";
            
            Log::info('构建的欢迎消息', [
                'message_length' => strlen($welcomeMessage),
                'message_preview' => substr($welcomeMessage, 0, 100)
            ]);
            
            // 发送欢迎消息
            Log::info('准备调用sendMessage', [
                'chat_id' => $chatId,
                'parse_mode' => 'markdown'
            ]);
            
            $result = $this->telegramService->sendMessage($chatId, $welcomeMessage, 'markdown');
            
            Log::info('欢迎消息发送成功', [
                'chat_id' => $chatId,
                'member_name' => $fullName,
                'member_id' => $member['id'] ?? 'unknown',
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('发送新成员欢迎消息失败', [
                'chat_id' => $chatId,
                'member_name' => $fullName ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
