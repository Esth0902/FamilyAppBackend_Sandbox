<?php

namespace Database\Seeders;

use App\Models\DietaryTag;
use App\Models\Event;
use App\Models\User;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\Recipe;
use App\Models\Ingredient;
use App\Models\TaskTemplate;
use App\Models\MealSetting;
use App\Models\BudgetSetting;
use App\Models\MealPoll;
use App\Models\MealPollOption;
use App\Models\MealPollVote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Finder\Glob;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DietaryTagSeeder::class);
        $this->call(GlobalRecipeSeeder::class);

        // 1. CRÉATION DES UTILISATEURS
        $parent1 = User::create([
            'name' => 'Vinciane',
            'email' => 'vinciane@test.com',
            'password' => Hash::make('password'),
        ]);

        $parent2 = User::create([
            'name' => 'Vincent',
            'email' => 'vincent@test.com',
            'password' => Hash::make('password'),
        ]);

        $parent3 = User::create([
            'name' => 'Esther',
            'email' => 'esther@test.com',
            'password' => Hash::make('password'),
        ]);

        $enfant1 = User::create([
            'name' => 'Alexandre',
            'email' => 'alexandre@test.com',
            'password' => Hash::make('password'),
        ]);

        $enfant2 = User::create([
            'name' => 'Thibault',
            'email' => 'thibault@test.com',
            'password' => Hash::make('password'),
        ]);

        $enfant3 = User::create([
            'name' => 'Nathan',
            'email' => 'nathan@test.com',
            'password' => Hash::make('password'),
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);

        // 2. CRÉATION DES FOYERS (Garde alternée)
        $maisonPapa = Household::create(['name' => 'Chez Papa', 'is_joint_custody' => true]);
        $maisonMaman = Household::create(['name' => 'Florisquatteurs', 'is_joint_custody' => true]);

        // Lien entre les deux maisons
            //$maisonPapa->update(['linked_household_id' => $maisonMaman->id]);
            //$maisonMaman->update(['linked_household_id' => $maisonPapa->id]);

        // 3. ATTACHEMENT DES USERS (Pivot)

        $maisonMaman->users()->attach([
            $parent1->id => ['role' => 'parent', 'nickname' => 'Mamounette'],
            $parent3->id => ['role' => 'parent', 'nickname' => null],
            $enfant1->id => ['role' => 'enfant', 'nickname' => 'Alex'],
            $enfant2->id => ['role' => 'enfant', 'nickname' => 'Bo'],
            $enfant3->id => ['role' => 'enfant', 'nickname' => 'Nath'],
        ]);

        $maisonPapa->users()->attach([
            $parent2->id => ['role' => 'parent', 'nickname' => null],
            $enfant1->id => ['role' => 'enfant', 'nickname' => null],
            $enfant2->id => ['role' => 'enfant', 'nickname' => null],
            $enfant3->id => ['role' => 'enfant', 'nickname' => null],
        ]);

/*
        4. RÉGLAGES DES MODULES
        foreach ([$maisonPapa, $maisonMaman] as $foyer) {
            HouseholdSetting::create(['household_id' => $foyer->id]);
            MealSetting::create(['household_id' => $foyer->id]);
        }

        $tagIds = DietaryTag::whereIn('key', ['vegan', 'crustaces', 'pas-de-coriandre'])->pluck('id');
        $maisonPapa->dietaryTags()->syncWithoutDetaching($tagIds);

        5. BUDGETS
        BudgetSetting::create([
            'household_id' => $maisonPapa->id,
            'user_id' => $enfant1->id,
            'base_amount' => 75.00,
            'recurrence' => 'monthly',
        ]);
        BudgetSetting::create([
            'household_id' => $maisonPapa->id,
            'user_id' => $enfant2->id,
            'base_amount' => 75.00,
            'recurrence' => 'monthly',
        ]);
        BudgetSetting::create([
            'household_id' => $maisonPapa->id,
            'user_id' => $enfant3->id,
            'base_amount' => 75.00,
            'recurrence' => 'monthly',
        ]);
        BudgetSetting::create([
            'household_id' => $maisonMaman->id,
            'user_id' => $enfant1->id,
            'base_amount' => 100.00,
            'recurrence' => 'monthly',
        ]);
        BudgetSetting::create([
            'household_id' => $maisonMaman->id,
            'user_id' => $enfant2->id,
            'base_amount' => 100.00,
            'recurrence' => 'monthly',
        ]);
        BudgetSetting::create([
            'household_id' => $maisonMaman->id,
            'user_id' => $enfant3->id,
            'base_amount' => 100.00,
            'recurrence' => 'monthly',
        ]);

        TaskTemplate::create([
            'household_id' => $maisonPapa->id,
            'name' => 'Sortir les poubelles',
            'is_rotation' => true,
        ]);
        TaskTemplate::create([
            'household_id' => $maisonPapa->id,
            'name' => 'Faire la vaisselle',
            'is_rotation' => true,
        ]);
        TaskTemplate::create([
            'household_id' => $maisonPapa->id,
            'name' => 'Faire la lessive',
            'is_rotation' => true,
        ]);

        TaskTemplate::create([
            'household_id' => $maisonMaman->id,
            'name' => 'Sortir les poubelles',
            'is_rotation' => true,
        ]);
        TaskTemplate::create([
            'household_id' => $maisonMaman->id,
            'name' => 'Faire la vaisselle',
            'is_rotation' => true,
        ]);
        TaskTemplate::create([
            'household_id' => $maisonMaman->id,
            'name' => 'Faire la lessive',
            'is_rotation' => true,
        ]);
*/
        // 7. RECETTES ET INGRÉDIENTS
        $pates = Ingredient::create(['name' => 'Pâtes', 'category' => 'épicerie salée']);
        $tomate = Ingredient::create(['name' => 'Sauce Tomate', 'category' => 'épicerie salée']);

        $recettepapa = Recipe::create([
            'household_id' => $maisonPapa->id,
            'title' => 'Pâtes à la tomate',
            'type' => 'plat principal',
            'instructions' => 'Cuire les pâtes et ajouter la sauce.',
        ]);
        $recettemaman = Recipe::create([
            'household_id' => $maisonMaman->id,
            'title' => 'Pâtes à la tomate',
            'type' => 'plat principal',
            'instructions' => 'Cuire les pâtes et ajouter la sauce.',
        ]);

        $recettepapa->ingredients()->attach([
            $pates->id => ['quantity' => 500, 'unit' => 'g'],
            $tomate->id => ['quantity' => 1, 'unit' => 'pot'],
        ]);
        $recettemaman->ingredients()->attach([
            $pates->id => ['quantity' => 500, 'unit' => 'g'],
            $tomate->id => ['quantity' => 1, 'unit' => 'pot'],
        ]);
/*
        // 8. SONDAGE REPAS
        $poll = MealPoll::create([
            'household_id' => $maisonMaman->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
            'status' => 'open',
        ]);

        // Création de l'option (Lien Sondage <-> Recette)
        $optionPates = MealPollOption::create([
            'meal_poll_id' => $poll->id,
            'recipe_id' => $recettemaman->id,
        ]);

        // Vote d'Alex pour cette option
        MealPollVote::create([
            'meal_poll_id' => $poll->id,
            'user_id' => $enfant1->id,
            'meal_poll_option_id' => $optionPates->id,
        ]);

        Event::create([
            'household_id' => $maisonMaman->id,
            'created_by_user_id' => $parent1->id,
            'title' => 'Rendez-vous Dentiste',
            'description' => 'Ne pas oublier le carnet de santé',
            'start_at' => now()->addDays(1)->setHour(14)->setMinute(0),
            'end_at' => now()->addDays(1)->setHour(15)->setMinute(0),
            'is_shared_with_other_household' => true,
        ]);
*/
    }
}
