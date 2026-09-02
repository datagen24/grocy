@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Userentities'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			@include('components.list_collapse_toggles')
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100 m-1 mt-md-0 mb-md-0 float-right"
				id="related-links">
				<a class="btn btn-primary responsive-button show-as-dialog-link"
					href="{{ $U('/userentity/new?embedded') }}">
					{{ $__t('Add') }}
				</a>
			</div>
		</div>
	</div>
</div>

<hr class="my-2">

@include('components.list_filter_row', array(
	'showDisabled' => false
))

<div class="row">
	<div class="col">
		<table id="userentities-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#userentities-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Caption') }}</th>
				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($userentities as $userentity)
				<tr>
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm show-as-dialog-link"
							href="{{ $U('/userentity/') }}{{ $userentity->id }}?embedded"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<a class="btn btn-danger btn-sm userentity-delete-button"
							href="#"
							data-userentity-id="{{ $userentity->id }}"
							data-userentity-name="{{ $userentity->name }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
						<a class="btn btn-secondary btn-sm"
							href="{{ $U('/userfields?entity=userentity-') }}{{ $userentity->name }}">
							{{ $__t('Configure fields') }}
						</a>
					</td>
					<td>
						{{ $userentity->name }}
					</td>
					<td>
						{{ $userentity->caption }}
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
