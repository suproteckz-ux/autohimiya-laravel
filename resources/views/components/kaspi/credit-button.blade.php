@props(['product'])

@if($product->canShowKaspiCreditButton())
    @php($merchantSku = $product->sku)

    @once
        @push('scripts')
            <script>
                (function(d, s, id) {
                    var js, kjs;
                    if (d.getElementById(id)) return;
                    js = d.createElement(s); js.id = id;
                    js.src = '{{ config('services.kaspi.widget_script_url') }}';
                    kjs = document.getElementsByTagName(s)[0]
                    kjs.parentNode.insertBefore(js, kjs);
                }(document, 'script', 'KS-Widget'));
            </script>
        @endpush
    @endonce

    <div class="kaspi-credit-widget">
        <div
            class="ks-widget"
            data-template="{{ config('services.kaspi.button_template', 'button') }}"
            data-merchant-sku="{{ $merchantSku }}"
            data-merchant-code="{{ config('services.kaspi.merchant_code') }}"
            data-city="{{ config('services.kaspi.city_code') }}"
            data-style="desktop"
        ></div>
    </div>
@endif
