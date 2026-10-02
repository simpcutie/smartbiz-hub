@props(['name'=>'grid','size'=>20])
@php
$paths=match($name){
'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
'glasses'=>'<circle cx="6" cy="14" r="4"/><circle cx="18" cy="14" r="4"/><path d="M10 13c1-2 3-2 4 0M2 13l2-7h3m15 7-2-7h-3"/>',
'building'=>'<path d="M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5M8 7h1m6 0h1M8 11h1m6 0h1"/>',
'purchase'=>'<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM8 11h8M8 15h5M8 18h3"/>',
'box'=>'<path d="m12 2 9 5v10l-9 5-9-5V7l9-5ZM3 7l9 5 9-5M12 12v10M7.5 4.5l9 5v4"/>',
'bag'=>'<path d="M4 8h16l1 13H3L4 8ZM8 8V6a4 4 0 0 1 8 0v2"/>',
'bill'=>'<path d="M5 3h14v19l-3-2-4 2-4-2-3 2V3ZM8 7h8M8 11h8M8 15h4"/>',
'wallet'=>'<rect x="3" y="6" width="18" height="15" rx="2"/><path d="M18 6V3H5a2 2 0 0 0-2 3m18 5h-6v5h6m-3-2.5h.01"/>',
'chart'=>'<path d="M3 3v18h18M7 17v-5m5 5V6m5 11V9"/>',
'shield'=>'<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4ZM8 12l3 3 5-6"/>',
'settings'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="9" cy="18" r="2"/>',
'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
'plus'=>'<path d="M12 5v14M5 12h14"/>',
'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
'check'=>'<path d="m5 12 4 4L19 6"/>',
'location'=>'<path d="M19 10c0 5-7 12-7 12S5 15 5 10a7 7 0 0 1 14 0Z"/><circle cx="12" cy="10" r="2"/>',
'delivery'=>'<path d="M3 5h11v12H3V5Zm11 5h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
default=>'<circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/>',
};
@endphp
<svg {{ $attributes->class(['icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths !!}</svg>