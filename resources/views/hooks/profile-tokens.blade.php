@if (\Guava\FilamentMcp\Livewire\ManageMcpTokens::canAccessOnProfilePage())
    <x-filament::section
        :heading="__('filament-mcp::tokens.section.heading')"
        :description="__('filament-mcp::tokens.section.description')"
        class="fi-mcp-tokens-section"
    >
        @livewire(\Guava\FilamentMcp\Livewire\ManageMcpTokens::class)
    </x-filament::section>
@endif
