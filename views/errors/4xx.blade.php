@extends('errors.base')

@section('title', $__t('This request could not be handled'))

@section('content')
{{--
	The page for a 4xx that is neither "not found" nor "not allowed to view this" - a 405
	on a route whose verb changed, say. It used to render the server-error page with a 500,
	which told the caller the server had broken when in fact their request could not be
	handled as sent. Plan 15-C4.

	The exception's message is printed escaped, like everything else on these pages: it
	comes from Slim rather than from the request today, and the 500 page's history (sweep
	finding S9) is the argument for not relying on that staying true.
--}}
<div class="row">
	<div class="col text-center">
		<h1 class="alert alert-danger">{{ $__t('This request could not be handled') }}</h1>
		<div class="alert alert-info">
			<span class="text-monospace">{{ $status }}</span>
			@if(!empty($exception->getMessage()))
			&mdash; {{ $exception->getMessage() }}
			@endif
		</div>
	</div>
</div>
@parent
@stop
