<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $table = 'v2_ticket_message';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    /**
     * 获取图片列表
     */
    public function getImagesAttribute($value)
    {
        if (empty($value)) {
            return [];
        }
        
        if (is_array($value)) {
            return $value;
        }
        
        try {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded ?: [];
            } else {
                // 如果不是有效的JSON，可能是旧格式的字符串
                return [$value];
            }
        } catch (\Exception $e) {
            // 如果解析失败，返回空数组
            \Log::warning('Failed to decode images JSON', [
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 设置图片列表
     */
    public function setImagesAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['images'] = json_encode($value);
        } else {
            $this->attributes['images'] = $value;
        }
    }

    /**
     * 添加图片到消息
     */
    public function addImage($imagePath)
    {
        $images = $this->images;
        $images[] = $imagePath;
        $this->images = $images;
        return $this;
    }

    /**
     * 获取图片的完整URL（缩略图）
     */
    public function getThumbnailUrls()
    {
        $images = $this->images;
        $urls = [];
        
        foreach ($images as $image) {
            // 如果是旧格式（只有原图路径），返回原图
            if (is_string($image)) {
                $urls[] = url('/api/user/ticket/image/' . base64_encode($image));
            } else {
                // 新格式：包含原图和缩略图
                $thumbnailPath = $image['thumbnail_path'] ?? $image['path'];
                $urls[] = url('/api/user/ticket/image/' . base64_encode($thumbnailPath));
            }
        }
        
        return $urls;
    }

    /**
     * 获取图片的完整URL（原图）
     */
    public function getOriginalImageUrls()
    {
        $images = $this->images;
        $urls = [];
        
        foreach ($images as $image) {
            // 如果是旧格式（只有原图路径），返回原图
            if (is_string($image)) {
                $urls[] = url('/api/user/ticket/image/' . base64_encode($image));
            } else {
                // 新格式：包含原图和缩略图
                $originalPath = $image['original_path'] ?? $image['path'];
                $urls[] = url('/api/user/ticket/image/' . base64_encode($originalPath));
            }
        }
        
        return $urls;
    }

    /**
     * 获取图片的完整URL（兼容旧版本）
     */
    public function getImageUrls()
    {
        return $this->getThumbnailUrls();
    }
}
