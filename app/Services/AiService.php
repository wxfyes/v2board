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

        $variables = [
            '{agent_name}' => config('v2board.ai_agent_name') ?: '官方智能客服',
            '{app_name}' => config('v2board.app_name', 'V2Board'),
            '{app_url}' => config('v2board.app_url', ''),
            '{current_time}' => date('Y-m-d H:i:s'),
            '{current_date}' => date('Y-m-d'),
            '{weekday}' => $weekday,
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

        // 4. 组装历史工单问答对 (Few-shot RAG 增强)
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

        foreach ($currentMessages as $msg) {
            $isUser = ((int)$msg->user_id !== 0 && (int)$msg->user_id === (int)$ticket->user_id);
            $messages[] = [
                'role' => $isUser ? 'user' : 'assistant',
                'content' => (string)$msg->message,
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
     * 召回最近完结的参考工单 (Few-shot 上下文)
     */
    protected function recallHistoryTickets(Ticket $currentTicket): string
    {
        $num = (int)config('v2board.ai_history_num', 5);
        if ($num <= 0) {
            return '';
        }

        $days = (int)config('v2board.ai_history_days', 90);
        $since = time() - ($days * 86400);

        // 查找已关闭 (status=1) 且有回复的历史优质工单
        $pastTickets = Ticket::where('status', 1)
            ->where('id', '!=', $currentTicket->id)
            ->where('updated_at', '>=', $since)
            ->orderBy('updated_at', 'DESC')
            ->limit($num)
            ->get();

        if ($pastTickets->isEmpty()) {
            return '';
        }

        $fewShotText = '';
        foreach ($pastTickets as $t) {
            $msgs = TicketMessage::where('ticket_id', $t->id)->orderBy('id', 'ASC')->get();
            if ($msgs->count() < 2) continue;

            $firstUserMsg = $msgs->firstWhere('user_id', $t->user_id);
            $lastAdminMsg = $msgs->reverse()->first(function ($m) use ($t) {
                return (int)$m->user_id === 0 || (int)$m->user_id !== (int)$t->user_id;
            });

            if ($firstUserMsg && $lastAdminMsg) {
                $q = mb_substr(trim($firstUserMsg->message), 0, 150);
                $a = mb_substr(trim($lastAdminMsg->message), 0, 200);
                $fewShotText .= "【问】: {$q}\n【答】: {$a}\n---\n";
            }
        }

        return trim($fewShotText);
    }

    /**
     * 获取预设的兜底系统提示词
     */
    protected function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
你是 {app_name} 的官方AI客服「{agent_name}」。当前时间：{current_time}（{weekday}）。

【当前用户信息】
- 用户邮箱：{user_email}
- 订阅套餐：{plan_name}
- 套餐到期：{expired_at}

【企业背景与知识说明】
{company_background}

【服务回复守则】
1. 语言亲切、简短专业、重点突出，直接切入问题核心，严禁大段无意义寒暄。
2. 若用户套餐已过期或无有效套餐，请礼貌提醒用户其套餐状态并引导前往商店选购。
3. 若属于常见排障（如节点不可用、测速超时、订阅更新失败），优先建议用户：
   - 检查客户端本地系统时间是否同步准确；
   - 更新订阅链接或切换不同节点/网络尝试；
   - 检查本地是否开启了其他代理软件冲突。
4. 若涉及账单退款、节点被墙大面积检修、复杂配置等无法自动化解决的事项，请明确告知：“已为您将工单流转至人工专席，人工客服将在上班后第一时间为您处理，请您耐心等待。”
PROMPT;
    }
}
