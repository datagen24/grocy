{{-- The filter row of a master data list page: the search box, the optional "show
     disabled" checkbox and the clear-filter button.

     The three ids here - #search, #show-disabled and #clear-filter-button - are the
     contract Victual.EntityList binds to (Victual.EntityList.SearchFilter and
     .ShowDisabledToggle look them up by id), which is the reason this markup is worth
     having in one place: a list page that misspells one of them silently loses its
     filtering. #table-filter-row is the collapse target of the filter toggle rendered by
     components.list_collapse_toggles.

     Parameters:
       showDisabled            render the "show disabled" checkbox column (default true)
       searchGroupCssClasses   extra classes for the search input group (default none)

     Plan 12 step 6. --}}
@php if(!isset($showDisabled)) { $showDisabled = true; } @endphp
@php if(empty($searchGroupCssClasses)) { $searchGroupCssClasses = ''; } @endphp

<div class="row collapse d-md-flex"
	id="table-filter-row">
	<div class="col-12 col-md-6 col-xl-3">
		<div class="input-group{{ empty($searchGroupCssClasses) ? '' : ' ' . $searchGroupCssClasses }}">
			<div class="input-group-prepend">
				<span class="input-group-text"><i class="fa-solid fa-search"></i></span>
			</div>
			<input type="text"
				id="search"
				class="form-control"
				placeholder="{{ $__t('Search') }}">
		</div>
	</div>
	@if($showDisabled)
	<div class="col-12 col-md-6 col-xl-3">
		<div class="form-check custom-control custom-checkbox">
			<input class="form-check-input custom-control-input"
				type="checkbox"
				id="show-disabled">
			<label class="form-check-label custom-control-label"
				for="show-disabled">
				{{ $__t('Show disabled') }}
			</label>
		</div>
	</div>
	@endif
	<div class="col">
		<div class="float-right">
			<button id="clear-filter-button"
				class="btn btn-sm btn-outline-info"
				data-toggle="tooltip"
				title="{{ $__t('Clear filter') }}">
				<i class="fa-solid fa-filter-circle-xmark"></i>
			</button>
		</div>
	</div>
</div>
