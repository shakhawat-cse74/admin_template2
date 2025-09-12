<?php

namespace App\Http\Controllers\Web\backend\shakhawat;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

class SystemSettingController extends Controller
{
    public function edit()
    {
        $settings = SystemSetting::getSettings();
        return view('backend.layouts.system_setting.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        try
        {
            $validated = $request->validate([
                'system_title'          => 'nullable|string|max:150',
                'system_short_title'    => 'nullable|string|max:100',
                'system_logo'           => 'nullable|image|max:2048',
                'system_favicon'        => 'nullable|image|max:1024',
                'company_name'          => 'nullable|string|max:100',
                'company_address'       => 'nullable|string|max:255',
                'tagline'               => 'nullable|string|max:255',
                'phone'                 => 'nullable|string|max:15',
                'email'                 => 'nullable|email|max:50',
                'timezone'              => 'nullable|string|max:50',
                'language'              => 'nullable|string|max:50',
                'copyright_text'        => 'nullable|string|max:1000',
            ]);

            $settings = SystemSetting::getSettings();
            $data = collect($validated)->except(['system_logo', 'system_favicon'])->toArray();

            foreach (['system_logo' => 'logo', 'system_favicon' => 'favicon'] as $field => $folder)
            {
                if ($request->hasFile($field)) 
                {
                    if ($settings->$field && file_exists(public_path($settings->$field))) 
                    {
                        unlink(public_path($settings->$field));
                    }
                    $data[$field] = $this->uploadFile($request->file($field), "uploads/systems/{$folder}");
                }
            }

            if ($settings->exists) 
            {
                $settings->update($data);
            } 
            else 
            {
                SystemSetting::create($data);
            }

            return back()->with('success', 'Settings updated successfully!');

        } 
        catch (\Exception $e) 
        {
            Log::error('Settings update failed: ' . $e->getMessage());
            return back()->with('error', 'Update failed. Please try again.');
        }
    }

    private function uploadFile($file, $path)
    {
        $directory = public_path($path);
        
        if (!is_dir($directory)) 
        {
            mkdir($directory, 0755, true);
        }
        
        $filename = time() . '_' . $file->getClientOriginalName();
        $file->move($directory, $filename);
        
        return "{$path}/{$filename}";
    }
}