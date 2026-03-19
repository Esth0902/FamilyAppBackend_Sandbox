<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\DeleteTaskTemplateAction;
use App\Actions\Tasks\RequestTaskReassignmentAction;
use App\Actions\Tasks\UpsertTaskTemplateAction;
use App\Actions\Tasks\UpdateTaskInstanceAction;
use App\Actions\Tasks\ValidateTaskInstanceAction;
use App\Http\Controllers\Controller;
use App\DTOs\Tasks\UpsertTaskTemplateDTO;
use App\Http\Requests\Tasks\DestroyTaskTemplateRequest;
use App\Http\Requests\Tasks\RequestTaskReassignmentRequest;
use App\Http\Requests\Tasks\StoreTaskInstanceRequest;
use App\Http\Requests\Tasks\StoreTaskTemplateRequest;
use App\Http\Requests\Tasks\TaskBoardRequest;
use App\Http\Requests\Tasks\UpdateTaskInstanceRequest;
use App\Http\Requests\Tasks\UpdateTaskTemplateRequest;
use App\Http\Requests\Tasks\ValidateTaskInstanceRequest;
use App\Http\Resources\Tasks\TaskBoardResource;
use App\Http\Resources\Tasks\TaskInstanceMutationResource;
use App\Http\Resources\Tasks\TaskMessageResource;
use App\Http\Resources\Tasks\TaskReassignmentResponseResource;
use App\Http\Resources\Tasks\TaskTemplateMutationResource;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Queries\Tasks\GetTaskBoardQuery;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
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

    public function board(TaskBoardRequest $request): JsonResponse
    {
        [$fromDate, $toDate] = $request->range();
        $board = $this->getTaskBoardQuery->execute($request->household(), $request->householdRole(), (int) $request->user()->id, $fromDate, $toDate);

        return response()->json((new TaskBoardResource($board))->resolve($request), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function storeTemplate(StoreTaskTemplateRequest $request): JsonResponse
    {
        $payload = UpsertTaskTemplateDTO::fromValidated($request->validated());
        $template = $this->upsertTaskTemplateAction->execute($request->household(), $request->user(), $payload);

        return response()->json(TaskTemplateMutationResource::created($template)->resolve($request), 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function updateTemplate(UpdateTaskTemplateRequest $request, TaskTemplate $template): JsonResponse
    {
        $payload = UpsertTaskTemplateDTO::fromValidated($request->validated());
        $template = $this->upsertTaskTemplateAction->execute($request->household(), $request->user(), $payload, $template);

        return response()->json(TaskTemplateMutationResource::updated($template)->resolve($request), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function destroyTemplate(DestroyTaskTemplateRequest $request, TaskTemplate $template): JsonResponse
    {
        $this->deleteTaskTemplateAction->execute($request->household(), $template);

        return response()->json(TaskMessageResource::makeMessage('Template de tâche supprimé.')->resolve($request), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function requestInstanceReassignment(RequestTaskReassignmentRequest $request, TaskInstance $instance): JsonResponse
    {
        $invitation = $this->requestTaskReassignmentAction->execute($request->household(), $request->user(), $instance, (int) $request->validated('invited_user_id'));

        return response()->json(TaskReassignmentResponseResource::sent($invitation)->resolve($request), 202, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function storeInstance(StoreTaskInstanceRequest $request): JsonResponse
    {
        $instance = $this->createTaskInstanceAction->execute($request->household(), $request->householdRole(), $request->user(), $request->validated());

        return response()->json(TaskInstanceMutationResource::created($instance)->resolve($request), 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function updateInstance(UpdateTaskInstanceRequest $request, TaskInstance $instance): JsonResponse
    {
        $instance = $this->updateTaskInstanceAction->execute($request->household(), $request->user(), $instance, $request->validated());

        return response()->json(TaskInstanceMutationResource::updated($instance)->resolve($request), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function validateInstance(ValidateTaskInstanceRequest $request, TaskInstance $instance): JsonResponse
    {
        $instance = $this->validateTaskInstanceAction->execute($request->household(), $request->user(), $instance);

        return response()->json(TaskInstanceMutationResource::validated($instance)->resolve($request), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
