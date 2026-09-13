<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Moscow Traders Wholesale') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|playfair-display:600,700" rel="stylesheet">
    <style>
        :root { --ink:#13221f; --pine:#1e4337; --cream:#f6f1e7; --sand:#e9decb; --coral:#e75c3e; --gold:#e8b743; }
        * { box-sizing:border-box; } body { margin:0; color:var(--ink); font-family:'DM Sans',sans-serif; background:var(--cream); }
        .page { min-height:100vh; overflow:hidden; position:relative; display:flex; flex-direction:column; background:radial-gradient(circle at 92% 9%,#f9d982 0 7%,transparent 7.2%),radial-gradient(circle at 5% 94%,#d7eadc 0 14%,transparent 14.2%),var(--cream); }
        .page::before { content:''; position:absolute; inset:0; pointer-events:none; opacity:.28; background-image:linear-gradient(90deg,transparent 49.8%,rgba(19,34,31,.07) 50%,transparent 50.2%),linear-gradient(rgba(19,34,31,.04) 1px,transparent 1px); background-size:100% 100%,28px 28px; }
        nav, main, footer { position:relative; z-index:1; width:100%; max-width:1180px; margin-left:auto; margin-right:auto; padding-left:28px; padding-right:28px; }
        nav { height:100px; display:flex; align-items:center; justify-content:space-between; }
        .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:var(--ink); font-weight:700; letter-spacing:-.05em; font-size:25px; }
        .mark { width:34px; height:34px; display:grid; place-items:center; color:white; background:var(--coral); border-radius:10px 10px 2px 10px; transform:rotate(-8deg); font-size:18px; }
        .brand small { color:var(--coral); font-size:11px; letter-spacing:.2em; display:block; margin-bottom:2px; }
        .nav-actions { display:flex; align-items:center; gap:18px; }.nav-link { font-size:14px; font-weight:700; color:var(--pine); text-decoration:none; border-bottom:2px solid var(--gold); padding:8px 0; }.logout { cursor:pointer; border:0; border-bottom:2px solid transparent; padding:8px 0; color:#6f5147; background:transparent; font:700 14px 'DM Sans',sans-serif; }.logout:hover { border-color:var(--coral); color:var(--coral); }
        .hero { flex:1; display:grid; grid-template-columns:1.08fr .92fr; gap:60px; align-items:center; padding-top:55px; padding-bottom:84px; }
        .eyebrow { color:var(--coral); font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:.17em; display:flex; align-items:center; gap:10px; }
        .eyebrow::before { content:''; width:36px; height:2px; background:var(--coral); }
        h1 { font-family:'Playfair Display',serif; font-size:clamp(50px,7vw,88px); line-height:.97; letter-spacing:-.065em; margin:20px 0 22px; font-weight:700; }
        h1 em { color:var(--coral); font-style:normal; }
        .lead { max-width:570px; line-height:1.7; font-size:18px; color:#52625d; margin-bottom:34px; }
        .actions { display:flex; flex-wrap:wrap; gap:14px; align-items:center; }
        .cta { display:inline-flex; gap:11px; align-items:center; padding:16px 22px; color:white; text-decoration:none; border-radius:6px 20px 20px 20px; background:var(--pine); font-weight:700; box-shadow:8px 9px 0 var(--gold); transition:transform .18s ease,box-shadow .18s ease; }
        .cta:hover { transform:translate(3px,3px); box-shadow:5px 6px 0 var(--gold); }
        .secondary { color:var(--pine); text-decoration:none; font-weight:700; padding:16px 12px; }
        .features { display:flex; gap:28px; margin-top:49px; font-size:13px; font-weight:700; color:#53645d; }
        .features span { display:flex; align-items:center; gap:8px; } .features i { width:9px; height:9px; display:block; background:var(--gold); border-radius:50%; }
        .terminal { background:var(--pine); padding:18px; border-radius:36px 6px 36px 6px; box-shadow:15px 16px 0 var(--sand); transform:rotate(2deg); }
        .screen { padding:25px; border-radius:22px 4px 22px 4px; background:#fdfaf2; min-height:480px; transform:rotate(-2deg); }
        .screen-top { display:flex; justify-content:space-between; color:#6e766f; font-size:12px; font-weight:700; margin-bottom:25px; }
        .store { color:var(--pine); font-family:'Playfair Display',serif; font-size:28px; margin:5px 0 24px; }
        .sale-line { display:flex; justify-content:space-between; border-bottom:1px solid #eae4d7; padding:13px 0; font-size:14px; } .sale-line small { display:block; color:#849088; margin-top:4px; }
        .total { display:flex; justify-content:space-between; align-items:end; margin-top:28px; color:var(--pine); font-weight:700; }.total strong { font-size:31px; font-family:'Playfair Display',serif; }
        .pay { margin-top:23px; width:100%; padding:14px; border:0; background:var(--coral); color:white; border-radius:5px 15px 15px 15px; font-family:inherit; font-weight:700; }
        footer { display:flex; justify-content:space-between; padding-top:22px; padding-bottom:30px; border-top:1px solid rgba(19,34,31,.12); color:#748079; font-size:12px; }
        @media (max-width:800px) { nav { height:82px; } .hero { grid-template-columns:1fr; gap:45px; padding-top:35px; } .terminal { max-width:480px; margin:auto; } .features { gap:14px; flex-wrap:wrap; } footer { gap:14px; flex-direction:column; } }
        @media (max-width:460px) { nav,main,footer { padding-left:20px; padding-right:20px; }.brand { font-size:21px; }.secondary { display:none; }.screen { min-height:430px; padding:20px; } }
    </style>
</head>
<body>
<div class="page">
    <nav>
        <a class="brand" href="/"><span class="mark">MT</span><span><small>WHOLESALE MADE SIMPLE</small>Moscow Traders Wholesale</span></a>
        @auth
            <div class="nav-actions">
                <a class="nav-link" href="{{ route('pos.index') }}">Open workspace</a>
                <form method="POST" action="{{ route('logout') }}">@csrf <button class="logout" type="submit">Sign out</button></form>
            </div>
        @else <a class="nav-link" href="{{ route('login') }}">Staff sign in</a> @endauth
    </nav>
    <main class="hero">
        <section>
            <div class="eyebrow">Built for the counter</div>
            <h1>One calm system<br>for every <em>sale.</em></h1>
            <p class="lead">Moscow Traders Wholesale keeps your business moving with fast checkout, accurate stock, and store-level pricing wherever your customers find you.</p>
            <div class="actions">
                <a class="cta" href="{{ auth()->check() ? route('pos.index') : route('login') }}">{{ auth()->check() ? 'Open POS Screen' : 'Sign in to POS' }} <span aria-hidden="true">→</span></a>
                <a class="secondary" href="{{ auth()->check() ? '/admin' : route('login') }}">{{ auth()->check() ? 'Manage back office' : 'Staff access only' }}</a>
            </div>
            <div class="features"><span><i></i>Fast barcode checkout</span><span><i></i>USD &amp; MVR stores</span><span><i></i>Live inventory</span></div>
        </section>
        <aside class="terminal" aria-label="Moscow Traders Wholesale checkout preview">
            <div class="screen">
                <div class="screen-top"><span>Moscow Traders Wholesale</span><span>Main Branch · MVR</span></div>
                <div class="store">Today's counter</div>
                <div class="sale-line"><span>Egg Case <small>SKU · EGG-CASE</small></span><strong>MVR 480.00</strong></div>
                <div class="sale-line"><span>Water 1.5L Case <small>SKU · WATER-1500-CASE</small></span><strong>MVR 67.00</strong></div>
                <div class="sale-line"><span>Sunquick Case <small>SKU · SUNQUICK-CASE</small></span><strong>MVR 485.00</strong></div>
                <div class="total"><span>3 items</span><strong>MVR 1,032.00</strong></div>
                <button class="pay" type="button">Ready to take payment</button>
            </div>
        </aside>
    </main>
    <footer><span>MOSCOW TRADERS WHOLESALE</span><span>Every store. One clear view.</span></footer>
</div>
</body>
</html>
