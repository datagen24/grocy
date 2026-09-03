{{-- The two narrow-screen collapse toggles every master data list page carries in its
     header: one for #table-filter-row, one for #related-links. Both targets are ids the
     rest of the page owns - this partial only renders the buttons that toggle them.
     Plan 12 step 6. --}}
<div class="float-right @if($embedded) pr-5 @endif">
	<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
		type="button"
		data-toggle="collapse"
		data-target="#table-filter-row">
		<i class="fa-solid fa-filter"></i>
	</button>
	<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
		type="button"
		data-toggle="collapse"
		data-target="#related-links">
		<i class="fa-solid fa-ellipsis-v"></i>
	</button>
</div>
