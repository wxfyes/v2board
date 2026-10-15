<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\AiService;
use App\Services\TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TicketAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ticketId;
    protected $targetMessageId;

    public $tries = 1; // 单次执行，失败不重试以防死循环打扰用户
    public $timeout = 120;

    public function __construct(int $ticketId, ?int $targetMessageId = null)
    {
        $this->onQueue('send_telegram');
        $this->ticketId = $ticketId;
        $this->targetMessageId = $targetMessageId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // 1. 检查全局总开关
        if (!config('v2board.ai_reply_enable', 0)) {
            return;
        }

        $ticket = Ticket::find($this->ticketId);
        if (!$ticket || $ticket->status !== 0) {
            // 工单不存在或已被结单
            return;
        }

        // 2. 检查工单最新一条消息
        $lastMsg = TicketMessage::where('ticket_id', $ticket->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$lastMsg) {
            return;
        }

        // 互斥安全原则：若最新一条消息已由管理员或客服回复，AI立即退避！
        if ((int)$lastMsg->user_id === 0 || (int)$lastMsg->user_id !== (int)$ticket->user_id) {
            $info = "[TicketAiReplyJob] 工单 #{$ticket->id} 已由管理员/客服回复，AI主动退避";
            Log::info($info);
            try { Log::channel('single')->info($info); } catch (\Throwable $e) {}
            return;
        }

        // 3. 检查最长等待时间 (防止队列积压几小时后突然回复挖坟)
        $maxWaitTime = (int)config('v2board.ai_max_wait_time', 60);
        if ($maxWaitTime > 0) {
            $msgTimestamp = is_numeric($lastMsg->created_at) ? (int)$lastMsg->created_at : strtotime($lastMsg->created_at);
            if ($msgTimestamp > 0) {
                $elapsedMinutes = (time() - $msgTimestamp) / 60;
                if ($elapsedMinutes > $maxWaitTime) {
                    $warn = "[TicketAiReplyJob] 工单 #{$ticket->id} 堆积等待超过 {$maxWaitTime} 分钟，为防挖坟自动放弃AI回复，留待人工";
                    Log::warning($warn);
                    try { Log::channel('single')->warning($warn); } catch (\Throwable $e) {}
                    return;
                }
            }
        }

        // 4. 调用 AiService 生成回复
        $aiService = new AiService();
        $replyText = $aiService->generateReply($ticket, $lastMsg);

        if (empty($replyText)) {
            $info = "[TicketAiReplyJob] 工单 #{$ticket->id} AI 未生成有效回复内容，静默退出";
            Log::info($info);
            try { Log::channel('single')->info($info); } catch (\Throwable $e) {}
            return;
        }

        // 5. 写入工单回复
        $aiUserId = (int)config('v2board.ai_user_id', 0);
        if ($aiUserId <= 0) {
            $adminUser = \App\Models\User::where('is_admin', 1)->first();
            $aiUserId = $adminUser ? $adminUser->id : 1;
        }
        $sendEmail = (bool)config('v2board.ai_email_notify', 0);
        $ticketService = new TicketService();
        $ticketService->replyByAdmin($ticket->id, $replyText, $aiUserId, $sendEmail);

        $successPayload = [
            'ticket_id' => $ticket->id,
            'reply_preview' => mb_substr($replyText, 0, 80),
        ];
        Log::info("[TicketAiReplyJob] 工单 #{$ticket->id} AI 自动回复成功", $successPayload);
        try { Log::channel('single')->info("[TicketAiReplyJob] 工单 #{$ticket->id} AI 自动回复成功", $successPayload); } catch (\Throwable $e) {}
    }
}
