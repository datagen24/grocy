@php require_frontend_packages(['tempusdominus']); @endphp

@once
@push('componentScripts')
<script src="{{ $U('/viewjs/components/datetimepicker.js', true) }}?v={{ $version }}"></script>
@endpush
@endonce

{{-- 'instance' names an independently-scoped picker so that two of them can share a page
     (the meal plan's date range, the stock entry form's due/purchased pair). It is appended
     to every per-instance id and class here, and to the matching selectors in
     datetimepicker.js. '' is the primary picker; 'secondary' is the second one. This
     replaced a second copy of this component that differed only by a "2" appended to the
     same names - plan 12 step 5. --}}
@php if(!isset($instance)) { $instance = ''; } @endphp
@php $instanceSuffix = empty($instance) ? '' : '-' . $instance; @endphp

@php if(!isset($isRequired)) { $isRequired = true; } @endphp
@php if(!isset($initialValue)) { $initialValue = ''; } @endphp
@php if(empty($earlierThanInfoLimit)) { $earlierThanInfoLimit = ''; } @endphp
@php if(empty($earlierThanInfoText)) { $earlierThanInfoText = ''; } @endphp
@php if(empty($additionalCssClasses)) { $additionalCssClasses = ''; } @endphp
@php if(empty($additionalGroupCssClasses)) { $additionalGroupCssClasses = ''; } @endphp
@php if(empty($invalidFeedback)) { $invalidFeedback = ''; } @endphp
@php if(!isset($isRequired)) { $isRequired = true; } @endphp
@php if(!isset($noNameAttribute)) { $noNameAttribute = false; } @endphp
@php if(!isset($nextInputSelector)) { $nextInputSelector = false; } @endphp
@php if(empty($additionalAttributes)) { $additionalAttributes = ''; } @endphp
@php if(empty($additionalGroupCssClasses)) { $additionalGroupCssClasses = ''; } @endphp
@php if(empty($activateNumberPad)) { $activateNumberPad = false; } @endphp

<div class="datetimepicker{{ $instanceSuffix }}-wrapper form-group {{ $additionalGroupCssClasses }}">
	<label for="{{ $id }}">{{ $__t($label) }}
		@if(!empty($hint))
		&nbsp;<i class="fa-solid fa-question-circle text-muted"
			data-toggle="tooltip"
			data-trigger="hover click"
			title="{{ $hint }}"></i>
		@endif
		<span class="small text-muted">
			<time id="datetimepicker{{ $instanceSuffix }}-timeago"
				class="timeago timeago-contextual"></time>
		</span>
	</label>
	<div class="input-group">
		<div class="input-group date datetimepicker{{ $instanceSuffix }} @if(!empty($additionalGroupCssClasses)){{ $additionalGroupCssClasses }}@endif"
			id="{{ $id }}"
			@if(!$noNameAttribute)
			name="{{ $id }}"
			@endif
			data-target-input="nearest">
			<input {!!
				$additionalAttributes
				!!}
				type="text"
				@if($activateNumberPad)
				inputmode="numeric"
				@endif
				@if($isRequired)
				required
				@endif
				class="form-control datetimepicker{{ $instanceSuffix }}-input @if(!empty($additionalCssClasses)){{ $additionalCssClasses }}@endif"
				data-target="#{{ $id }}"
				data-format="{{ $format }}"
				data-init-with-now="{{ BoolToString($initWithNow) }}"
				data-init-value="{{ $initialValue }}"
				data-limit-end-to-now="{{ BoolToString($limitEndToNow) }}"
				data-limit-start-to-now="{{ BoolToString($limitStartToNow) }}"
				data-next-input-selector="{{ $nextInputSelector }}"
				data-earlier-than-limit="{{ $earlierThanInfoLimit }}" />
			<div class="input-group-append"
				data-target="#{{ $id }}"
				data-toggle="datetimepicker">
				<div class="input-group-text"><i class="fa-solid fa-calendar"></i></div>
			</div>
			<div class="invalid-feedback">{{ $invalidFeedback }}</div>
		</div>
		<div id="datetimepicker{{ $instanceSuffix }}-earlier-than-info"
			class="form-text text-info font-italic w-100 d-none">{{ $earlierThanInfoText }}</div>
		@if(isset($shortcutValue) && isset($shortcutLabel))
		<div class="form-group mt-n2 mb-0">
			<div class="custom-control custom-checkbox">
				<input class="form-check-input custom-control-input"
					type="checkbox"
					id="datetimepicker{{ $instanceSuffix }}-shortcut"
					name="datetimepicker{{ $instanceSuffix }}-shortcut"
					value="1"
					data-datetimepicker-shortcut-value="{{ $shortcutValue }}"
					tabindex="-1">
				<label class="form-check-label custom-control-label"
					for="datetimepicker{{ $instanceSuffix }}-shortcut">{{ $__t($shortcutLabel) }}
				</label>
			</div>
		</div>
		@endif
	</div>
</div>
