// Powers the products master data list view (views/products.blade.php):
// DataTable of all products with search, product group/status filters, delete confirmation and a product merge dialog.

// DataTables setup for the products list (column 0 = row menu; hidden columns 7-9 hold extra searchable/sortable data from the template)
var productsTable = $('#products-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 7 },
		{ 'visible': false, 'targets': 8 },
		{ 'visible': false, 'targets': 9 },
		{ "type": "html-num-fmt", "targets": 3 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#products-table tbody').removeClass("d-none");
productsTable.columns.adjust().draw();

// Debounced full-text search over the table
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	productsTable.search(value).draw();
}, Victual.FormFocusDelay));

// Product group filter: exact-match regex search on the (possibly reordered) product group column
$("#product-group-filter").on("change", function ()
{
	var value = $("#product-group-filter option:selected").text();
	if (value === __t("All"))
	{
		productsTable.column(productsTable.colReorder.transpose(6)).search("").draw();
	}
	else
	{
		productsTable.column(productsTable.colReorder.transpose(6)).search("^" + $.fn.dataTable.util.escapeRegex(value) + "$", true, false).draw();
	}

});

// Reset search, group/status filters and the "show disabled" toggle
$("#clear-filter-button").on("click", function ()
{
	$("#search").val("");
	$("#product-group-filter").val("all");
	productsTable.column(productsTable.colReorder.transpose(6)).search("").draw();
	productsTable.search("").draw();

	if ($("#show-disabled").is(":checked"))
	{
		$("#show-disabled").prop("checked", false);
		RemoveUriParam("include_disabled");
		RemoveUriParam("only_in_stock");
		window.location.reload();
	}

	if ($("#status-filter").val() != "all")
	{
		$("#status-filter").val("all");
		$("#status-filter").trigger("change");
	}
});

if (typeof GetUriParam("product-group") !== "undefined")
{
	$("#product-group-filter").val(GetUriParam("product-group"));
	$("#product-group-filter").trigger("change");
}

// Delete button per row (expects data-product-name / data-product-id from the template);
// confirms via bootbox, then DELETEs objects/products/{id}
$(document).on('click', '.product-delete-button', function (e)
{
	var objectName = $(e.currentTarget).attr('data-product-name');
	var objectId = $(e.currentTarget).attr('data-product-id');

	bootbox.confirm({
		message: __t('Are you sure you want to delete product "%s"?', objectName) + '<br><br>' + __t('This also removes any stock amount, the journal and all other references of this product - consider disabling it instead, if you want to keep that and just hide the product.'),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function (result)
		{
			if (result === true)
			{
				jsonData = {};
				jsonData.active = 0;
				Victual.Api.Delete('objects/products/' + objectId, {},
					function (result)
					{
						window.location.href = U('/products');
					},
					function (xhr)
					{
						console.error(xhr);
					}
				);
			}
		}
	});
});

// "Show disabled" toggle and status filter both reload the page with updated URI parameters (filtering happens server-side)
$("#show-disabled").change(function ()
{
	if (this.checked)
	{
		UpdateUriParam("include_disabled", "true");
	}
	else
	{
		RemoveUriParam("include_disabled");
	}

	window.location.reload();
});

$("#status-filter").change(function ()
{
	var value = $(this).val();

	if (value != "all")
	{
		UpdateUriParam("filter", value);
	}
	else
	{
		RemoveUriParam("filter");
	}

	window.location.reload();
});

if (GetUriParam('include_disabled'))
{
	$("#show-disabled").prop('checked', true);
}

if (GetUriParam("filter"))
{
	$("#status-filter").val(GetUriParam("filter"));
}

// Merge products: opens the modal with the clicked row's product (data-product-id) preselected as the one to keep
$(".merge-products-button").on("click", function (e)
{
	var productId = $(e.currentTarget).attr("data-product-id");
	$("#merge-products-keep").val(productId);
	$("#merge-products-remove").val("");
	$("#merge-products-modal").modal("show");
});

// Merge submit: POSTs stock/products/{idToKeep}/merge/{idToRemove} and returns to the products list
$("#merge-products-save-button").on("click", function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("merge-products-form", true))
	{
		return;
	}

	var productIdToKeep = $("#merge-products-keep").val();
	var productIdToRemove = $("#merge-products-remove").val();

	Victual.Api.Post("stock/products/" + productIdToKeep.toString() + "/merge/" + productIdToRemove.toString(), {},
		function (result)
		{
			window.location.href = U('/products');
		},
		function (xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while merging', xhr.response);
		}
	);
});
