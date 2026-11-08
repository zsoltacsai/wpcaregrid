{{--
    Status pill for the customer area. Pass a status object with tone() and label()
    (for example a WebsiteStatus), or a tone with slot text. The label is always
    visible text, never colour alone; `data-tone` and `.m16-chip` carry the styling.
--}}
@props(['status' => null, 'tone' => null])

<x-ui.status-badge :status="$status" :tone="$tone" {{ $attributes->merge(['class' => 'm16-chip']) }}>{{ $slot }}</x-ui.status-badge>
