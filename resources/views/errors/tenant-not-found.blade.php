<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agency Not Found — {{ config('app.name') }}</title>
    <link rel="icon" href="/img/logo-light.svg" type="image/svg+xml">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: #f9fafb;
            color: #111827;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 3rem 2.5rem;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
        }
        .icon {
            width: 56px;
            height: 56px;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .icon svg { width: 28px; height: 28px; color: #ef4444; }
        h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: .5rem; }
        p { font-size: .9375rem; color: #6b7280; line-height: 1.6; margin-bottom: 2rem; }
        .path {
            display: inline-block;
            background: #f3f4f6;
            border-radius: 6px;
            padding: .25rem .625rem;
            font-family: ui-monospace, monospace;
            font-size: .8125rem;
            color: #374151;
            margin-bottom: 2rem;
            word-break: break-all;
        }
        a {
            display: inline-block;
            background: #111827;
            color: #fff;
            text-decoration: none;
            padding: .625rem 1.5rem;
            border-radius: 8px;
            font-size: .9375rem;
            font-weight: 500;
            transition: background .15s;
        }
        a:hover { background: #374151; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
        </div>
        <h1>Agency Not Found</h1>
        <p>The agency you are trying to access does not exist or may have been removed.</p>
        <div class="path">{{ request()->path() }}</div>
        <br>
        <a href="{{ url('/') }}">Go to Homepage</a>
    </div>
</body>
</html>
