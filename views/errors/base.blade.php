@extends('layout.default')

@section('content')
{{--
	The exception detail block is shown in dev mode only (sweep finding S9). It used to be
	rendered for every visitor of every error page, unescaped: file path and line, the
	exception message, the whole stack trace, and json_encode()d system info naming the PHP
	version, the operating system and the database server. The "/" route is unauthenticated,
	so an exception raised there showed all of that to anyone who asked.

	Every value is printed with {{ }} rather than {!! !!} for the second half of the same
	finding: an exception message is the one string here that can carry request data, which
	makes an unescaped print a reflected XSS sink waiting for the first exception that
	quotes its input. The operator's copy of this information is the stderr log
	(helpers/StderrLogger.php), which is where it belongs on both deployment targets.
--}}
@if (VICTUAL_MODE === 'dev')
<div class="row">
	<div class="col">
		<div class="alert alert-dark py-1">
			<h4>{{ $__t('Error source') }}</h4>
			<pre class="my-0"><code>{{ $exception->getFile() }}:{{ $exception->getLine() }}</code></pre>
		</div>
		<div class="alert alert-dark py-1">
			<h4>{{ $__t('Error message') }}</h4>
			<pre class="my-0"><code>{{ $exception->getMessage() }}</code></pre>
		</div>
		<div class="alert alert-dark py-1">
			<h4>{{ $__t('Stack trace') }}</h4>
			<pre class="my-0"><code>{{ $exception->getTraceAsString() }}</code></pre>
		</div>
		<div class="alert alert-dark py-1">
			<h4>{{ $__t('Easy error info copy & paste (for reporting)') }}</h4>
			<textarea class="form-control easy-link-copy-textbox text-monospace mt-1"
				rows="20">
Error source:
```
{{ $exception->getFile() }}:{{ $exception->getLine() }}
```

Error message:
```
{{ $exception->getMessage() }}
```

Stack trace:
```
{{ $exception->getTraceAsString() }}
```

System info:
```
{{ json_encode($systemInfo ?? [], JSON_PRETTY_PRINT) }}
```
			</textarea>
		</div>
	</div>
</div>
@endif
@stop
