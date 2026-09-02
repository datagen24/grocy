@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Task categories'))

@section('content')
<div class="row">
	<div class="col">
		<div class="title-related-links">
			<h2 class="title">@yield('title')</h2>
			@include('components.list_collapse_toggles')
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				<a class="btn btn-primary responsive-button m-1 mt-md-0 mb-md-0 float-right show-as-dialog-link"
					href="{{ $U('/taskcategory/new?embedded') }}">
					{{ $__t('Add') }}
				</a>
				<a class="btn btn-outline-secondary"
					href="{{ $U('/userfields?entity=task_categories') }}">
					{{ $__t('Configure userfields') }}
				</a>
			</div>
		</div>
	</div>
</div>

<hr class="my-2">

@include('components.list_filter_row')

<div class="row">
	<div class="col">
		<table id="taskcategories-table"
			class="table table-sm table-striped nowrap w-100">
			<thead>
				<tr>
					<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
							data-toggle="tooltip"
							title="{{ $__t('Table options') }}"
							data-table-selector="#taskcategories-table"
							href="#"><i class="fa-solid fa-eye"></i></a>
					</th>
					<th>{{ $__t('Name') }}</th>
					<th>{{ $__t('Description') }}</th>

					@include('components.userfields_thead', array(
					'userfields' => $userfields
					))

				</tr>
			</thead>
			<tbody class="d-none">
				@foreach($taskCategories as $taskCategory)
				<tr class="@if($taskCategory->active == 0) text-muted @endif">
					<td class="fit-content border-right">
						<a class="btn btn-info btn-sm show-as-dialog-link"
							href="{{ $U('/taskcategory/') }}{{ $taskCategory->id }}?embedded"
							data-toggle="tooltip"
							title="{{ $__t('Edit this item') }}">
							<i class="fa-solid fa-edit"></i>
						</a>
						<a class="btn btn-danger btn-sm task-category-delete-button"
							href="#"
							data-category-id="{{ $taskCategory->id }}"
							data-category-name="{{ $taskCategory->name }}"
							data-toggle="tooltip"
							title="{{ $__t('Delete this item') }}">
							<i class="fa-solid fa-trash"></i>
						</a>
					</td>
					<td>
						{{ $taskCategory->name }}
					</td>
					<td>
						{{ $taskCategory->description }}
					</td>

					@include('components.userfields_tbody', array(
					'userfields' => $userfields,
					'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValues, 'object_id', $taskCategory->id)
					))

				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
@stop
