<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        // System settings
        'system_title',
        'system_short_title',
        'system_logo',
        'system_favicon',
        'company_name',
        'company_address',
        'tagline',
        'phone',
        'email',
        'timezone',
        'language',
        'copyright_text',

        // Admin settings
        'admin_title',
        'short_title',
        'admin_logo',
        'admin_favicon',
        'admin_copyright_text',
    ];

    protected $appends = [
        'system_logo_url',
        'system_favicon_url',
        'admin_logo_url',
        'admin_favicon_url',
    ];

    /**
     * Get or create the first settings record
     */
    public static function getSettings()
    {
        return static::first() ?: new static();
    }

    /**
     * System logo URL accessor
     */
    public function getSystemLogoUrlAttribute()
    {
        return $this->getFileUrl('system_logo', 'backend/assets/images/default-logo.png');
    }

    /**
     * System favicon URL accessor
     */
    public function getSystemFaviconUrlAttribute()
    {
        return $this->getFileUrl('system_favicon', 'backend/assets/images/default-favicon.ico');
    }

    /**
     * Admin logo URL accessor
     */
    public function getAdminLogoUrlAttribute()
    {
        return $this->getFileUrl('admin_logo', 'backend/assets/images/default-logo.png');
    }

    /**
     * Admin favicon URL accessor
     */
    public function getAdminFaviconUrlAttribute()
    {
        return $this->getFileUrl('admin_favicon', 'backend/assets/images/default-favicon.ico');
    }

    /**
     * Resolve full asset URL for a file or fallback
     */
    private function getFileUrl($field, $fallback)
    {
        if (!empty($this->$field) && file_exists(public_path($this->$field))) {
            return asset($this->$field);
        }
        return asset($fallback);
    }

    /**
     * Cleanup files when deleting the model
     */
    protected static function booted()
    {
        static::deleting(function ($model) {
            $model->cleanupFiles();
        });
    }

    /**
     * Delete uploaded files
     */
    public function cleanupFiles()
    {
        foreach ([
            'system_logo',
            'system_favicon',
            'admin_logo',
            'admin_favicon'
        ] as $field) {
            if ($this->$field && file_exists(public_path($this->$field))) {
                @unlink(public_path($this->$field));
            }
        }
    }
}
