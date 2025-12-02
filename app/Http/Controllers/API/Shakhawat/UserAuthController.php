<?php

namespace App\Http\Controllers\API\Shakhawat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use App\Models\PasswordReset;
use App\Helper\Helper;
use Laravel\Socialite\Facades\Socialite;



class UserAuthController extends Controller
{
    use ApiResponse;




    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        $credentials = $request->only('email', 'password');

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->badRequest('User not found.', null, 404);
        }

        if (!$token = JWTAuth::attempt($credentials)) {
            \Log::warning('Login failed for: ' . $request->email);
            return $this->badRequest('Invalid credentials.', null, 401);
        }

        $user = auth()->user();

        return $this->success([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'user'         => $this->formatUser($user),
        ]);
    }


    public function googleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->token);

            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => Hash::make(Str::random(10)), 
                ]);
            }

            $token = JWTAuth::fromUser($user);

            return $this->success([
                'user' => $this->formatUser($user),
                'token' => $token,
            ], 'Google login successful', 200);
        } catch (\Exception $e) {
            return $this->error('Google login failed: ' . $e->getMessage(), 500);
        }
    }




    public function UserRegister(Request $request)
    {
        return $this->registerUser($request, 'user');
    }

    protected function registerUser(Request $request, $role)
    {
        if (strtolower($role) === 'admin') {
            return $this->badRequest('Admin registration is not allowed.', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'phone'    => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        DB::beginTransaction();
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'password' => Hash::make($request->password),
            ]);

            // $user->assignRole($role);
            DB::commit();

            $token = JWTAuth::fromUser($user);

            return $this->success([
                'user'  => $this->formatUser($user),
                'role'  => $role,
                'token' => $token,
            ], 'Registered successfully.', 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Registration failed: ' . $e->getMessage(), 500);
        }
    }


    public function guestLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        try {
            $deviceId = $request->device_id;
            $user = User::where('device_id', $deviceId)->first();

            if (!$user) {
                $lastGuest = User::where('name', 'like', 'Guest_%')
                    ->orderBy('id', 'desc')
                    ->first();

                $guestNumber = $lastGuest ? ((int) str_replace('Guest_', '', $lastGuest->name)) + 1 : 1;
                $guestName = 'Guest_' . $guestNumber;

                $user = User::create([
                    'name' => $guestName,
                    'device_id' => $deviceId,
                    'is_guest' => true,
                ]);
            }

            $token = JWTAuth::fromUser($user);

            return $this->success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'is_guest' => $user->is_guest,
                    'device_id' => $user->device_id,
                ],
                'token' => $token,
            ], 'Guest login successful.', 200);
        } catch (\Throwable $e) {
            return $this->error('Something went wrong: ' . $e->getMessage(), 500);
        }
    }


    public function forgetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        try {
            $user = User::where('email', $request->email)->first();
            $this->clearPasswordResetCache();
            $this->sendOtp($user);

            Cache::put('password_reset_user_id', $user->id, now()->addMinutes(15));
            Cache::put('password_reset_otp', $user->otp, now()->addMinutes(15));
            Cache::put('password_reset_email', $user->email, now()->addMinutes(15));

            return $this->success([], 'OTP sent successfully. Please check your email.', 200);
        } catch (\Throwable $e) {
            return $this->error('Failed to send OTP: ' . $e->getMessage(), 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|numeric|digits:4',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        $userId = Cache::get('password_reset_user_id');
        $cachedOtp = Cache::get('password_reset_otp');

        if (!$userId || !$cachedOtp) {
            return $this->badRequest('Please request OTP first.', [], 400);
        }

        if ($request->otp != $cachedOtp) {
            return $this->badRequest('Invalid OTP.', [], 400);
        }

        $user = User::find($userId);
        if (!$user) return $this->error('User not found.', 404);

        if ($user->otp_created_at && now()->gt(Carbon::parse($user->otp_created_at)->addMinutes(15))) {
            return $this->badRequest('OTP has expired.', [], 400);
        }

        Cache::put('password_reset_verified', true, now()->addMinutes(2));
        Cache::put('verified_user_id', $userId, now()->addMinutes(2));

        return $this->success([], 'OTP verified successfully. You can now reset your password.', 200);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        $userId = Cache::get('verified_user_id');
        $isVerified = Cache::get('password_reset_verified');

        if (!$userId || !$isVerified) {
            return $this->badRequest('Please verify OTP first.', [], 400);
        }

        $user = User::find($userId);
        if (!$user) return $this->error('User not found.', 404);

        $user->password = Hash::make($request->password);
        $user->otp = null;
        $user->otp_created_at = null;
        $user->save();

        $this->clearPasswordResetCache();

        return $this->success([], 'Password reset successfully. Please login with your new password.', 200);
    }

    public function resendOtp()
    {
        $userId = Cache::get('password_reset_user_id');
        if (!$userId) return $this->badRequest('No OTP request found. Please request OTP first.', [], 400);

        $user = User::find($userId);
        if (!$user) return $this->error('User not found.', 404);

        try {
            $this->sendOtp($user);
            Cache::put('password_reset_otp', $user->otp, now()->addMinutes(15));

            return $this->success([], 'OTP resent successfully. Please check your email.', 200);
        } catch (\Throwable $e) {
            return $this->error('Failed to resend OTP: ' . $e->getMessage(), 500);
        }
    }


    public function logout(Request $request)
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->success([], 'Successfully logged out.', 200);
        } catch (\Exception $e) {
            return $this->error('Logout failed: ' . $e->getMessage(), 500);
        }
    }


    public function showUser()
    {
        $user = auth()->user();
        if (!$user) return $this->unauthorized();

        return $this->success(['user' => $this->formatUser($user)], 'User found', 200);
    }




    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        if (!$user) return $this->unauthorized();

        $booleanFields = ['messages', 'notification'];
        foreach ($booleanFields as $field) {
            if ($request->has($field)) {
                $request->merge([
                    $field => filter_var($request->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        $validator = Validator::make($request->all(), [
            'name'   => 'nullable|string|max:255',
            'email'  => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'  => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        try {
            if ($request->hasFile('avatar')) {
                $file = $request->file('avatar');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = public_path('uploads/avatars');

                if (!file_exists($path)) mkdir($path, 0755, true);
                $file->move($path, $filename);

                if ($user->avatar && file_exists($path . '/' . $user->avatar)) {
                    @unlink($path . '/' . $user->avatar);
                }

                $user->avatar = $filename;
            }

            if ($user->is_guest) {
                $randomPassword = Str::random(10);

                if ($request->filled('name'))  $user->name  = $request->name;
                if ($request->filled('email')) $user->email = $request->email;
                if ($request->filled('phone')) $user->phone = $request->phone;

                $user->is_guest = false;
                $user->password = Hash::make($randomPassword);

                Mail::raw(
                    "Hi {$user->name},\n\nYour guest account has been upgraded to a full account.\n\nLogin Email: {$user->email}\nPassword: {$randomPassword}\n\nPlease change your password after logging in.\n\nThank you!",
                    function ($message) use ($user) {
                        $message->to($user->email)
                            ->subject('Your Account Has Been Activated');
                    }
                );

                $message = 'Guest account upgraded successfully. Login details sent to your email.';
            } else {
                foreach (['name', 'email', 'phone', 'messages', 'notification'] as $field) {
                    if ($request->has($field)) {
                        $user->$field = $request->input($field);
                    }
                }

                $message = 'Profile updated successfully.';
            }

            $user->save();

            return $this->success(
                ['user' => $this->formatUser($user)],
                $message,
                200
            );
        } catch (\Throwable $e) {
            return $this->error('Profile update failed: ' . $e->getMessage(), 500);
        }
    }

    public function changePassword(Request $request)
    {
        $user = auth()->user();
        if (!$user) return $this->unauthorized();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray(), 'Validation failed', 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->badRequest('Current password is incorrect.', [], 400);
        }

        try {
            $user->password = Hash::make($request->new_password);
            $user->save();

            return $this->success([], 'Password changed successfully.', 200);
        } catch (\Throwable $e) {
            return $this->error('Password change failed: ' . $e->getMessage(), 500);
        }
    }


    protected function sendOtp(User $user)
    {
        $otp = rand(1000, 9999);

        $user->update([
            'otp' => $otp,
            'otp_created_at' => now(),
        ]);

        Mail::raw("Your OTP code is: $otp. It will expire in 15 minutes.", function ($message) use ($user) {
            $message->to($user->email)->subject('Your OTP Code');
        });
    }

    protected function clearPasswordResetCache()
    {
        $keys = [
            'password_reset_user_id',
            'password_reset_otp',
            'password_reset_email',
            'password_reset_verified',
            'verified_user_id',
        ];
        foreach ($keys as $key) Cache::forget($key);
    }

    protected function formatUser($user)
    {
        return [
            'id'     => $user->id,
            'avatar' => $user->avatar ? asset('uploads/avatars/' . $user->avatar) : asset('No_Image/No_profile.png'),
            'name'   => $user->name,
            'email'  => $user->email,
            'phone'  => $user->phone,
        ];
    }
}
