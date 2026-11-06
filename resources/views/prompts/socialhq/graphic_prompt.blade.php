{!! $prompt !!}

A finished still graphic for a social media post, {{ $format }} format.
@if ($reference_count > 0)
Use the attached reference {{ $reference_count > 1 ? 'images' : 'image' }} as the prompt describes.
@endif
@if ($brand_logo)
The first attached image is the brand's logo: place it as it is, never redraw, recolour or distort it.
@endif
Any text in the image must be spelled exactly as written in the prompt.
@if (filled($brand_look))

Follow the brand's look:
{!! $brand_look !!}
@endif
