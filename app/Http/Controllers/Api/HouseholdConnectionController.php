<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdLinkCode;
use App\Models\HouseholdLinkRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HouseholdConnectionController extends Controller
{
    use ResolvesHouseholdContext;

    private const LINK_CODE_LENGTH = 8;
    private const LINK_CODE_TTL_HOURS = 24;
    private const REQUEST_STATUS_PENDING = 'pending';
    private const REQUEST_STATUS_ACCEPTED = 'accepted';
    private const REQUEST_STATUS_REFUSED = 'refused';
    private const NOTIFICATION_TYPE_REQUEST = 'household_link_request';
    private const NOTIFICATION_TYPE_RESPONSE = 'household_link_request_responded';
    private const NOTIFICATION_TYPE_DISCONNECTED = 'household_link_disconnected';

    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);

        $linkedHousehold = $this->resolveConnectedHousehold($household);
        $pendingRequest = $this->resolvePendingRequestForHousehold((int) $household->id);
        $activeCode = null;

        if (
            $role === User::ROLE_PARENT
            && !$linkedHousehold
            && !$pendingRequest
        ) {
            $activeCode = $this->findReusableCode((int) $household->id);
        }

        return response()->json([
            'connection' => [
                'is_connected' => $linkedHousehold instanceof Household,
                'linked_household' => $linkedHousehold
                    ? [
                        'id' => (int) $linkedHousehold->id,
                        'name' => (string) $linkedHousehold->name,
                    ]
                    : null,
                'pending_request' => $pendingRequest
                    ? $this->toPendingRequestPayload($pendingRequest, (int) $household->id)
                    : null,
                'active_code' => $activeCode
                    ? [
                        'code' => (string) $activeCode->code,
                        'expires_at' => optional($activeCode->expires_at)->toIso8601String(),
                        'share_text' => $this->buildConnectionCodeShareText(
                            (string) $household->name,
                            (string) $activeCode->code
                        ),
                    ]
                    : null,
            ],
            'permissions' => [
                'can_manage_connection' => $role === User::ROLE_PARENT,
                'can_generate_code' => $role === User::ROLE_PARENT && !$linkedHousehold && !$pendingRequest,
                'can_connect_with_code' => $role === User::ROLE_PARENT && !$linkedHousehold && !$pendingRequest,
                'can_unlink' => $role === User::ROLE_PARENT && $linkedHousehold instanceof Household,
            ],
        ]);
    }

    public function generateCode(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);

        return DB::transaction(function () use ($household, $request): JsonResponse {
            /** @var Household $lockedHousehold */
            $lockedHousehold = Household::query()
                ->whereKey((int) $household->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($lockedHousehold);

            $code = $this->findReusableCode((int) $lockedHousehold->id);
            if (!$code) {
                $code = HouseholdLinkCode::query()->create([
                    'household_id' => (int) $lockedHousehold->id,
                    'created_by_user_id' => (int) $request->user()->id,
                    'code' => $this->generateUniqueCode(),
                    'expires_at' => now()->addHours(self::LINK_CODE_TTL_HOURS),
                ]);
            }

            return response()->json([
                'message' => 'Code de liaison prêt.',
                'code' => [
                    'value' => (string) $code->code,
                    'expires_at' => optional($code->expires_at)->toIso8601String(),
                    'share_text' => $this->buildConnectionCodeShareText(
                        (string) $lockedHousehold->name,
                        (string) $code->code
                    ),
                ],
            ]);
        });
    }

    public function connectWithCode(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:32'],
        ]);

        $normalizedCode = $this->normalizeCode((string) $validated['code']);
        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'code' => ['Le code de liaison est invalide.'],
            ]);
        }

        $requesterHouseholdId = (int) $household->id;
        $requestingUser = $request->user();
        $notificationsToPublish = collect();

        $createdRequest = DB::transaction(function () use (
            $requesterHouseholdId,
            $requestingUser,
            $normalizedCode,
            &$notificationsToPublish
        ): HouseholdLinkRequest {
            /** @var Household $requesterHousehold */
            $requesterHousehold = Household::query()
                ->whereKey($requesterHouseholdId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($requesterHousehold);

            /** @var HouseholdLinkCode|null $code */
            $code = HouseholdLinkCode::query()
                ->where('code', $normalizedCode)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$code) {
                throw ValidationException::withMessages([
                    'code' => ['Ce code de liaison est invalide ou expiré.'],
                ]);
            }

            $targetHouseholdId = (int) $code->household_id;
            if ($targetHouseholdId === $requesterHouseholdId) {
                throw ValidationException::withMessages([
                    'code' => ['Ce code appartient déjà à votre foyer.'],
                ]);
            }

            /** @var Household $targetHousehold */
            $targetHousehold = Household::query()
                ->whereKey($targetHouseholdId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureHouseholdCanStartConnectionFlow($targetHousehold);

            $requestAlreadyExists = HouseholdLinkRequest::query()
                ->where('status', self::REQUEST_STATUS_PENDING)
                ->where(function ($query) use ($requesterHouseholdId, $targetHouseholdId): void {
                    $query
                        ->where(function ($directQuery) use ($requesterHouseholdId, $targetHouseholdId): void {
                            $directQuery
                                ->where('from_household_id', $requesterHouseholdId)
                                ->where('to_household_id', $targetHouseholdId);
                        })
                        ->orWhere(function ($inverseQuery) use ($requesterHouseholdId, $targetHouseholdId): void {
                            $inverseQuery
                                ->where('from_household_id', $targetHouseholdId)
                                ->where('to_household_id', $requesterHouseholdId);
                        });
                })
                ->exists();
            if ($requestAlreadyExists) {
                throw ValidationException::withMessages([
                    'code' => ['Une demande de liaison est déjà en attente entre ces deux foyers.'],
                ]);
            }

            $createdRequest = HouseholdLinkRequest::query()->create([
                'from_household_id' => $requesterHouseholdId,
                'to_household_id' => $targetHouseholdId,
                'requested_by_user_id' => (int) $requestingUser->id,
                'household_link_code_id' => (int) $code->id,
                'status' => self::REQUEST_STATUS_PENDING,
            ]);

            $code->forceFill([
                'used_at' => now(),
                'used_by_household_id' => $requesterHouseholdId,
            ])->save();

            $targetParentIds = $this->resolveParentUserIds($targetHouseholdId);
            if (count($targetParentIds) === 0) {
                throw ValidationException::withMessages([
                    'household' => ['Aucun parent n\'est disponible pour valider la liaison.'],
                ]);
            }

            foreach ($targetParentIds as $parentId) {
                $notification = UserNotification::query()->create([
                    'household_id' => $targetHouseholdId,
                    'user_id' => $parentId,
                    'type' => self::NOTIFICATION_TYPE_REQUEST,
                    'title' => 'Demande de liaison de foyer',
                    'body' => sprintf(
                        'Le foyer %s souhaite se connecter à votre foyer %s.',
                        (string) $requesterHousehold->name,
                        (string) $targetHousehold->name
                    ),
                    'data' => [
                        'status' => self::REQUEST_STATUS_PENDING,
                        'link_request_id' => (int) $createdRequest->id,
                        'requester_household_id' => $requesterHouseholdId,
                        'requester_household_name' => (string) $requesterHousehold->name,
                        'target_household_id' => $targetHouseholdId,
                        'target_household_name' => (string) $targetHousehold->name,
                        'requested_by_user_id' => (int) $requestingUser->id,
                        'requested_by_user_name' => (string) ($requestingUser->name ?? 'Un parent'),
                        'household_name' => (string) $targetHousehold->name,
                    ],
                ]);
                $notificationsToPublish->push($notification);
            }

            return $createdRequest;
        });

        DB::afterCommit(function () use ($notificationsToPublish, $createdRequest): void {
            foreach ($notificationsToPublish as $notification) {
                if ($notification instanceof UserNotification) {
                    $this->publishNotificationCreated($notification);
                }
            }

            $this->realtimePublisher->publishHousehold(
                householdId: (int) $createdRequest->from_household_id,
                module: 'household',
                type: 'connection_request_created',
                payload: [
                    'from_household_id' => (int) $createdRequest->from_household_id,
                    'to_household_id' => (int) $createdRequest->to_household_id,
                    'status' => (string) $createdRequest->status,
                ],
            );

            $this->realtimePublisher->publishHousehold(
                householdId: (int) $createdRequest->to_household_id,
                module: 'household',
                type: 'connection_request_created',
                payload: [
                    'from_household_id' => (int) $createdRequest->from_household_id,
                    'to_household_id' => (int) $createdRequest->to_household_id,
                    'status' => (string) $createdRequest->status,
                ],
            );
        });

        $createdRequest->loadMissing(['fromHousehold:id,name', 'toHousehold:id,name']);

        return response()->json([
            'message' => 'Demande de liaison envoyée.',
            'request' => $this->toPendingRequestPayload($createdRequest, $requesterHouseholdId),
        ], 202);
    }

    public function unlink(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureParentRole($role);

        $actorUser = $request->user();
        $notificationsToPublish = collect();
        $detachedHouseholdIds = null;

        DB::transaction(function () use (
            $household,
            $actorUser,
            &$notificationsToPublish,
            &$detachedHouseholdIds
        ): void {
            /** @var Household $sourceHousehold */
            $sourceHousehold = Household::query()
                ->whereKey((int) $household->id)
                ->lockForUpdate()
                ->firstOrFail();

            $linkedHouseholdId = (int) ($sourceHousehold->linked_household_id ?? 0);
            if ($linkedHouseholdId <= 0) {
                throw ValidationException::withMessages([
                    'connection' => ['Aucun foyer connecté à dissocier.'],
                ]);
            }

            /** @var Household|null $linkedHousehold */
            $linkedHousehold = Household::query()
                ->whereKey($linkedHouseholdId)
                ->lockForUpdate()
                ->first();

            $sourceHousehold->forceFill(['linked_household_id' => null])->save();
            if ($linkedHousehold && (int) ($linkedHousehold->linked_household_id ?? 0) === (int) $sourceHousehold->id) {
                $linkedHousehold->forceFill(['linked_household_id' => null])->save();
            }

            $detachedHouseholdIds = [
                'source' => (int) $sourceHousehold->id,
                'linked' => $linkedHousehold ? (int) $linkedHousehold->id : null,
            ];

            if ($linkedHousehold) {
                $targetParentIds = $this->resolveParentUserIds((int) $linkedHousehold->id);
                foreach ($targetParentIds as $parentId) {
                    $notification = UserNotification::query()->create([
                        'household_id' => (int) $linkedHousehold->id,
                        'user_id' => $parentId,
                        'type' => self::NOTIFICATION_TYPE_DISCONNECTED,
                        'title' => 'Liaison de foyer rompue',
                        'body' => sprintf(
                            'Le foyer %s a rompu la liaison avec votre foyer.',
                            (string) $sourceHousehold->name
                        ),
                        'data' => [
                            'source_household_id' => (int) $sourceHousehold->id,
                            'source_household_name' => (string) $sourceHousehold->name,
                            'target_household_id' => (int) $linkedHousehold->id,
                            'target_household_name' => (string) $linkedHousehold->name,
                            'triggered_by_user_id' => (int) $actorUser->id,
                            'triggered_by_user_name' => (string) ($actorUser->name ?? 'Un parent'),
                            'household_name' => (string) $linkedHousehold->name,
                        ],
                    ]);
                    $notificationsToPublish->push($notification);
                }
            }
        });

        DB::afterCommit(function () use ($notificationsToPublish, $detachedHouseholdIds): void {
            foreach ($notificationsToPublish as $notification) {
                if ($notification instanceof UserNotification) {
                    $this->publishNotificationCreated($notification);
                }
            }

            if (is_array($detachedHouseholdIds)) {
                $sourceId = (int) ($detachedHouseholdIds['source'] ?? 0);
                $linkedId = (int) ($detachedHouseholdIds['linked'] ?? 0);

                if ($sourceId > 0) {
                    $this->realtimePublisher->publishHousehold(
                        householdId: $sourceId,
                        module: 'household',
                        type: 'connection_updated',
                        payload: [
                            'household_id' => $sourceId,
                            'linked_household_id' => null,
                            'status' => 'disconnected',
                        ],
                    );
                }

                if ($linkedId > 0) {
                    $this->realtimePublisher->publishHousehold(
                        householdId: $linkedId,
                        module: 'household',
                        type: 'connection_updated',
                        payload: [
                            'household_id' => $linkedId,
                            'linked_household_id' => null,
                            'status' => 'disconnected',
                        ],
                    );
                }
            }
        });

        return response()->json([
            'message' => 'La liaison entre foyers a été supprimée.',
        ]);
    }

    private function ensureHouseholdCanStartConnectionFlow(Household $household): void
    {
        if ($this->resolveConnectedHousehold($household)) {
            throw ValidationException::withMessages([
                'connection' => ['Ce foyer est déjà connecté à un autre foyer.'],
            ]);
        }

        if ($this->resolvePendingRequestForHousehold((int) $household->id)) {
            throw ValidationException::withMessages([
                'connection' => ['Une demande de liaison est déjà en attente pour ce foyer.'],
            ]);
        }
    }

    private function resolveConnectedHousehold(Household $household): ?Household
    {
        $linkedHouseholdId = (int) ($household->linked_household_id ?? 0);
        if ($linkedHouseholdId <= 0) {
            return null;
        }

        $linkedHousehold = Household::query()->find($linkedHouseholdId);
        if (!$linkedHousehold) {
            return null;
        }

        return (int) ($linkedHousehold->linked_household_id ?? 0) === (int) $household->id
            ? $linkedHousehold
            : null;
    }

    private function resolvePendingRequestForHousehold(int $householdId): ?HouseholdLinkRequest
    {
        return HouseholdLinkRequest::query()
            ->with(['fromHousehold:id,name', 'toHousehold:id,name'])
            ->where('status', self::REQUEST_STATUS_PENDING)
            ->where(function ($query) use ($householdId): void {
                $query
                    ->where('from_household_id', $householdId)
                    ->orWhere('to_household_id', $householdId);
            })
            ->latest('id')
            ->first();
    }

    private function findReusableCode(int $householdId): ?HouseholdLinkCode
    {
        return HouseholdLinkCode::query()
            ->where('household_id', $householdId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private function normalizeCode(string $value): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($value)));
    }

    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($index = 0; $index < self::LINK_CODE_LENGTH; $index++) {
                $charIndex = random_int(0, strlen($alphabet) - 1);
                $code .= $alphabet[$charIndex];
            }

            $exists = HouseholdLinkCode::query()->where('code', $code)->exists();
        } while ($exists);

        return $code;
    }

    /**
     * @return array<int>
     */
    private function resolveParentUserIds(int $householdId): array
    {
        return DB::table('household_user')
            ->where('household_id', $householdId)
            ->where('role', User::ROLE_PARENT)
            ->pluck('user_id')
            ->map(static fn($userId): int => (int) $userId)
            ->filter(static fn(int $userId): bool => $userId > 0)
            ->values()
            ->all();
    }

    private function buildConnectionCodeShareText(string $householdName, string $code): string
    {
        return "Invitation de liaison FamilyFlow\n\n"
            . "Foyer : {$householdName}\n"
            . "Code de liaison : {$code}\n\n"
            . "Ouvre FamilyFlow > Modifier le foyer > Foyer connecté, puis encode ce code.";
    }

    private function toPendingRequestPayload(HouseholdLinkRequest $request, int $currentHouseholdId): array
    {
        $isOutgoing = (int) $request->from_household_id === $currentHouseholdId;
        $otherHousehold = $isOutgoing ? $request->toHousehold : $request->fromHousehold;

        return [
            'id' => (int) $request->id,
            'direction' => $isOutgoing ? 'outgoing' : 'incoming',
            'status' => (string) $request->status,
            'created_at' => optional($request->created_at)->toIso8601String(),
            'other_household' => $otherHousehold
                ? [
                    'id' => (int) $otherHousehold->id,
                    'name' => (string) $otherHousehold->name,
                ]
                : null,
        ];
    }

    private function publishNotificationCreated(UserNotification $notification): void
    {
        $this->realtimePublisher->publishUser(
            userId: (int) $notification->user_id,
            module: 'notifications',
            type: 'notification_created',
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => (int) $notification->household_id,
                'type' => (string) $notification->type,
                'title' => (string) $notification->title,
                'body' => (string) $notification->body,
            ],
        );
    }
}
