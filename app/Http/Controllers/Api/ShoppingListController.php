<?php

namespace App\Http\Controllers\Api;

use App\Actions\ShoppingList\{AddShoppingListItemAction, ClearCheckedItemsAction, DestroyShoppingListAction, GetShoppingListsAction, RemoveShoppingListItemAction, ShowShoppingListAction, StoreShoppingListAction, ToggleShoppingListItemAction, UpdateShoppingListItemAction};
use App\Http\Controllers\Controller;
use App\Http\Requests\ShoppingList\{AddShoppingListItemRequest, ClearCheckedItemsRequest, DestroyShoppingListRequest, GetShoppingListRequest, RemoveShoppingListItemRequest, StoreShoppingListRequest, ToggleShoppingListItemRequest, UpdateShoppingListItemRequest};
use App\Http\Resources\ShoppingList\{ShoppingListDetailResource, ShoppingListIndexResource, ShoppingListItemResource, ShoppingListMessageResource, ShoppingListMutationResource};
use App\Models\{ShoppingList, ShoppingListItem};
use Illuminate\Http\JsonResponse;

class ShoppingListController extends Controller
{
    public function index(GetShoppingListRequest $request, GetShoppingListsAction $action): JsonResponse
    {
        $payload = $action->execute($request->household(), $request->householdRole());
        return ShoppingListIndexResource::fromContext($payload['can_manage'], $payload['lists'])->response();
    }

    public function storeList(StoreShoppingListRequest $request, StoreShoppingListAction $action): JsonResponse
    {
        $list = $action->execute($request->household(), $request->user(), (string) $request->validated('title'));
        return ShoppingListMutationResource::created($list, 'Liste creee.')->response()->setStatusCode(201);
    }

    public function showList(GetShoppingListRequest $request, ShoppingList $list, ShowShoppingListAction $action): JsonResponse
    {
        $payload = $action->execute($request->household(), $request->householdRole(), $list);
        return ShoppingListDetailResource::fromContext($payload['can_manage'], $payload['can_add_manual_items'], $payload['list'], $payload['from_date'], $payload['to_date'], $payload['meal_plans'])->response();
    }

    public function destroyList(DestroyShoppingListRequest $request, ShoppingList $list, DestroyShoppingListAction $action): JsonResponse
    {
        $action->execute($request->household(), $request->user(), $list);
        return ShoppingListMessageResource::makeMessage('Liste supprimee.')->response();
    }

    public function addItem(AddShoppingListItemRequest $request, ShoppingList $list, AddShoppingListItemAction $action): JsonResponse
    {
        $item = $action->execute($request->household(), $list, $request->user(), $request->validated(), $request->isManualAddition());
        return ShoppingListItemResource::make($item)->response()->setStatusCode(201);
    }

    public function updateItem(UpdateShoppingListItemRequest $request, ShoppingListItem $item, UpdateShoppingListItemAction $action): JsonResponse
    {
        $updatedItem = $action->execute($request->household(), $item, $request->user(), $request->validated());
        return ShoppingListItemResource::make($updatedItem)->response();
    }

    public function toggleItem(ToggleShoppingListItemRequest $request, ShoppingListItem $item, ToggleShoppingListItemAction $action): JsonResponse
    {
        $updatedItem = $action->execute($request->household(), $item, $request->user(), (bool) $request->validated('is_checked'));
        return ShoppingListItemResource::make($updatedItem)->response();
    }

    public function clearCheckedItems(ClearCheckedItemsRequest $request, ShoppingList $list, ClearCheckedItemsAction $action): JsonResponse
    {
        $deletedCount = $action->execute($request->household(), $list, $request->user());
        return ShoppingListMessageResource::makeMessage('Elements coches supprimes.', $deletedCount)->response();
    }

    public function removeItem(RemoveShoppingListItemRequest $request, ShoppingListItem $item, RemoveShoppingListItemAction $action): JsonResponse
    {
        $action->execute($request->household(), $request->user(), $item);
        return ShoppingListMessageResource::makeMessage('Element supprime')->response();
    }
}
