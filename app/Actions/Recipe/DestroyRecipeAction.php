<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class DestroyRecipeAction
{
    public function execute(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe): void {
            $recipe->savedByHouseholds()->detach();
            $recipe->ingredients()->detach();
            $recipe->delete();
        });
    }
}
