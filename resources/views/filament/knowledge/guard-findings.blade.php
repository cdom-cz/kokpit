{{--
    The findings of a refused save inside a secret-guard callout (SEC-01, SEC-02): one line per finding, built by the page
    as "<rule label> — line N". $lines holds those strings only, never the matched text. Every echo is escaped; no raw
    echo is allowed in this view. Inline styles only: the panel theme does not compile utility classes of views outside
    the Filament sources.
--}}
<ul style="margin:0;padding-left:1.25rem;list-style:disc;">
    @foreach ($lines as $line)
        <li>{{ $line }}</li>
    @endforeach
</ul>
