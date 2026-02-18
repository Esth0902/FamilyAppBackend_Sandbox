@extends('dev-admin.layout')

@section('content')
    <div class="card">
        <div class="row" style="justify-content: space-between; margin-bottom: 10px;">
            <div>
                <h2 style="margin:0;">
                    {{ $mode === 'create' ? 'Créer' : 'Éditer' }} une ligne
                    <code>{{ $table }}</code>
                </h2>
            </div>
            <a class="btn" href="{{ route('dev-admin.table', ['table' => $table]) }}">Retour</a>
        </div>

        <form method="post"
              action="{{ $mode === 'create'
                    ? route('dev-admin.store', ['table' => $table])
                    : route('dev-admin.update', ['table' => $table, 'id' => $row[$primaryKey] ?? '']) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            @foreach($columns as $column)
                @php
                    $name = $column['name'];
                    $type = $column['type'];
                    $isPrimary = $primaryKey !== null && $name === $primaryKey;
                    $current = old("fields.$name", $row[$name] ?? '');
                    $asString = is_scalar($current) || $current === null ? (string) $current : json_encode($current);
                @endphp

                <div style="margin-bottom: 12px;">
                    <label style="display:block; margin-bottom:6px;">
                        <strong>{{ $name }}</strong>
                        <span class="muted">({{ $type }})</span>
                    </label>

                    @if($isPrimary)
                        <input type="text" value="{{ $asString }}" disabled>
                    @elseif(str_contains($type, 'bool'))
                        <select name="fields[{{ $name }}]">
                            <option value="" {{ $asString === '' ? 'selected' : '' }}>NULL</option>
                            <option value="1" {{ in_array(strtolower($asString), ['1','true'], true) ? 'selected' : '' }}>true</option>
                            <option value="0" {{ in_array(strtolower($asString), ['0','false'], true) ? 'selected' : '' }}>false</option>
                        </select>
                    @elseif(str_contains($type, 'text') || str_contains($type, 'json'))
                        <textarea name="fields[{{ $name }}]">{{ $asString }}</textarea>
                    @else
                        <input type="text" name="fields[{{ $name }}]" value="{{ $asString }}">
                    @endif
                </div>
            @endforeach

            <button class="btn btn-primary" type="submit">
                {{ $mode === 'create' ? 'Créer' : 'Mettre à jour' }}
            </button>
        </form>
    </div>
@endsection
