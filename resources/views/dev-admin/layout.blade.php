<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FamilyApp Dev Admin</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --muted: #94a3b8;
            --text: #e5e7eb;
            --line: #334155;
            --accent: #22d3ee;
            --danger: #ef4444;
            --ok: #22c55e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .title { font-size: 22px; font-weight: 700; margin: 0; }
        .subtitle { color: var(--muted); margin: 2px 0 0 0; font-size: 13px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 12px;
            color: var(--text);
            text-decoration: none;
            background: transparent;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }
        .btn:hover { border-color: var(--accent); }
        .btn-primary { border-color: var(--accent); color: var(--accent); }
        .btn-danger { border-color: var(--danger); color: var(--danger); }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 14px;
        }
        .flash-ok {
            border: 1px solid rgba(34, 197, 94, 0.5);
            color: #bbf7d0;
            background: rgba(34, 197, 94, 0.1);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 12px;
        }
        .flash-error {
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #fecaca;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 760px;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 600; }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        .table-wrap table { border: 0; }
        .table-wrap th, .table-wrap td { border-left: 0; border-right: 0; }
        input, textarea, select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #0b1220;
            color: var(--text);
            padding: 8px 10px;
            font-size: 13px;
        }
        textarea { min-height: 120px; resize: vertical; }
        code {
            display: inline-block;
            background: #0b1220;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 2px 6px;
            color: #cbd5e1;
            font-size: 12px;
        }
        .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .row form { margin: 0; }
        .search-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-row input { flex: 1; min-width: 220px; }
        .muted { color: var(--muted); font-size: 12px; }
        .pagination {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .pagination-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 10px;
            text-decoration: none;
            color: var(--text);
            font-size: 13px;
        }
        .pagination-item:hover { border-color: var(--accent); }
        .pagination-item.disabled {
            opacity: 0.45;
            pointer-events: none;
        }
        .pagination-meta {
            color: var(--muted);
            font-size: 12px;
            margin-left: 4px;
        }
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .title { font-size: 20px; }
            .search-row input { min-width: 0; }
            table { min-width: 640px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <div>
            <h1 class="title">FamilyApp Dev Admin</h1>
            <p class="subtitle">Accès développeur local uniquement</p>
        </div>
        <div class="row">
            <a class="btn" href="{{ route('dev-admin.index') }}">Dashboard</a>
            <form method="post" action="{{ route('dev-admin.logout') }}">
                @csrf
                <button type="submit" class="btn">Logout</button>
            </form>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-ok">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="flash-error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
