<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\MealSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class HouseholdController extends Controller
{
    public function store(Request $request)
    {
        if ($request->user()->households()->exists()) {
            return response()->json(['message' => 'Vous avez déjà un foyer.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'modules' => 'array',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($validated, $user) {
            $household = Household::create(['name' => $validated['name']]);
            $modules = $validated['modules'] ?? [];
            HouseholdSetting::create([
                'household_id' => $household->id,
                'has_meals' => in_array('meals', $modules),
                'has_shopping_list' => in_array('shopping_list', $modules),
                'has_tasks' => in_array('tasks', $modules),
                'has_budget' => in_array('budget', $modules),
                'has_calendar' => in_array('calendar', $modules),
            ]);
            MealSetting::create(['household_id' => $household->id]);

            $household->users()->attach($user->id, ['role' => 'parent', 'nickname' => 'Admin']);

            return response()->json([
                'message' => 'Foyer créé ! Vous pouvez maintenant ajouter des membres.',
                'household' => $household
            ], 201);
        });
    }
    public function addMember(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'role' => 'required|in:parent,enfant',
        ]);

        $adminUser = $request->user();

        $household = $adminUser->households()->wherePivot('role', 'parent')->first();
        if (!$household) $household = $adminUser->households()->firstOrFail();

        if (empty($validated['email'])) {
            $cleanName = Str::slug($validated['name']);
            $randomCode = Str::random(4);
            $finalEmail = "{$cleanName}.{$randomCode}@family.app";
        } else {
            $finalEmail = $validated['email'];
        }

        $rawPassword = Str::random(8);

        return DB::transaction(function () use ($validated, $finalEmail, $household, $rawPassword) {

            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $finalEmail,
                'password' => Hash::make($rawPassword),
                'must_change_password' => true,
            ]);

            $household->users()->attach($newUser->id, [
                'role' => $validated['role'],
                'nickname' => $validated['name']
            ]);

            if ($validated['role'] === 'enfant') {
                BudgetSetting::create([
                    'household_id' => $household->id,
                    'user_id' => $newUser->id,
                    'base_amount' => 0
                ]);
            }

            $shareText = "Coucou ! Voici tes accès pour FamilyApp.\n\n" .
                "Login : " . $finalEmail . "\n" .
                "Mot de passe : " . $rawPassword . "\n\n" .
                "Connecte-toi vite !";

            return response()->json([
                'message' => 'Compte créé avec succès',
                'user' => $newUser,
                'generated_password' => $rawPassword,
                'generated_email' => $finalEmail,
                'share_text' => $shareText
            ], 201);
        });
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $household = $user->households()->first();

        if (!$household) {
            return response()->json(['message' => 'Aucun foyer', 'requires_setup' => true]);
        }

        $household->load(['users', 'mealPolls' => fn($q) => $q->where('status', 'open')]);

        return response()->json([
            'household_name' => $household->name,
            'members' => $household->users,
            'active_poll' => $household->mealPolls->first(),
        ]);
    }
}
