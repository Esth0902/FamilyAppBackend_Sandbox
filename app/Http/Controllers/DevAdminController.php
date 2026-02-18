<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DevAdminController extends Controller
{
    public function index(): View
    {
        $tables = $this->listTables()
            ->map(function (string $table): array {
                return [
                    'name' => $table,
                    'count' => $this->safeCount($table),
                ];
            });

        return view('dev-admin.index', [
            'tables' => $tables,
            'sqlRows' => session('dev_admin_sql_rows'),
            'sqlMessage' => session('dev_admin_sql_message'),
            'sqlInput' => session('dev_admin_sql_input', ''),
        ]);
    }

    public function table(Request $request, string $table): View
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        $columns = $tableMeta['columns'];
        $primaryKey = $tableMeta['primary_key'];
        $isReadOnly = $primaryKey === null;

        $query = DB::table($table);
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $this->applySearch($query, $columns, $search);
        }

        $sort = (string) $request->query('sort', $primaryKey ?? ($columns[0]['name'] ?? ''));
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = collect($columns)->pluck('name')->all();

        if (in_array($sort, $allowedSortColumns, true)) {
            $query->orderBy($sort, $dir);
        }

        $rows = $query->paginate(25)->appends($request->query());

        return view('dev-admin.table', [
            'table' => $table,
            'columns' => $columns,
            'primaryKey' => $primaryKey,
            'isReadOnly' => $isReadOnly,
            'rows' => $rows,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function create(string $table): View
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        return view('dev-admin.form', [
            'mode' => 'create',
            'table' => $table,
            'columns' => $tableMeta['columns'],
            'primaryKey' => $tableMeta['primary_key'],
            'row' => null,
        ]);
    }

    public function store(Request $request, string $table): RedirectResponse
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        $payload = $this->extractPayload($request, $tableMeta['columns'], $tableMeta['primary_key'], false);

        if (count($payload) === 0) {
            return back()->withErrors(['fields' => 'Aucune donnée à insérer.'])->withInput();
        }

        try {
            DB::table($table)->insert($payload);
        } catch (Throwable $e) {
            return back()->withErrors(['fields' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dev-admin.table', ['table' => $table])
            ->with('status', 'Ligne créée avec succès.');
    }

    public function edit(string $table, string $id): View|RedirectResponse
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        $primaryKey = $tableMeta['primary_key'];

        if ($primaryKey === null) {
            return redirect()->route('dev-admin.table', ['table' => $table])
                ->withErrors(['table' => 'Table sans clé primaire: édition indisponible.']);
        }

        $row = DB::table($table)->where($primaryKey, $id)->first();
        if (!$row) {
            return redirect()->route('dev-admin.table', ['table' => $table])
                ->withErrors(['row' => 'Ligne introuvable.']);
        }

        return view('dev-admin.form', [
            'mode' => 'edit',
            'table' => $table,
            'columns' => $tableMeta['columns'],
            'primaryKey' => $primaryKey,
            'row' => (array) $row,
        ]);
    }

    public function update(Request $request, string $table, string $id): RedirectResponse
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        $primaryKey = $tableMeta['primary_key'];

        if ($primaryKey === null) {
            return redirect()->route('dev-admin.table', ['table' => $table])
                ->withErrors(['table' => 'Table sans clé primaire: mise à jour indisponible.']);
        }

        $payload = $this->extractPayload($request, $tableMeta['columns'], $primaryKey, true);
        if (count($payload) === 0) {
            return back()->withErrors(['fields' => 'Aucune modification détectée.'])->withInput();
        }

        try {
            DB::table($table)->where($primaryKey, $id)->update($payload);
        } catch (Throwable $e) {
            return back()->withErrors(['fields' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dev-admin.table', ['table' => $table])
            ->with('status', 'Ligne mise à jour avec succès.');
    }

    public function destroy(string $table, string $id): RedirectResponse
    {
        $tableMeta = $this->getTableMetaOrFail($table);
        $primaryKey = $tableMeta['primary_key'];

        if ($primaryKey === null) {
            return redirect()->route('dev-admin.table', ['table' => $table])
                ->withErrors(['table' => 'Table sans clé primaire: suppression indisponible.']);
        }

        DB::table($table)->where($primaryKey, $id)->delete();

        return redirect()->route('dev-admin.table', ['table' => $table])
            ->with('status', 'Ligne supprimée.');
    }

    public function runSql(Request $request): RedirectResponse
    {
        $sql = trim((string) $request->input('sql', ''));
        if ($sql === '') {
            return back()->withErrors(['sql' => 'La requête SQL est vide.']);
        }

        $keyword = strtolower((string) strtok($sql, " \n\r\t("));
        $readOnlyKeywords = ['select', 'with', 'show', 'describe', 'desc', 'explain'];

        try {
            if (in_array($keyword, $readOnlyKeywords, true)) {
                $rows = DB::select($sql);
                return redirect()->route('dev-admin.index')
                    ->with('dev_admin_sql_rows', array_map(static fn($row) => (array) $row, $rows))
                    ->with('dev_admin_sql_input', $sql)
                    ->with('dev_admin_sql_message', 'Requête exécutée.');
            }

            if (in_array($keyword, ['insert', 'update', 'delete'], true)) {
                $affected = DB::affectingStatement($sql);
                return redirect()->route('dev-admin.index')
                    ->with('dev_admin_sql_input', $sql)
                    ->with('dev_admin_sql_message', "Requête exécutée. Lignes affectées: {$affected}");
            }

            DB::statement($sql);
            return redirect()->route('dev-admin.index')
                ->with('dev_admin_sql_input', $sql)
                ->with('dev_admin_sql_message', 'Instruction exécutée.');
        } catch (Throwable $e) {
            return redirect()->route('dev-admin.index')
                ->withErrors(['sql' => $e->getMessage()])
                ->with('dev_admin_sql_input', $sql);
        }
    }

    private function listTables(): Collection
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $rows = DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            return collect($rows)->map(static fn($row) => (string) $row->tablename);
        }

        if ($driver === 'mysql') {
            $rows = DB::select('SHOW TABLES');
            return collect($rows)->map(static fn($row) => (string) array_values((array) $row)[0]);
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            return collect($rows)->map(static fn($row) => (string) $row->name);
        }

        return collect();
    }

    private function safeCount(string $table): ?int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function getTableMetaOrFail(string $table): array
    {
        $allowed = $this->listTables()->all();
        if (!in_array($table, $allowed, true)) {
            abort(404, 'Table introuvable.');
        }

        $columns = collect(Schema::getColumnListing($table))
            ->map(function (string $column) use ($table): array {
                try {
                    $type = (string) Schema::getColumnType($table, $column);
                } catch (Throwable) {
                    $type = 'string';
                }

                return [
                    'name' => $column,
                    'type' => strtolower($type),
                ];
            })
            ->values()
            ->all();

        $primaryKey = $this->resolvePrimaryKey($table);

        return [
            'columns' => $columns,
            'primary_key' => $primaryKey,
        ];
    }

    private function resolvePrimaryKey(string $table): ?string
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT kcu.column_name
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu
                   ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                  AND tc.table_schema = 'public'
                  AND tc.table_name = ?
                ORDER BY kcu.ordinal_position",
                [$table]
            );

            if (count($rows) === 1) {
                return (string) $rows[0]->column_name;
            }

            return null;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
            if (count($rows) === 1) {
                return (string) $rows[0]->Column_name;
            }
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA table_info('{$table}')");
            $primary = collect($rows)->first(static fn($row) => (int) ($row->pk ?? 0) === 1);
            if ($primary) {
                return (string) $primary->name;
            }
        }

        return null;
    }

    private function applySearch(Builder $query, array $columns, string $search): void
    {
        $searchable = collect($columns)->filter(function (array $column): bool {
            $type = $column['type'];
            return str_contains($type, 'char')
                || str_contains($type, 'text')
                || str_contains($type, 'uuid')
                || str_contains($type, 'int');
        })->values();

        if ($searchable->isEmpty()) {
            return;
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query->where(function (Builder $builder) use ($searchable, $search, $operator): void {
            foreach ($searchable as $column) {
                $builder->orWhere($column['name'], $operator, '%' . $search . '%');
            }
        });
    }

    private function extractPayload(Request $request, array $columns, ?string $primaryKey, bool $isUpdate): array
    {
        $fields = (array) $request->input('fields', []);
        $payload = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $type = (string) $column['type'];

            if ($primaryKey !== null && $name === $primaryKey) {
                continue;
            }

            if (!$request->has("fields.{$name}")) {
                continue;
            }

            $raw = $fields[$name] ?? null;
            $payload[$name] = $this->castValueForColumn($raw, $type);
        }

        if ($isUpdate && array_key_exists('updated_at', $payload) && $payload['updated_at'] === null) {
            unset($payload['updated_at']);
        }

        return $payload;
    }

    private function castValueForColumn(mixed $value, string $type): mixed
    {
        if ($value === '') {
            return null;
        }

        if (str_contains($type, 'bool')) {
            if ($value === null) {
                return null;
            }

            $stringValue = strtolower((string) $value);
            if (in_array($stringValue, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($stringValue, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            return null;
        }

        if (str_contains($type, 'int')) {
            return is_numeric($value) ? (int) $value : null;
        }

        if (str_contains($type, 'json')) {
            if ($value === null) {
                return null;
            }
            json_decode((string) $value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $value;
            }
            return $value;
        }

        return $value;
    }
}
