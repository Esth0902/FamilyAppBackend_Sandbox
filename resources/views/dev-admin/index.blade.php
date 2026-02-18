@extends('dev-admin.layout')

@section('content')
    <div class="card">
        <h2 style="margin-top:0;">Tables</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Table</th>
                    <th>Rows</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($tables as $table)
                    <tr>
                        <td><code>{{ $table['name'] }}</code></td>
                        <td>{{ $table['count'] ?? 'n/a' }}</td>
                        <td>
                            <a class="btn btn-primary" href="{{ route('dev-admin.table', ['table' => $table['name']]) }}">Ouvrir</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Aucune table detectee.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">SQL Console (dev)</h2>
        <p class="muted">Execute une requete SQL brute. Utilise avec prudence.</p>
        <form method="post" action="{{ route('dev-admin.sql') }}">
            @csrf
            <textarea name="sql" placeholder="SELECT * FROM users LIMIT 20;">{{ old('sql', $sqlInput ?? '') }}</textarea>
            <div style="margin-top:10px;">
                <button class="btn btn-primary" type="submit">Executer</button>
            </div>
        </form>

        @if(!empty($sqlMessage))
            <div style="margin-top:12px;" class="flash-ok">{{ $sqlMessage }}</div>
        @endif

        @if(is_array($sqlRows))
            <div style="margin-top:12px;">
                <h3 style="margin:0 0 8px 0;">Resultats</h3>
                @if(count($sqlRows) === 0)
                    <p class="muted">Aucune ligne retournee.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                @foreach(array_keys($sqlRows[0]) as $header)
                                    <th>{{ $header }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($sqlRows as $row)
                                <tr>
                                    @foreach($row as $value)
                                        <td>{{ is_scalar($value) || $value === null ? (string) $value : json_encode($value) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection
