<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\DeleteTaskTemplateAction;
use App\Actions\Tasks\RequestTaskReassignmentAction;
use App\Actions\Tasks\UpsertTaskTemplateAction;
use App\Actions\Tasks\UpdateTaskInstanceAction;
use App\Actions\Tasks\ValidateTaskInstanceAction;
use App\Events\Tasks\TaskTemplateCreatedEvent;
use App\Events\Tasks\TaskTemplateUpdatedEvent;
use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\RequestTaskReassignmentRequest;
use App\Http\Requests\Tasks\StoreTaskInstanceRequest;
use App\Http\Requests\Tasks\StoreTaskTemplateRequest;
use App\Http\Requests\Tasks\UpdateTaskInstanceRequest;
use App\Http\Requests\Tasks\UpdateTaskTemplateRequest;
use App\Http\Resources\Tasks\TaskBoardResource;
use App\Http\Resources\Tasks\TaskInstanceResource;
use App\Http\Resources\Tasks\TaskTemplateResource;
use App\Models\Household;
use App\Models\TaskTemplate;
use App\Models\UserNotification;
use App\Queries\Tasks\GetTaskBoardQuery;
use App\Support\Normalization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use InteractsWithTaskContext;

    private const DEFAULT_RANGE_DAYS = 14;
    private const MAX_RANGE_DAYS = 45;

    public function __construct(
        private readonly GetTaskBoardQuery $getTaskBoardQuery,
        private readonly UpsertTaskTemplateAction $upsertTaskTemplateAction,
        private readonly CreateTaskInstanceAction $createTaskInstanceAction,
        private readonly UpdateTaskInstanceAction $updateTaskInstanceAction,
        private readonly ValidateTaskInstanceAction $validateTaskInstanceAction,
        private readonly DeleteTaskTemplateAction $deleteTaskTemplateAction,
        private readonly RequestTaskReassignmentAction $requestTaskReassignmentAction,
    ) {
    }

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        [$fromDate, $toDate] = Normalization::dateRange($request, self::DEFAULT_RANGE_DAYS, self::MAX_RANGE_DAYS);
        $board = $this->getTaskBoardQuery->execute($household, $role, (int) $request->user()->id, $fromDate, $toDate);

        return response()->json((new TaskBoardResource($board))->resolve($request));
    }

    public function storeTemplate(StoreTaskTemplateRequest $request): JsonResponse
    {
        $household = $request->household();
        $template = $this->upsertTaskTemplateAction->execute($household, $request->validated());
        $this->dispatchTaskTemplateCreated($request, $household, $template);

        return response()->json(['message' => 'Template de tâche créé.', 'template' => TaskTemplateResource::make($template)->resolve($request)], 201);
    }

    public function updateTemplate(UpdateTaskTemplateRequest $request, TaskTemplate $template): JsonResponse
    {
        $household = $request->household();
        $template = $this->upsertTaskTemplateAction->execute($household, $request->validated(), $template);
        $this->dispatchTaskTemplateUpdated($household, $template);

        return response()->json(['message' => 'Template de tâche mis à jour.', 'template' => TaskTemplateResource::make($template)->resolve($request)]);
    }

    public function destroyTemplate(Request $request, TaskTemplate $template): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->deleteTaskTemplateAction->execute($household, $role, $template);

        return response()->json(['message' => 'Template de tâche supprimé.']);
    }

    public function requestInstanceReassignment(RequestTaskReassignmentRequest $request, \App\Models\TaskInstance $instance): JsonResponse
    {
        $invitationNotification = $this->requestTaskReassignmentAction->execute($request->household(), $request->user(), $instance, (int) $request->validated('invited_user_id'));

        return response()->json([
            'message' => 'Demande envoyée.',
            'invitation' => $this->toInvitationPayload($invitationNotification),
        ], 202);
    }

    public function storeInstance(StoreTaskInstanceRequest $request): JsonResponse
    {
        $instance = $this->createTaskInstanceAction->execute($request->household(), $request->householdRole(), $request->user(), $request->validated());

        return response()->json(['message' => 'Tâche créée.', 'instance' => TaskInstanceResource::make($instance)->resolve($request)], 201);
    }

    public function updateInstance(UpdateTaskInstanceRequest $request, \App\Models\TaskInstance $instance): JsonResponse
    {
        $instance = $this->updateTaskInstanceAction->execute($request->household(), $request->householdRole(), $request->user(), $instance, $request->validated());

        return response()->json(['message' => 'Tâche mise à jour.', 'instance' => TaskInstanceResource::make($instance)->resolve($request)]);
    }

    public function validateInstance(Request $request, \App\Models\TaskInstance $instance): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $instance = $this->validateTaskInstanceAction->execute($household, $role, $request->user(), $instance);

        return response()->json(['message' => 'Tâche validée.', 'instance' => TaskInstanceResource::make($instance)->resolve($request)]);
    }

    private function dispatchTaskTemplateCreated(
        StoreTaskTemplateRequest $request,
        Household $household,
        TaskTemplate $template
    ): void {
        event(new TaskTemplateCreatedEvent(
            template: $template,
            householdId: (int) $household->id,
            actorUserId: (int) $request->user()->id,
            actorName: (string) ($request->user()->name ?? 'Un membre'),
        ));
    }

    private function dispatchTaskTemplateUpdated(Household $household, TaskTemplate $template): void
    {
        event(new TaskTemplateUpdatedEvent(
            template: $template,
            householdId: (int) $household->id,
        ));
    }

    /**
     * @return array<string, int|string>
     */
    private function toInvitationPayload(UserNotification $invitationNotification): array
    {
        return [
            'notification_id' => (int) $invitationNotification->id,
            'status' => (string) data_get($invitationNotification->data, 'status', 'pending'),
            'task_instance_id' => (int) data_get($invitationNotification->data, 'task_instance_id'),
            'invited_user_id' => (int) data_get($invitationNotification->data, 'invited_user_id'),
        ];
    }
}
