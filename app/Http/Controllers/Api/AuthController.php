<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\ChangeInitialCredentialsAction;
use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Auth\ForgotPasswordAction;
use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\LogoutUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\UpdateHouseholdNicknameAction;
use App\Actions\Auth\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\AuthSuccessResource;
use App\Models\Household;
use App\Support\JsonUtf8Sanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $payload = $action->execute($request->normalizedEmail(), (string) $request->validated('password'));
        return AuthSuccessResource::fromPayload($payload['user'], (string) $payload['token'])->response();
    }

    public function me(Request $request, GetCurrentUserAction $action): JsonResponse
    {
        $user = $action->execute($request);
        return response()->json(JsonUtf8Sanitizer::sanitize(['user' => $user]));
    }

    public function logout(Request $request, LogoutUserAction $action): Response
    {
        $action->execute($request->user());
        return response()->noContent();
    }

    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $payload = $action->execute($request->validated());
        return AuthSuccessResource::fromPayload($payload['user'], (string) $payload['token'])->response()->setStatusCode(201);
    }

    public function forgotPassword(Request $request, ForgotPasswordAction $action): JsonResponse
    {
        return response()->json($action->execute($request));
    }

    public function resetPassword(Request $request, ResetPasswordAction $action): JsonResponse
    {
        return response()->json($action->execute($request));
    }

    public function changeInitialCredentials(Request $request, ChangeInitialCredentialsAction $action): JsonResponse
    {
        $result = $action->execute($request);
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }

    public function updateProfile(Request $request, UpdateProfileAction $action): JsonResponse
    {
        $result = $action->execute($request);
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }

    public function destroyAccount(Request $request, DeleteAccountAction $action): JsonResponse
    {
        $result = $action->execute($request);
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }

    public function updateHouseholdNickname(Request $request, Household $household, UpdateHouseholdNicknameAction $action): JsonResponse
    {
        $result = $action->execute($request, $household);
        return response()->json(JsonUtf8Sanitizer::sanitize($result['payload']), (int) $result['status']);
    }
}
