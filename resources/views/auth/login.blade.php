<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign In | {{ config('app.name', 'Moscow Traders Wholesale') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(251,146,60,0.16),_transparent_24rem),radial-gradient(circle_at_bottom_right,_rgba(34,197,94,0.14),_transparent_26rem),linear-gradient(160deg,_#020617_0%,_#0f172a_45%,_#111827_100%)]"></div>

            <div class="relative grid w-full max-w-5xl overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/80 shadow-2xl shadow-black/30 backdrop-blur md:grid-cols-[1.1fr_0.9fr]">
                <section class="flex flex-col justify-between gap-10 p-8 md:p-12">
                    <div class="space-y-6">
                        <div class="inline-flex items-center rounded-full border border-amber-300/30 bg-amber-300/10 px-4 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-amber-200">
                            Moscow Traders Wholesale Checkout
                        </div>
                        <div class="space-y-4">
                            <h1 class="max-w-xl font-[var(--font-display)] text-4xl font-semibold tracking-tight text-white md:text-5xl">
                                Fast retail checkout for your counter team.
                            </h1>
                            <p class="max-w-xl text-base leading-7 text-slate-300 md:text-lg">
                                Sign in with your authorized account to access the checkout terminal and back-office tools.
                            </p>
                        </div>
                    </div>

                </section>

                <section class="border-t border-white/10 bg-slate-950/55 p-8 md:border-t-0 md:border-l md:p-12">
                    <div class="mx-auto flex h-full w-full max-w-md flex-col justify-center">
                        <h2 class="text-2xl font-semibold text-white">Sign in</h2>
                        <p class="mt-2 text-sm text-slate-400">Use your company credentials to access the checkout terminal.</p>

                        @if ($errors->any())
                            <div class="mt-6 rounded-2xl border border-rose-400/25 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                                {{ $errors->first() }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('login.attempt') }}" class="mt-8 space-y-5">
                            @csrf

                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Email</span>
                                <input
                                    type="email"
                                    name="email"
                                    value="{{ old('email') }}"
                                    required
                                    autofocus
                                    autocomplete="username"
                                    class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition focus:border-amber-300/80 focus:ring-4 focus:ring-amber-300/15"
                                >
                            </label>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Password</span>
                                <input
                                    type="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition focus:border-amber-300/80 focus:ring-4 focus:ring-amber-300/15"
                                >
                            </label>

                            <label class="inline-flex items-center gap-3 text-sm text-slate-300">
                                <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-white/20 bg-white/5 text-amber-400 focus:ring-amber-300/50">
                                <span>Keep me signed in</span>
                            </label>

                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-amber-400 px-4 py-3 text-base font-semibold text-slate-950 transition hover:bg-amber-300">
                                Open POS
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </main>
    </body>
</html>
