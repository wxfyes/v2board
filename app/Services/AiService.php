<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    /**
     * 判断当前策略是否允许触发 AI 回复
     */
    public function isScheduleAllowed(): bool
    {
        if (!config('v2board.ai_reply_enable', 0)) {
            return false;
        }

        $apiKey = trim(config('v2board.ai_api_key', ''));
        if (empty($apiKey)) {
            return false;
        }

        $scheduleMode = config('v2board.ai_schedule_mode', 'all');
        if ($scheduleMode === 'night') {
            // 仅夜间/非工作时段运行：22:00 ~ 次日 08:00
            $currentHour = (int)date('G');
            if ($currentHour >= 8 && $currentHour < 22) {
                return false;
            }
        }

        return true;
    }

    /**
     * 为指定工单生成智能回复
     */
    public function generateReply(Ticket $ticket, TicketMessage $lastUserMsg): ?string
    {
        if (!$this->isScheduleAllowed()) {
            return null;
        }

        $user = User::find($ticket->user_id);
        if (!$user) {
            return null;
        }

        // 1. 获取用户套餐信息
        $planName = '无有效套餐';
        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            if ($plan) {
                $planName = $plan->name;
            }
        }

        $expiredAt = '永久有效 / 未激活';
        if ($user->expired_at) {
            $expiredAt = date('Y-m-d H:i:s', $user->expired_at);
            if ($user->expired_at < time()) {
                $expiredAt .= ' (已过期)';
            }
        }

        // 2. 格式化变量插值表
        $weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
        $weekday = $weekdays[(int)date('w')] ?? '';
        $nodeSummary = $this->getNodeSummary($user);

        $variables = [
            '{agent_name}' => config('v2board.ai_agent_name') ?: '官方智能客服',
            '{app_name}' => config('v2board.app_name', 'V2Board'),
            '{app_url}' => config('v2board.app_url', ''),
            '{current_time}' => date('Y-m-d H:i:s'),
            '{current_date}' => date('Y-m-d'),
            '{weekday}' => $weekday,
            '{node_summary}' => $nodeSummary,
            '{company_background}' => config('v2board.ai_company_bg', ''),
            '{user_email}' => $user->email,
            '{plan_name}' => $planName,
            '{expired_at}' => $expiredAt,
        ];

        // 3. 组装 System Prompt
        $systemPromptTemplate = (string)config('v2board.ai_system_prompt');
        if (empty(trim($systemPromptTemplate))) {
            $systemPromptTemplate = $this->getDefaultSystemPrompt();
        }

        $systemPrompt = str_replace(array_keys($variables), array_values($variables), $systemPromptTemplate);

        // 4. 组装历史工单问答对 (Few-shot RAG 增强，仅保留高相关工单)
        $historyFewShot = $this->recallHistoryTickets($ticket);

        // 5. 组装当前工单消息上下文
        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt . ($historyFewShot ? "\n\n### 历史工单解决案例参考：\n" . $historyFewShot : ''),
        ];

        $currentMessages = TicketMessage::where('ticket_id', $ticket->id)
            ->orderBy('id', 'ASC')
            ->get();

        $isFirstUserMsg = true;
        foreach ($currentMessages as $msg) {
            $isUser = ((int)$msg->user_id !== 0 && (int)$msg->user_id === (int)$ticket->user_id);
            $content = (string)$msg->message;

            // 核心修复：如果是用户第一条提问，务必将工单主题一并拼接，防止用户仅输入“如题”导致 AI 丢失核心提问意图
            if ($isUser && $isFirstUserMsg) {
                $content = "【工单主题】: {$ticket->subject}\n【用户提问内容】: {$content}";
                $isFirstUserMsg = false;
            }

            $messages[] = [
                'role' => $isUser ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        // 6. 调用 OpenAI 兼容端点
        return $this->callLlmApi($messages);
    }

    /**
     * 调用大模型接口 (OpenAI 兼容协议 /v1/chat/completions)
     */
    protected function callLlmApi(array $messages): ?string
    {
        $apiUrl = trim(config('v2board.ai_api_url', 'https://api.openai.com/v1'));
        $apiKey = trim(config('v2board.ai_api_key', ''));
        $model = trim(config('v2board.ai_model', 'gpt-4o-mini'));
        $temperature = (float)config('v2board.ai_temperature', 0.3);
        $maxTokens = (int)config('v2board.ai_max_tokens', 1024);
        $timeout = (int)config('v2board.ai_timeout', 60);

        if (empty($apiUrl) || empty($apiKey) || empty($model)) {
            Log::warning('[AiService] 配置缺失，终止调用');
            return null;
        }

        // 规范化 API 终端路径
        $endpoint = rtrim($apiUrl, '/');
        if (!str_ends_with($endpoint, '/chat/completions')) {
            $endpoint .= '/chat/completions';
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => max(0.0, min(1.0, $temperature)),
            'max_tokens' => max(128, min(4096, $maxTokens)),
        ];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                $errPayload = [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $endpoint,
                    'model' => $model,
                ];
                Log::warning('[AiService] LLM 响应异常', $errPayload);
                try { Log::channel('single')->warning('[AiService] LLM 响应异常', $errPayload); } catch (\Throwable $e) {}
                return null;
            }

            $resData = $response->json();
            $content = $resData['choices'][0]['message']['content'] ?? null;

            if (empty($content) || !is_string($content)) {
                Log::warning('[AiService] LLM 返回文本为空', ['res' => $resData]);
                try { Log::channel('single')->warning('[AiService] LLM 返回文本为空', ['res' => $resData]); } catch (\Throwable $e) {}
                return null;
            }

            return trim($content);
        } catch (\Throwable $e) {
            $errPayload = [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
                'model' => $model,
            ];
            Log::error('[AiService] LLM 请求崩溃或超时: ' . $e->getMessage(), $errPayload);
            try { Log::channel('single')->error('[AiService] LLM 请求崩溃或超时: ' . $e->getMessage(), $errPayload); } catch (\Throwable $ex) {}
            return null;
        }
    }

    /**
     * 召回最近完结或已有回复的参考工单 (基于关键词相关性精准召回 Few-shot 上下文)
     */
    public function recallHistoryTickets(Ticket $currentTicket): string
    {
        $num = (int)config('v2board.ai_history_num', 5);
        if ($num <= 0) {
            return '';
        }

        $days = (int)config('v2board.ai_history_days', 180);
        $since = time() - ($days * 86400);

        // 提取工单主题中的关键词进行相关性匹配
        $subject = trim($currentTicket->subject);
        $cleanSubject = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $subject);
        $words = array_filter(explode(' ', $cleanSubject), fn($w) => mb_strlen($w) >= 2);

        // 中文支持：如果分词少于2个且主题长度>=2，生成2字中文滑动窗口词，排除无意义虚词
        if (count($words) <= 1 && mb_strlen($subject) >= 2) {
            $len = mb_strlen($subject);
            $stopWords = ['都有', '哪里', '里的', '怎么', '什么', '可以', '如题', '为什么', '这个', '那个', '一下', '如何', '请问'];
            for ($i = 0; $i < $len - 1; $i++) {
                $sub = mb_substr($subject, $i, 2);
                if (!in_array($sub, $stopWords)) {
                    $words[] = $sub;
                }
            }
            $words = array_unique($words);
        }

        if (empty($words)) {
            return '';
        }

        // 基础查询：只要已关闭 (status=1) 或管理员已回复过 (reply_status=1) 的工单均可作为样本
        $queryBase = Ticket::where('id', '!=', $currentTicket->id)
            ->where(function ($q) {
                $q->where('status', 1)->orWhere('reply_status', 1);
            })
            ->where('updated_at', '>=', $since);

        $matchedQuery = clone $queryBase;
        $matchedQuery->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('subject', 'like', "%{$word}%");
            }
        });
        $matchedTickets = $matchedQuery->orderBy('updated_at', 'DESC')->limit($num)->get();

        // 核心原则：宁缺毋滥！如果没有真正命中主题关键词的历史工单，绝不强行塞入不相干工单误导 AI
        if ($matchedTickets->isEmpty()) {
            return '';
        }

        $fewShotText = '';
        foreach ($matchedTickets as $t) {
            $msgs = TicketMessage::where('ticket_id', $t->id)->orderBy('id', 'ASC')->get();
            if ($msgs->count() < 2) continue;

            $firstUserMsg = $msgs->firstWhere('user_id', $t->user_id);
            $lastAdminMsg = $msgs->reverse()->first(function ($m) use ($t) {
                return (int)$m->user_id === 0 || (int)$m->user_id !== (int)$t->user_id;
            });

            if ($firstUserMsg && $lastAdminMsg) {
                $q = mb_substr(trim($firstUserMsg->message), 0, 150);
                $a = mb_substr(trim($lastAdminMsg->message), 0, 250);
                $fewShotText .= "【问题主题】: {$t->subject}\n【用户提问】: {$q}\n【管理员历史答复】: {$a}\n---\n";
            }
        }

        return trim($fewShotText);
    }

    /**
     * 获取站点当前可用节点的地区与线路概览信息
     */
    public function getNodeSummary(User $user): string
    {
        try {
            $serverService = new ServerService();
            // 优先获取当前用户所在权限组的可用节点
            $servers = $serverService->getAvailableServers($user);
            // 若用户未购套餐或可用节点为空，获取全站启用的全部节点
            if (empty($servers)) {
                $servers = $serverService->getAllServers();
            }

            if (empty($servers)) {
                return '暂未配置节点。';
            }

            $names = [];
            foreach ($servers as $s) {
                if (isset($s['show']) && !$s['show']) continue;
                $name = trim($s['name'] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            $names = array_unique($names);
            if (empty($names)) {
                return '暂无可用节点。';
            }

            $regions = [];
            $regionMap = [
                '香港' => '香港 (HK)', 'HK' => '香港 (HK)', 'HongKong' => '香港 (HK)',
                '日本' => '日本 (JP)', 'JP' => '日本 (JP)', 'Japan' => '日本 (JP)', '东京' => '日本 (JP)', '大阪' => '日本 (JP)',
                '美国' => '美国 (US)', 'US' => '美国 (US)', 'USA' => '美国 (US)', '洛杉矶' => '美国 (US)',
                '新加坡' => '新加坡 (SG)', 'SG' => '新加坡 (SG)', 'Singapore' => '新加坡 (SG)', '狮城' => '新加坡 (SG)',
                '台湾' => '台湾 (TW)', 'TW' => '台湾 (TW)', 'Taiwan' => '台湾 (TW)', '台北' => '台湾 (TW)',
                '韩国' => '韩国 (KR)', 'KR' => '韩国 (KR)', 'Korea' => '韩国 (KR)', '首尔' => '韩国 (KR)',
                '英国' => '英国 (UK)', 'UK' => '英国 (UK)', '伦敦' => '英国 (UK)',
                '德国' => '德国 (DE)', 'DE' => '德国 (DE)', '法兰克福' => '德国 (DE)',
                '法国' => '法国 (FR)', 'FR' => '法国 (FR)',
                '加拿大' => '加拿大 (CA)', 'CA' => '加拿大 (CA)',
                '澳大利亚' => '澳大利亚 (AU)', '澳洲' => '澳大利亚 (AU)', 'AU' => '澳大利亚 (AU)', '悉尼' => '澳大利亚 (AU)',
                '土耳其' => '土耳其 (TR)', 'TR' => '土耳其 (TR)',
                '阿根廷' => '阿根廷 (AR)', 'AR' => '阿根廷 (AR)',
                '俄罗斯' => '俄罗斯 (RU)', 'RU' => '俄罗斯 (RU)', '莫斯科' => '俄罗斯 (RU)',
                '马来西亚' => '马来西亚 (MY)', 'MY' => '马来西亚 (MY)',
                '泰国' => '泰国 (TH)', 'TH' => '泰国 (TH)',
                '菲律宾' => '菲律宾 (PH)', 'PH' => '菲律宾 (PH)',
                '越南' => '越南 (VN)', 'VN' => '越南 (VN)',
                '印度' => '印度 (IN)', 'IN' => '印度 (IN)',
            ];

            foreach ($names as $name) {
                foreach ($regionMap as $kw => $label) {
                    if (stripos($name, $kw) !== false) {
                        $regions[$label] = true;
                        break;
                    }
                }
            }

            $regionList = !empty($regions) ? implode('、', array_keys($regions)) : '主流海外国家与地区';
            $sampleNodes = array_slice($names, 0, 6);
            $sampleStr = implode(', ', $sampleNodes);
            $total = count($names);

            return "【当前节点覆盖地区】：{$regionList} 等，全站共上线约 {$total} 条线路（包含 {$sampleStr}... 等）。";
        } catch (\Throwable $e) {
            Log::warning('[AiService] 获取节点概况失败: ' . $e->getMessage());
            return '本站节点覆盖香港、日本、美国、新加坡、台湾等多个国家与地区线路。';
        }
    }

    /**
     * 获取预设的兜底系统提示词
     */
    protected function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
你是 {app_name} 的官方智能客服「{agent_name}」。当前时间：{current_time}（{weekday}）。

【当前用户信息】
- 用户邮箱：{user_email}
- 订阅套餐：{plan_name}
- 套餐到期：{expired_at}

【当前站点节点资源概况】
{node_summary}

【企业背景与知识说明】
{company_background}

【服务回复守则】
1. 【仔细审题，精准识别意图】：
   - 业务咨询类（如询问“都有哪些地区的节点”、“有哪些套餐”、“如何购买/续费”等）：直接根据上方【节点资源概况】与【企业知识说明】据实、详尽地回答，严禁将正常的业务咨询误判为网络故障排障！
   - 故障报修类（如反馈“连不上”、“节点全红/超时”、“客户端报错”等）：才引导用户排查本地网络与客户端。
2. 语言亲切、简短专业、重点突出，直接切入问题核心，严禁大段无意义寒暄。
3. 若用户套餐已过期或无有效套餐，请礼貌提醒用户其套餐状态并引导前往商店选购。
4. 若属于常见使用故障（如节点不可用、测速超时、订阅更新失败），优先建议用户：
   - 检查客户端本地系统时间是否同步准确；
   - 更新订阅链接或切换不同节点/网络尝试；
   - 检查本地是否开启了其他代理软件冲突。
5. 若涉及账单退款、节点被墙大面积检修、复杂配置等无法自动化解决的事项，请明确告知：“已为您将工单流转至人工专席，人工客服将在上班后第一时间为您处理，请您耐心等待。”
6. 【历史工单优先遵循】：若下方附带有历史工单参考案例，且案例中包含当前相同类似问题的解决方案，请优先参考过往管理员的答复风格与排障方案作答。
PROMPT;
    }
}
