{{--
    What an expired session looks like when somebody presses a button.

    Laravel's own 419 is the word "Page Expired" on a blank page, which reads as
    a fault in the thing you just clicked — an operator pressing Delete sees it
    and reports that Delete is broken. It usually means only that the session
    behind the open page is gone: the app restarted, or the page sat open past
    the session lifetime. Nothing was changed, and signing in again fixes it.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Session expired</title>
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0d1416;
            color: #f5f7f7;
        }
        main { max-width: 430px; text-align: center; }
        h1 { margin: 0 0 12px; font-size: 22px; letter-spacing: -.01em; }
        p { margin: 0 0 10px; color: #9da9ac; font-size: 14px; line-height: 1.55; }
        a {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 20px;
            border-radius: 9px;
            background: #24e435;
            color: #07240b;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        <h1>Your session expired</h1>
        <p>The page you had open was signed in under a session that has since ended — usually because the app restarted, or the page sat open for a while.</p>
        <p><strong>Nothing was changed.</strong> Sign in again and repeat what you were doing.</p>
        <a href="{{ url('/login') }}">Sign in again</a>
    </main>
</body>
</html>
