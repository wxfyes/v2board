<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = null, bool $bubble = true)
    {
        $level = $level ?? (\class_exists(\Monolog\Level::class) ? \Monolog\Level::Debug : 100);
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord|array $record): void
    {
        try {
            $data = $record instanceof LogRecord ? $record->toArray() : $record;
            if (isset($data['context']['exception']) && is_object($data['context']['exception'])) {
                $data['context']['exception'] = (array)$data['context']['exception'];
            }
            $data['request_data'] = request()->all() ?? [];
            $levelName = $record instanceof LogRecord ? $record->level->getName() : ($data['level_name'] ?? 'DEBUG');
            $createdAt = isset($data['datetime']) ? strtotime((string)$data['datetime']) : time();

            $log = [
                'title' => $data['message'] ?? '',
                'level' => $levelName,
                'host' => $data['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri' => $data['request_uri'] ?? request()->getRequestUri(),
                'method' => $data['request_method'] ?? request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => json_encode($data['request_data']),
                'context' => isset($data['context']) ? json_encode($data['context']) : '',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            LogModel::insert(
                $log
            );
        } catch (\Exception $e) {
            Log::channel('daily')->error($e->getMessage() . $e->getFile() . $e->getTraceAsString());
        }
    }
}
