<?php

namespace App\Http\Controllers\Api;

use App\Traits\ApiResponser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserInterest;
use App\Models\UserPhoneBook;
use App\Notifications\Otp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    use ApiResponser;

    /** Recover account */
    public function recoverAccount(Request $request)
    {
        $this->validate($request, [
            'user_id'       =>    'required|exists:users,id',
            'device_type'   =>    'in:ios,android,web'
        ]);

        $user = User::withTrashed()->where('email', $request->email)->first(); 

        if($user->deleted_at != null){
            User::whereId($request->user_id)->update(['device_type' => $request->device_type, 'device_token' => $request->device_token, 'deleted_at' => null]);
            $userUpdate = User::find($request->user_id);
            $token = $userUpdate->createToken('AuthToken');

            $userResource = new UserResource($userUpdate);
            return $this->loginResponse('Your account has recovered successfully.', $token->plainTextToken, $userResource);
        } else {
            return $this->errorResponse('Your account already has been recovered.', 400);
        }
    }

    /** Login */
    public function login(Request $request)
    {
        $this->validate($request, [
            'email'             =>  'required|email',
            'password'          =>  'required',
            'device_type'       =>  'in:ios,android,web'
        ]);

        $user = User::withTrashed()->where('email', $request->email)->first(); 

        if (!empty($user)) {
            if($user->deleted_at != null){
                return $this->errorDataResponse('Your account has been deleted as per your request.', ['user_id' => $user->id, 'is_deleted' => 1], 400);
            } else {
                if (Hash::check($request->password, $user->password)) {
                    if ($user->is_verified == 1) {
                        if ($user->is_blocked == 0) {
                            Auth::attempt($request->only('email', 'password') + ['is_social' => '0']);

                            $token = $user->createToken('AuthToken');
                            User::whereId($user->id)->update(['device_type' => $request->device_type, 'device_token' => $request->device_token]);
                            $userUpdate = User::find($user->id);

                            $userResource = new UserResource($userUpdate);
                            return $this->loginResponse('User login successfully.', $token->plainTextToken, $userResource);
                        } else {
                            return $this->errorResponse('You have been blocked by admin.', 400);
                        }
                    } else {
                        $userResource = new UserResource($user);
                        return $this->successDataResponse('Your account is not verfied.', $userResource, 200);
                    }
                } else {
                    return $this->errorResponse('Password is incorrect.', 400);
                }
            }
        } else {
            return $this->errorResponse('Email not found.', 400);
        }
    }

    /** Social login */
    public function socialLogin(Request $request)
    {
        $this->validate($request, [
            'social_type'       =>  'required|in:google,facebook,apple',
            'social_token'      =>  'required',
            'device_type'       =>  'in:ios,android,web'
        ]);

        $user = User::where(['social_token' => $request->social_token, 'social_type' => $request->social_type])->first();

        if (!empty($user)) {
            if ($user->is_blocked == 0) {
                $user->device_type = $request->device_type;
                $user->device_token = $request->device_token;
                $user->save();
            } else {
                return $this->errorResponse('Your account is blocked.', 400);
            }
        } else {
            $user = new User;
            $user->full_name = $request->full_name;
            $user->email = $request->email;
            $user->phone_number = $request->phone_number;
            $user->social_type = $request->social_type;
            $user->social_token = $request->social_token;
            $user->is_profile_complete = '0';
            $user->is_verified = '1';
            $user->is_social = '1';
            $user->device_type = $request->device_type;
            $user->device_token = $request->device_token;
            $user->save();
        }

        $token = $user->createToken('AuthToken');
        $user = User::whereId($user->id)->first();
        $userResource = new UserResource($user);
        
        return $this->loginResponse('Social login successfully.', $token->plainTextToken, $userResource);
    }

    /** Register */
    public function register(Request $request)
    {
        $this->validate($request, [
            'email'       =>  'required|email|max:255',
            'password'    =>  'required'
        ]);

        $user = User::withTrashed()->where('email', $request->email)->first(); 

        if (!empty($user)) {
            if($user->deleted_at != null){
                return $this->errorDataResponse('Your account has been deleted as per your request.', ['user_id' => $user->id, 'is_deleted' => 1], 400);
            } elseif ($user->email == $request->email){
                return $this->errorResponse('The email already has been taken.', 400);
            }
        } else {
            $created =  User::create($request->only('email', 'password'));
    
            if ($created) {
    
                try {
                    $created->subject =  'User Account Verification';
                    $created->message =  'Please use the verification code below to sign up. ' . '<b>' . $created->verified_code . '</b>';
    
                    Notification::send($created, new Otp($created));
                } catch (\Exception $exception) {

                    Log::error('Error while sending OTP notification: ' . $exception->getMessage(), [
                        'exception' => $exception,
                        'user' => $created->id ?? 'unknown', // Optionally log the user ID or relevant details
                    ]);
                }
    
                $data = [
                    'user_id' => $created->id
                ];
                return $this->successDataResponse('User register successfully.', $data, 200);
            } else {
                return $this->errorResponse('Something went wrong.', 400);
            }
        }
    }

    /** Forgot password */
    public function forgotPassword(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email|exists:users,email'
        ]);

        $user = User::where('email', $request->email)->first();
        $user->verified_code = random_int(100000, 900000); // mt_rand(100000,900000);
        $user->is_forgot = '1';

        if ($user->save()) {

            try {
                $user->subject =  'Forgot Your Password';
                $user->message =  'We received a request to reset the password for your account. Please use the verification code below to change password.' . '<br> <br> <b>' . $user->verified_code . '</b>';

                Notification::send($user, new Otp($user));
            } catch (\Exception $exception) {
            }

            $data = [
                'user_id' => $user->id
            ];
            return $this->successDataResponse('Verification code has been sent on your email address', $data, 200);
        } else {
            return $this->errorResponse('Something went wrong.', 400);
        }
    }

    /** Verification */
    public function verification(Request $request)
    {
        $this->validate($request, [
            'user_id'       => 'required|exists:users,id',
            'verified_code' => 'required',
            'type'          => 'required|in:forgot,account_verify',
            'device_type'   =>  'in:ios,android,web'
        ]);

        $userExists = User::whereId($request->user_id)->where('verified_code', $request->verified_code)->exists();

        if ($userExists) {
            if ($request->type == 'forgot') {
                $updateUser = User::whereId($request->user_id)->where('verified_code', $request->verified_code)->update(['device_type' => $request->device_type, 'device_token' => $request->device_token, 'is_forgot' => '1', 'verified_code' => null]);
            } else {
                $updateUser = User::whereId($request->user_id)->where('verified_code', $request->verified_code)->update(['device_type' => $request->device_type, 'device_token' => $request->device_token, 'is_verified' => '1', 'verified_code' => null]);
            }

            if ($updateUser) {
                $user = User::find($request->user_id);
                $token = $user->createToken('AuthToken');

                $userResource = new UserResource($user);
                return $this->loginResponse('Your verification completed successfully.', $token->plainTextToken, $userResource);
            } else {
                return $this->errorResponse('Something went wrong.', 400);
            }
        } else {
            return $this->errorResponse('Invalid OTP.', 400);
        }
    }

    /** Resend code */
    public function reSendCode(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required|exists:users,id'
        ]);

        $user = User::whereId($request->user_id)->first();
        $user->verified_code = random_int(100000, 900000); // mt_rand(100000,900000);

        if ($user->save()) {

            try {
                $user->subject =  'Resend Code';
                $user->message =  'We received a request to resend code. Please use the verification code.' . '<br> <br> <b>' . $user->verified_code . '</b>';

                Notification::send($user, new Otp($user));
            } catch (\Exception $exception) {
            }

            return $this->successResponse('Resend code successfully send on your given email.', 200);
        } else {
            return $this->errorResponse('Something went wrong.', 400);
        }
    }

    /** Update password */
    public function updatePassword(Request $request)
    {
        $this->validate($request, [
            'new_password' => 'required|max:255|min:8'
        ]);

        $user = User::whereId(auth()->user()->id)->first();

        if (empty($request->old_password)) {
            if (Hash::check($request->new_password, $user->password)) {
                return $this->errorResponse('Your new password could not be old password.', 400);
            } else {
                $updateUser = User::whereId(auth()->user()->id)->update(['password' => Hash::make($request->new_password), 'is_forgot' => '0']);
                if ($updateUser) {
                    return $this->successResponse('New Password set successfully.', 200);
                } else {
                    return $this->errorResponse('Something went wrong.', 400);
                }
            }
        } else {
            if (Hash::check($request->old_password, $user->password)) {
                $updateUser = User::whereId(auth()->user()->id)->update(['password' => Hash::make($request->new_password)]);
                if ($updateUser) {
                    return $this->successResponse('Password update successfully.', 200);
                } else {
                    return $this->errorResponse('Something went wrong.', 400);
                }
            } else {
                return $this->errorResponse('Old password is incorrect.', 400);
            }
        }
    }

    /** Complete profile */
    public function completeProfile(Request $request)
    {
        $this->validate($request, [
            'profile_image'             =>    'mimes:jpeg,png,jpg',
            'cover_image'               =>    'mimes:jpeg,png,jpg',
        ]);

        $authUser = auth()->user();
        $authId = $authUser->id;

        $check = User::where('id', '!=', $authId)->where('user_name', $request->user_name)->get();
        if (count($check) > 0) {
            return $this->errorResponse('User name should be unique.', 400);
        }
        
        $completeProfile = $request->all();

        if ($request->hasFile('profile_image')) {
            $profile_image = strtotime("now"). mt_rand(100000,900000) . '.' . $request->profile_image->getClientOriginalExtension();
            $request->profile_image->move(public_path('/media/profile_image'), $profile_image);
            $file_path = '/media/profile_image/' . $profile_image;
            $completeProfile['profile_image'] = $file_path;
        }
        
        if ($request->hasFile('cover_image')) {
            $cover_image = strtotime("now") . mt_rand(100000,900000) . '.' . $request->cover_image->getClientOriginalExtension();
            $request->cover_image->move(public_path('/media/cover_image'), $cover_image);
            $file_path = '/media/cover_image/' . $cover_image;
            $completeProfile['cover_image'] = $file_path;
        }

        $completeProfile['is_profile_complete'] = '1';
        $update_user = User::whereId($authId)->update($completeProfile);

        if ($update_user) {
            $user = User::find($authId);
            $userResource = new UserResource($user);

            if ($authUser->is_profile_complete == '0') {
                return $this->successDataResponse('Profile completed successfully.', $userResource);
            } else {
                return $this->successDataResponse('Profile updated successfully.', $userResource);
            }
        } else {
            return $this->errorResponse('Something went wrong.', 400);
        }
    }

    /** Content */
    public function content(Request $request)
    {
        $this->validate($request, [
            'type' => 'required|exists:contents,type'
        ]);

        return $this->successDataResponse('Content found.', ['url' => url('content', $request->type)], 200);
    }

    /** Delete account */
    public function deleteAccount()
    {
        try {
            DB::beginTransaction();    
            User::whereId(auth()->id())->update(['device_type' => null, 'device_token' => null]);
            $user = User::whereId(auth()->id())->first(); 
            $user->tokens()->delete();
            $user->delete();

            DB::commit();
            return $this->successResponse('Account has been deleted successfully.', 200);
        } catch (\Exception $exception){
            DB::rollBack();
            return $this->errorResponse($exception->getMessage(), 400);
        }
    }

    /** Logout */
    public function logout(Request $request)
    {
        $deleteTokens = $request->user()->tokens()->delete(); // $request->user()->currentAccessToken()->delete();

        if ($deleteTokens) {
            $update_user = User::whereId(auth()->user()->id)->update(['device_type' => null, 'device_token' => null]);
            if ($update_user) {
                return $this->successResponse('User logout successfully.', 200);
            } else {
                return $this->errorResponse('Something went wrong.', 400);
            }
        } else {
            return $this->errorResponse('Something went wrong.', 400);
        }
    }

    /** Notification setting */
    public function notificationSetting(Request $request)
    {
        $this->validate($request, [
            'type'      =>      'required|in:push_notification,post_comment_notification,follower_notification,is_sharing,is_phone_book'
        ]);

        $type = $request->type; 
        $user = User::find(auth()->id());

        if($user->$type == '0'){
            $user->$type = '1';
            $status = 'on';
        } else {
            $user->$type = '0';
            $status = 'off';
        }
        $user->save();

        return $this->successResponse('Notification ' . $status . ' successfully.');
    }

    /** Is profile private */
    public function isProfilePrivate(Request $request)
    {
        $user = User::find(auth()->id());

        if($user->is_profile_private == '0'){
            $user->is_profile_private = '1';
            $status = 'private';
        } else {
            $user->is_profile_private = '0';
            $status = 'public';
        }
        $user->save();

        return $this->successResponse('Profile has been ' . $status . ' successfully.');
    }

    /** User interest */
    function userInterest(Request $request)
    {
        $this->validate($request, [
            'interest_id' => 'required|array'
        ]);

        if(isset($request->interest_id) && count($request->interest_id) > 0){
            $userInterestCount = UserInterest::where('user_id', auth()->id())->count();
            UserInterest::where('user_id', auth()->id())->delete();
            foreach($request->interest_id as $interest_id){
                UserInterest::create([
                    'user_id'     =>   auth()->id(),
                    'interest_id' =>   $interest_id
                ]);
            }
            $user = User::find(auth()->id());
            $userResource = new UserResource($user);
            if($userInterestCount > 0){
                return $this->successDataResponse('Your Interest has been updated successfully.', $userResource);
            } else {
                return $this->successDataResponse('Your Interest has been saved successfully.', $userResource);
            }
        } else {
            return $this->errorResponse('Something went wrong.', 400);
        }
    }
    
    /** User Phone Book */
    public function enablePhoneBook(Request $request){
        
        $this->validate($request, [
            'content' => 'required',
        ]);
        
        try{
            $user = auth()->user();
            $authId = $user->id;
            
            
            if ($user->is_phone_book == '1') {
                $update_user = User::whereId($authId)->update(['is_phone_book' => '0']);
                UserPhoneBook::where('user_id', $authId)->delete();
                if ($update_user) {
                    return $this->successResponse('Phone Book Disabled Successfully', 200);
                } else {
                    return $this->errorResponse('Something went wrong.', 400);
                }
            } else if ($user->is_phone_book == '0') {
                $update_user = User::whereId($authId)->update(['is_phone_book' => '1']);
                UserPhoneBook::create([
                    'user_id' => $authId,
                    'content' => json_encode($request->content),
                ]);
                if ($update_user) {
                    return $this->successResponse('Phone Book Enabled Successfully', 200);
                } else {
                    return $this->errorResponse('Something went wrong.', 400);
                }
            }
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

    }
}
