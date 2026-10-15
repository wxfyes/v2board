<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TicketAiTest extends Command
{
    protected $signature = 'ticket:ai-test {ticketId?}';
    protected $description = '一键测试 AI 工单客服连通性与回复功能';

    public function handle()
    {
        $this->info("==========================================");
        $this->info("   V2Board AI 客服健康诊断与自检工具");
        $this->info("==========================================");

        $enable = config('v2board.ai_reply_enable', 0);
        $apiUrl = config('v2board.ai_api_url', '');
        $apiKey = config('v2board.ai_api_key', '');
        $model = config('v2board.ai_model', '');
        $waitTime = config('v2board.ai_wait_time', 5);

        $maskedKey = empty($apiKey) ? '未配置 (空)' : (substr($apiKey, 0, 7) . '...' . substr($apiKey, -4));

        $this->table(
            ['配置项', '当前读取值', '状态'],
            [
                ['ai_reply_enable', $enable ? '1 (开启)' : '0 (关闭)', $enable ? '正常' : '⚠️ 已关闭'],
                ['ai_api_url', $apiUrl ?: '未配置', $apiUrl ? '正常' : '❌ 缺失'],
                ['ai_model', $model ?: '未配置', $model ? '正常' : '❌ 缺失'],
                ['ai_api_key', $maskedKey, $apiKey ? '正常' : '❌ 缺失'],
                ['ai_wait_time', "{$waitTime} 分钟", $waitTime == 0 ? '立即秒回' : "需等待 {$waitTime} 分钟"],
            ]
        );

        if (empty($apiUrl) || empty($apiKey) || empty($model)) {
            $this->error("❌ 核心配置不完整，请先在后台【系统配置 -> AI客服】中填齐保存！");
            return 1;
        }

        // 1. 直连连通性测试
        $this->info("\n[步骤 1/2] 正在直接测试大模型端点连通性...");
        $endpoint = rtrim($apiUrl, '/');
        if (!str_ends_with($endpoint, '/chat/completions')) {
            $endpoint .= '/chat/completions';
        }
        $this->line("请求地址: {$endpoint}");
        $this->line("调用模型: {$model}");

        try {
            $start = microtime(true);
            $res = Http::timeout(25)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => '你好，请回复“连接成功”四个字以完成测试。']
                    ],
                    'max_tokens' => 50,
                ]);

            $elapsed = round(microtime(true) - $start, 2);

            $this->line("HTTP 状态码: " . $res->status() . " (耗时: {$elapsed}s)");

            if (!$res->successful()) {
                $this->error("❌ 请求失败！服务端返回错误：");
                $this->line($res->body());

                // 自动拉取该服务商为当前 API Key 提供的可用模型列表
                try {
                    $modelsEndpoint = preg_replace('/\/chat\/completions$/', '/models', $endpoint);
                    $modelsRes = Http::timeout(10)
                        ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                        ->get($modelsEndpoint);
                    if ($modelsRes->successful()) {
                        $modelList = $modelsRes->json('data') ?? [];
                        $modelIds = array_column($modelList, 'id');
                        $this->warn("\n💡 该 API Key 在当前平台实际支持调用的模型列表（共 " . count($modelIds) . " 个）：");
                        $this->line(implode("\n", array_map(fn($id) => " - " . $id, array_slice($modelIds, 0, 15))));
                    }
                } catch (\Throwable $e) {}

                return 1;
            }

            $json = $res->json();
            $replyContent = $json['choices'][0]['message']['content'] ?? null;
            $this->info("✅ 端点连通测试成功！");
            $this->line("AI 原始返回: " . trim($replyContent));
        } catch (\Throwable $e) {
            $this->error("❌ 连接抛出异常: " . $e->getMessage());
            return 1;
        }

        // 2. 模拟真实工单业务回复
        $this->info("\n[步骤 2/2] 正在测试工单业务回复链条...");
        $ticketId = $this->argument('ticketId');
        if (!$ticketId) {
            $ticket = Ticket::where('status', 0)->orderBy('id', 'desc')->first();
            if (!$ticket) {
                $ticket = Ticket::orderBy('id', 'desc')->first();
            }
        } else {
            $ticket = Ticket::find($ticketId);
        }

        if (!$ticket) {
            $this->warn("⚠️ 系统中尚未找到任何工单，跳过工单业务生成测试。");
            return 0;
        }

        $lastMsg = TicketMessage::where('ticket_id', $ticket->id)->orderBy('id', 'desc')->first();
        if (!$lastMsg) {
            $this->warn("⚠️ 工单 #{$ticket->id} 中没有消息。");
            return 0;
        }

        $this->line("选中工单 ID: #{$ticket->id} (主题: {$ticket->subject})");
        $this->line("用户最后提问: {$lastMsg->message}");

        $aiService = new AiService();

        $historyFewShot = $aiService->recallHistoryTickets($ticket);
        if (!empty($historyFewShot)) {
            $this->info("\n📚 [历史工单召回] 成功提取到历史参考案例：");
            $this->line("--------------------------------------------------");
            $this->line($historyFewShot);
            $this->line("--------------------------------------------------");
        } else {
            $this->warn("\n📚 [历史工单召回] 未检索到符合条件的历史工单（条件：已结单或管理员已回且在180天内）");
        }

        $reply = $aiService->generateReply($ticket, $lastMsg);

        if (empty($reply)) {
            $this->error("❌ generateReply 未能生成回复内容，请检查是否有策略限制或错误日志。");
            return 1;
        }

        $this->info("✅ 工单回复生成成功！预览如下：");
        $this->line("--------------------------------------------------");
        $this->line($reply);
        $this->line("--------------------------------------------------");

        $this->info("\n🎉 诊断全部通过！AI 客服核心引擎工作完全正常。");
        return 0;
    }
}
