<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Shakhawat\UserAuthController;

Route::prefix('user')->controller(UserAuthController::class)->group(function () {

    Route::post('login', 'Login');
    Route::post('guest_login', 'guestLogin');
    Route::post('/login/google',  'googleLogin');
    Route::post('register', 'UserRegister');
    Route::post('forget_password', 'forgetPassword');
    Route::post('verify_otp', 'verifyOtp');
    Route::post('reset_password', 'resetPassword');
    Route::post('resend-otp', 'resendOtp');

    Route::middleware('auth:api')->group(function () {
        // Route::post('/use-referral', 'useReferralCode');
        Route::post('logout', 'logout');
        Route::get('show', 'showUser');
        Route::post('update', 'updateProfile');
        Route::post('change_password', 'changePassword');
    });
});
