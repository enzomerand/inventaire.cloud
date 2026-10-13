{{--
    Translated strings for scripts, read by resources/js/lib/i18n.js.

    The HEX flags escape every character that could close the <script> element
    or break out of it, which keeps translations safe to embed verbatim.
--}}
<script type="application/json" id="client-messages">@json($messages(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>
