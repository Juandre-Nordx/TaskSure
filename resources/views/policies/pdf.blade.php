<div data-policy-document style="margin:20px 0">
    <p class="field-help">PDF document · {{ $pdfName }} · {{ number_format($pdfSize / 1024) }} KB</p>
    <div class="actions" style="margin-top:12px">
        <button class="btn primary" type="button" data-policy-read aria-expanded="false">Read policy</button>
        <a class="btn" href="{{ $pdfUrl }}?download=1">Download PDF</a>
    </div>
    <div data-policy-viewer hidden style="margin-top:16px">
        <iframe data-pdf-src="{{ $pdfUrl }}" title="{{ $pdfTitle ?? 'Company policy PDF' }}" style="width:100%;height:65vh;border:1px solid var(--line);border-radius:9px"></iframe>
        <p class="field-help">If your browser cannot display the PDF, use Download PDF and open it in your PDF reader.</p>
    </div>
    <noscript><p><a href="{{ $pdfUrl }}" target="_blank" rel="noopener">Open the policy PDF</a></p></noscript>
    <p class="field-help">Reading or downloading this document does not record an acknowledgement.</p>
</div>
