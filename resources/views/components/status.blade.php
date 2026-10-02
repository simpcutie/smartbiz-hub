@props(['value'])
<span class="status status-{{ \Illuminate\Support\Str::slug($value) }}">{{ $value }}</span>
