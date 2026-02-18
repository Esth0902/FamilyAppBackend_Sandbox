<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Admin Login</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #0f172a;
            color: #e5e7eb;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Arial, sans-serif;
        }
        .card {
            width: min(420px, 92vw);
            background: #111827;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px;
        }
        h1 { margin: 0 0 4px 0; font-size: 22px; }
        p { margin: 0 0 16px 0; color: #94a3b8; font-size: 13px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; }
        input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #334155;
            border-radius: 8px;
            background: #0b1220;
            color: #e5e7eb;
            padding: 10px;
            margin-bottom: 12px;
        }
        button {
            width: 100%;
            border: 1px solid #22d3ee;
            border-radius: 8px;
            color: #22d3ee;
            background: transparent;
            padding: 10px;
            cursor: pointer;
        }
        .error {
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #fecaca;
            background: rgba(239, 68, 68, 0.12);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Dev Admin Login</h1>
    <p>Connexion réservée au compte administrateur local.</p>

    @if ($errors->any())
        <div class="error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="post" action="{{ route('dev-admin.login.attempt') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Se connecter</button>
    </form>
</div>
</body>
</html>
