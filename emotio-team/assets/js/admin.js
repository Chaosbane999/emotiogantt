/**
 * Emotio Team — admin behaviour.
 * Hover-photo media picker on the edit screen; drag-and-drop row ordering
 * on the team list table.
 */
/* global jQuery, wp, etmAdmin */
(function ($) {
	'use strict';

	$(function () {
		/* ------------------------------------------ hover photo picker */
		var frame = null;

		$(document).on('click', '.etm-pick-hover', function (e) {
			e.preventDefault();
			var box = $(this).closest('.postbox');

			if (!frame) {
				frame = wp.media({
					title: box.find('.etm-pick-hover').text(),
					multiple: false,
					library: { type: 'image' }
				});
			}
			frame.off('select').on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
				box.find('.etm-hover-image-id').val(attachment.id);
				box.find('.etm-hover-image img').attr('src', url).show();
				box.find('.etm-remove-hover').show();
			});
			frame.open();
		});

		$(document).on('click', '.etm-remove-hover', function (e) {
			e.preventDefault();
			var box = $(this).closest('.postbox');
			box.find('.etm-hover-image-id').val('');
			box.find('.etm-hover-image img').hide();
			$(this).hide();
		});

		/* -------------------------------------- drag & drop reordering */
		if (!window.etmAdmin || !etmAdmin.sortable || !$.fn.sortable) {
			return;
		}

		var list = $('#the-list');
		if (!list.length) {
			return;
		}

		list.sortable({
			items: 'tr',
			handle: '.etm-drag-handle',
			axis: 'y',
			cursor: 'grabbing',
			placeholder: 'etm-sort-placeholder',
			helper: function (e, tr) {
				var helper = tr.clone();
				helper.children().each(function (i) {
					$(this).width(tr.children().eq(i).width());
				});
				return helper;
			},
			update: function () {
				var order = list.find('tr').map(function () {
					var id = ($(this).attr('id') || '').replace('post-', '');
					return id ? parseInt(id, 10) : null;
				}).get().filter(Boolean);

				list.find('tr').each(function (i) {
					$(this).find('.etm-order-value').text(i);
				});

				$.post(etmAdmin.ajaxUrl, {
					action: 'etm_save_order',
					nonce: etmAdmin.orderNonce,
					order: order,
					offset: 0
				});
			}
		});
	});
})(jQuery);
