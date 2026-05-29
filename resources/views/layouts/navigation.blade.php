<nav class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="{{ route('dashboard') }}" class="text-lg font-semibold text-gray-800">
                    {{ config('app.name', 'GOAITracker') }}
                </a>
            </div>

            <div class="flex items-center space-x-4">
                <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="text-sm text-gray-700 hover:text-gray-900 underline">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
