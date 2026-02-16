<?php

namespace Database\Seeders;

use App\Models\DietaryTag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DietaryTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $create = function (string $type, string $label) {
            $key = Str::slug($label);

            if ($key === '') {
                $key = Str::slug(Str::ascii($label)) ?: md5($label);
            }

            DietaryTag::firstOrCreate(
                ['type' => $type, 'key' => $key],
                [
                    'label' => $label,
                    'is_system' => true,
                    'created_by_household_id' => null,
                    'embedding' => null,
                ]
            );
        };

        foreach ([
                     'Vegan',
                     'Végétarien',
                     'Pescétarien',
                     'Flexitarien',
                     'Keto',
                     'Paléo',
                     'Méditerranéen',
                     'Low carb',
                     'High protein',
                     'Sans gluten',
                     'Sans lactose',
                     'Low FODMAP',
                     'Diabétique friendly',
                     'Hyposodé (faible en sel)',
                 ] as $label) {
            $create('diet', $label);
        }

        foreach ([
                     'Arachides (cacahuètes)',
                     'Fruits à coque (noix, amandes, etc.)',
                     'Lait',
                     'Œufs',
                     'Soja',
                     'Blé (gluten)',
                     'Sésame',
                     'Poisson',
                     'Crustacés',
                     'Mollusques',
                     'Moutarde',
                     'Céleri',
                     'Lupin',
                     'Sulfites',
                 ] as $label) {
            $create('allergen', $label);
        }

        foreach ([
                     'Pas de champignons',
                     'Pas de coriandre',
                     'Pas d’ail',
                     'Pas d’oignons',
                     'Pas de poivron',
                     'Pas d’olives',
                     'Pas de fromage',
                     'Pas de poisson',
                     'Pas de fruits de mer',
                     'Pas de viande rouge',
                     'Pas de plats trop épicés',
                     'Pas de sucré-salé',
                 ] as $label) {
            $create('dislike', $label);
        }

        foreach ([
                     'Halal',
                     'Kasher',
                     'Sans porc',
                     'Sans bœuf',
                     'Sans alcool',
                     'Sans gélatine',
                     'Sans miel',
                     'Sans caféine',
                     'Sans aliments crus',
                     'Sans friture',
                     'Sans sucre ajouté',
                 ] as $label) {
            $create('restriction', $label);
        }

        foreach ([
                     'Recettes rapides (≤ 20 min)',
                     'Batch cooking',
                     'One-pot (une seule casserole)',
                     'Air fryer',
                     'Sans four',
                     'Sans micro-ondes',
                     'Sans robot',
                     'Budget serré',
                     'Peu d’ingrédients (≤ 6)',
                     'Recettes pour enfants',
                     'Meal prep friendly',
                 ] as $label) {
            $create('cuisine_rule', $label);
        }
    }
}
