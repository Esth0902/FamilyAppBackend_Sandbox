@extends('dev-admin.layout')

@section('content')
    <div class="card">
        <div class="row" style="justify-content: space-between; margin-bottom: 10px;">
            <div>
                <h2 style="margin:0;">Table <code>{{ $table }}</code></h2>
                <p class="muted" style="margin:4px 0 0 0;">
                    Cle primaire: {{ $primaryKey ? $primaryKey : 'aucune (lecture seule pour update/delete)' }}
                </p>
            </div>
            <div class="row">
                <a class="btn" href="{{ route('dev-admin.index') }}">Retour</a>
                <a class="btn btn-primary" href="{{ route('dev-admin.create', ['table' => $table]) }}">Nouvelle ligne</a>
            </div>
        </div>

        <form method="get" class="search-row" style="margin-bottom: 12px;">
            <input type="text" name="q" value="{{ $search }}" placeholder="Recherche globale...">
            <button class="btn" type="submit">Rechercher</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    @foreach($columns as $column)
                        @php
                            $isCurrentSort = $sort === $column['name'];
                            $nextDir = $isCurrentSort && $dir === 'asc' ? 'desc' : 'asc';
                        @endphp
                        <th>
                            <a href="{{ route('dev-admin.table', ['table' => $table, 'q' => $search, 'sort' => $column['name'], 'dir' => $nextDir]) }}"
                               style="color:inherit; text-decoration:none;">
                                {{ $column['name'] }}
                                @if($isCurrentSort)
                                    {{ $dir === 'asc' ? '?' : '?' }}
                                @endif
                            </a>
                        </th>
                    @endforeach
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    @php
                        $rowArray = (array) $row;
                        $pkValue = $primaryKey ? ($rowArray[$primaryKey] ?? null) : null;
                    @endphp
                    <tr>
                        @foreach($columns as $column)
                            @php
                                $value = $rowArray[$column['name']] ?? null;
                                $display = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
                            @endphp
                            <td>{{ \Illuminate\Support\Str::limit($display, 120) }}</td>
                        @endforeach
                        <td>
                            @if($primaryKey !== null && $pkValue !== null)
                                <div class="row">
                                    <a class="btn" href="{{ route('dev-admin.edit', ['table' => $table, 'id' => $pkValue]) }}">Edit</a>
                                    <form method="post" action="{{ route('dev-admin.destroy', ['table' => $table, 'id' => $pkValue]) }}"
                                          onsubmit="return confirm('Supprimer cette ligne ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}">Aucune ligne.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <a class="pagination-item {{ $rows->onFirstPage() ? 'disabled' : '' }}" href="{{ $rows->onFirstPage() ? '#' : $rows->previousPageUrl() }}">Precedent</a>
            <a class="pagination-item {{ $rows->hasMorePages() ? '' : 'disabled' }}" href="{{ $rows->hasMorePages() ? $rows->nextPageUrl() : '#' }}">Suivant</a>
            <span class="pagination-meta">Page {{ $rows->currentPage() }} / {{ $rows->lastPage() }} ({{ $rows->total() }} lignes)</span>
        </div>
    </div>
@endsection
