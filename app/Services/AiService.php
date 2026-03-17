<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class AiService
{
    public const RECIPE_TYPES = [
        'petit-déjeuner','entrée','plat principal','dessert','collation','boisson','autre'
    ];

    public const INGREDIENT_CATEGORIES = [
        'fruits et légumes',
        'boucherie',
        'poissonnerie',
        'crèmerie',
        'épicerie salée',
        'épicerie sucrée',
        'boissons',
        'surgelés',
        'entretien et hygiène',
        'autre',
    ];

    public function suggestMealIdeas(int $count = 5, string $preferences = '')
    {
        $preferences = $this->sanitizeUserInput($preferences, 500);

        $systemPrompt = $this->getSystemPrompt(
            "Tu es un assistant culinaire.
MISSION : Générer exactement {$count} idées de recettes distinctes.

RÈGLES :
1) Réponds UNIQUEMENT avec un tableau JSON valide (aucun texte).
2) Le tableau contient exactement {$count} objets.
3) Format :
   [{\"title\":\"Nom\",\"type\":\"plat principal\",\"description\":\"...\"}]
4) type ∈ " . json_encode(self::RECIPE_TYPES, JSON_UNESCAPED_UNICODE) . "
"
        );

        $userPrompt = empty($preferences)
            ? "Suggère-moi {$count} idées variées pour 1 personne."
            : "Préférences utilisateur : {$preferences}. Génère {$count} idées.";

        return $this->executeRequest($systemPrompt, $userPrompt, expected: 'list', count: $count);
    }

    public function getFullRecipeDetails(string $title, string $preferences = '')
    {
        $title = $this->sanitizeUserInput($title);
        $preferences = $this->sanitizeUserInput($preferences, 500);

        $systemPrompt = $this->getSystemPrompt(
            "Tu es un expert en recettes et en gestion de courses.
RÈGLES :
1) Réponds UNIQUEMENT avec un objet JSON valide (aucun texte).
2) Format :
{
  \"title\":\"Nom\",
  \"type\":\"plat principal\",
  \"description\":\"...\",
  \"instructions\":\"...\",
  \"ingredients\":[{\"name\":\"tomates\",\"quantity\":3,\"unit\":\"pcs\",\"category\":\"fruits et légumes\"}]
}
3) type ∈ " . json_encode(self::RECIPE_TYPES, JSON_UNESCAPED_UNICODE) . "
4) category ∈ " . json_encode(self::INGREDIENT_CATEGORIES, JSON_UNESCAPED_UNICODE) . "
5) quantity = nombre (int/float). 0 si au goût.
6) 1 portion, métrique.
"
        );

        $userPrompt = empty($preferences)
            ? "Donne-moi la recette complète pour : {$title}."
            : "Donne-moi la recette complète pour : {$title}. Contraintes et préférences à respecter : {$preferences}.";

        return $this->executeRequest($systemPrompt, $userPrompt, expected: 'object');
    }

    private function executeRequest(string $systemPrompt, string $userPrompt, string $expected, int $count = 5)
    {
        $model = env('OPENROUTER_MODEL') ?: 'auto';

        try {
            $result = OpenAI::chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
            ]);

            $content = $result->choices[0]->message->content ?? '';

            if (config('app.debug')) {
                Log::debug("AI RAW (truncated): " . Str::limit($content, 900));
            }

            $json = $this->extractFirstJson($content);
            if (!$json) return $this->fallback($expected, $count);

            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) return $this->fallback($expected, $count);

            $normalized = $this->validateAndNormalize($decoded, $expected, $count);
            return $normalized ?? $this->fallback($expected, $count);

        } catch (\Throwable $e) {
            Log::error("AiService crash: " . $e->getMessage());
            return $this->fallback($expected, $count);
        }
    }

    private function validateAndNormalize(mixed $decoded, string $expected, int $count): mixed
    {
        if ($expected === 'list') {
            if (!is_array($decoded) || !array_is_list($decoded)) return null;

            $decoded = array_slice($decoded, 0, max(1, min($count, 20)));
            $out = [];

            foreach ($decoded as $item) {
                if (!is_array($item)) return null;

                $title = trim((string)($item['title'] ?? ''));
                $desc  = trim((string)($item['description'] ?? ''));
                $type  = trim((string)($item['type'] ?? 'autre'));

                if ($title === '' || $desc === '') return null;
                $type = $this->normalizeRecipeType($type);

                $out[] = ['title' => $title, 'type' => $type, 'description' => $desc];
            }

            while (count($out) < $count) {
                $out[] = ['title' => 'Idée supplémentaire', 'type' => 'autre', 'description' => 'Suggestion ajoutée.'];
            }

            return $out;
        }

        if ($expected === 'object') {
            if (!is_array($decoded) || array_is_list($decoded)) return null;

            foreach (['title','type','description','instructions','ingredients'] as $k) {
                if (!array_key_exists($k, $decoded)) return null;
            }

            $title = trim((string)$decoded['title']);
            $type  = $this->normalizeRecipeType((string)$decoded['type']);
            $desc  = trim((string)$decoded['description']);
            $instr = trim((string)$decoded['instructions']);
            $ings  = $decoded['ingredients'];

            if ($title === '' || $desc === '' || $instr === '') return null;
            if (!is_array($ings) || !array_is_list($ings) || count($ings) === 0) return null;

            $norm = [];
            foreach ($ings as $ing) {
                if (!is_array($ing)) return null;

                $name = $this->normalizeIngredientName((string)($ing['name'] ?? ''));
                $unit = isset($ing['unit']) ? trim((string)$ing['unit']) : null;
                $q    = $ing['quantity'] ?? null;
                $cat  = $this->normalizeIngredientCategory((string)($ing['category'] ?? 'autre'));

                if ($name === '') return null;
                if (!(is_int($q) || is_float($q) || (is_string($q) && is_numeric($q)))) return null;

                $norm[] = [
                    'name' => $name,
                    'quantity' => (float)$q,
                    'unit' => $unit,
                    'category' => $cat,
                ];
            }

            return [
                'title' => $title,
                'type' => $type,
                'description' => $desc,
                'instructions' => $instr,
                'ingredients' => $norm,
            ];
        }

        return null;
    }

    private function normalizeRecipeType(string $type): string
    {
        $type = mb_strtolower(trim($type));
        // petits alias possibles
        $aliases = [
            'petit déjeuner' => 'petit-déjeuner',
            'petitdej' => 'petit-déjeuner',
            'plat' => 'plat principal',
            'principal' => 'plat principal',
            'snack' => 'collation',
        ];
        $type = $aliases[$type] ?? $type;

        return in_array($type, self::RECIPE_TYPES, true) ? $type : 'autre';
    }

    private function normalizeIngredientCategory(string $cat): string
    {
        $cat = mb_strtolower(trim($cat));

        // Alias mapping depuis tes anciennes catégories possibles
        $aliases = [
            'fruits & légumes' => 'fruits et légumes',
            'fruit et légumes' => 'fruits et légumes',
            'legumes' => 'fruits et légumes',
            'légumes' => 'fruits et légumes',

            'epicerie salee' => 'épicerie salée',
            'épicerie salee' => 'épicerie salée',
            'epicerie sucree' => 'épicerie sucrée',
            'épicerie sucree' => 'épicerie sucrée',

            'frais' => 'autre',          // tu n’as plus 'frais' en DB
            'boulangerie' => 'autre',     // idem
            'autres' => 'autre',
        ];

        $cat = $aliases[$cat] ?? $cat;

        return in_array($cat, self::INGREDIENT_CATEGORIES, true) ? $cat : 'autre';
    }

    private function normalizeIngredientName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\([^)]*\)/u', '', $name); // enlève parenthèses
        $name = preg_replace('/[.,;:!?"]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return trim($name);
    }

    private function fallback(string $expected, int $count)
    {
        if ($expected === 'list') {
            $out = [];
            for ($i = 1; $i <= max(1, min($count, 10)); $i++) {
                $out[] = ['title' => "Idée #{$i}", 'type' => 'plat principal', 'description' => "Suggestion de secours."];
            }
            return $out;
        }

        return [
            'title' => "Recette de secours",
            'type' => "plat principal",
            'description' => "Recette fallback.",
            'instructions' => "1) Préparer\n2) Cuire\n3) Servir",
            'ingredients' => [
                ['name' => 'tomates', 'quantity' => 3, 'unit' => 'pcs', 'category' => 'fruits et légumes'],
                ['name' => 'pâtes', 'quantity' => 120, 'unit' => 'g', 'category' => 'épicerie salée'],
            ],
        ];
    }

    private function getSystemPrompt(string $specificInstructions): string
    {
        $context = "Tu cuisines pour 1 personne en Belgique, système métrique.
Réponds uniquement en JSON valide, aucun texte, jamais.";
        return trim($context . "\n\n" . $specificInstructions);
    }

    private function extractFirstJson(string $text): ?string
    {
        $posObj = strpos($text, '{');
        $posArr = strpos($text, '[');
        if ($posObj === false && $posArr === false) return null;

        if ($posObj === false || ($posArr !== false && $posArr < $posObj)) {
            $start = $posArr; $open = '['; $close = ']';
        } else {
            $start = $posObj; $open = '{'; $close = '}';
        }

        $level = 0; $inString = false; $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];

            if ($inString) {
                if ($escape) { $escape = false; continue; }
                if ($c === '\\') { $escape = true; continue; }
                if ($c === '"') $inString = false;
                continue;
            }

            if ($c === '"') { $inString = true; continue; }
            if ($c === $open) $level++;
            if ($c === $close) {
                $level--;
                if ($level === 0) return substr($text, $start, $i - $start + 1);
            }
        }
        return null;
    }

    private function sanitizeUserInput(string $text, int $maxLen = 240): string
    {
        $text = trim(mb_substr(trim($text), 0, $maxLen));
        $text = preg_replace('/https?:\/\/\S+/i', '', $text);
        $text = preg_replace('/\b(system prompt|developer message|role:|assistant:|user:)\b/i', '', $text);
        $text = preg_replace('/<\s*script\b[^>]*>/i', '', $text);
        $text = preg_replace('/\{.*\}|\[.*\]/s', '', $text);
        $text = trim($text);

        $techChars = preg_match_all('/[{}[\]<>$`~=^|\\\]/', $text);
        return ($techChars > 8) ? '' : $text;
    }
}
