<?php

namespace App\Http\Controllers\Api;

use App\Actions\HouseholdConnection\GenerateHouseholdLinkCodeAction;
use App\Actions\HouseholdConnection\SubmitHouseholdLinkAction;
use App\Actions\HouseholdConnection\UnlinkHouseholdsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\HouseholdConnection\GenerateLinkCodeRequest;
use App\Http\Requests\HouseholdConnection\ShowHouseholdConnectionRequest;
use App\Http\Requests\HouseholdConnection\SubmitLinkRequest;
use App\Http\Requests\HouseholdConnection\UnlinkHouseholdRequest;
use App\Http\Resources\HouseholdConnection\HouseholdConnectionMessageResource;
use App\Http\Resources\HouseholdConnection\HouseholdConnectionStatusResource;
use App\Http\Resources\HouseholdConnection\HouseholdLinkCodeResource;
use App\Http\Resources\HouseholdConnection\HouseholdLinkRequestResource;
use App\Queries\HouseholdConnection\GetHouseholdConnectionStatusQuery;
use Illuminate\Http\JsonResponse;

class HouseholdConnectionController extends Controller
{
    public function show(ShowHouseholdConnectionRequest $request, GetHouseholdConnectionStatusQuery $query): JsonResponse
    {
        $status = $query->execute($request->household(), $request->householdRole());
        return HouseholdConnectionStatusResource::make($status)->response();
    }

    public function generateCode(GenerateLinkCodeRequest $request, GenerateHouseholdLinkCodeAction $action): JsonResponse
    {
        $code = $action->execute($request->household(), $request->actorOrFail());
        return HouseholdLinkCodeResource::generated($code, (string) $request->household()->name)->response();
    }

    public function submitRequest(SubmitLinkRequest $request, SubmitHouseholdLinkAction $action): JsonResponse
    {
        $linkRequest = $action->execute($request->household(), $request->actorOrFail(), $request->normalizedCode());
        return HouseholdLinkRequestResource::submitted($linkRequest, (int) $request->household()->id)->response()->setStatusCode(202);
    }

    public function unlink(UnlinkHouseholdRequest $request, UnlinkHouseholdsAction $action): JsonResponse
    {
        $payload = $action->execute($request->household(), $request->actorOrFail());
        return HouseholdConnectionMessageResource::makeMessage((string) ($payload['message'] ?? ''))->response();
    }
}
