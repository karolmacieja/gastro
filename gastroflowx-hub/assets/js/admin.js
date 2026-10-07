(function ($) {
	'use strict';

	$(document).on('click', '#gfx-logo-select', function (e) {
		e.preventDefault();
		var frame = wp.media({
			title: 'Wybierz logo restauracji',
			multiple: false,
			library: { type: 'image' },
			button: { text: 'Użyj tego logo' }
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			$('#gfx_logo_id').val(attachment.id);
			$('#gfx-logo-preview').attr('src', attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url).show();
			$('#gfx-logo-remove').show();
		});
		frame.open();
	});

	$(document).on('click', '#gfx-logo-remove', function (e) {
		e.preventDefault();
		$('#gfx_logo_id').val(0);
		$('#gfx-logo-preview').hide();
		$(this).hide();
	});

	// Wybór ikony powiadomienia push (Powiadomienia push → Szablony i ikony).
	// Jeden, wspólny handler dla wszystkich pickerów na stronie.
	$(document).on('click', '.gfx-icon-select', function (e) {
		e.preventDefault();
		var $picker = $(this).closest('.gfx-icon-picker');
		var frame = wp.media({
			title: 'Wybierz ikonę powiadomienia',
			multiple: false,
			library: { type: 'image' },
			button: { text: 'Użyj tej ikony' }
		});
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			if (a.mime === 'image/svg+xml') {
				window.alert('SVG nie jest obsługiwany jako ikona powiadomienia w większości przeglądarek. Wybierz PNG, JPG lub WebP.');
				return;
			}
			var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			$picker.find('.gfx-icon-id').val(a.id);
			$picker.find('.gfx-icon-preview').attr('src', url).css({ opacity: 1 }).show();
			$picker.find('.gfx-icon-remove').show();
		});
		frame.open();
	});

	$(document).on('click', '.gfx-icon-remove', function (e) {
		e.preventDefault();
		var $picker = $(this).closest('.gfx-icon-picker');
		var $img = $picker.find('.gfx-icon-preview');
		var fallback = $img.data('fallback');
		$picker.find('.gfx-icon-id').val(0);
		if (fallback) {
			$img.attr('src', fallback).css({ opacity: 0.45 }).show();
		} else {
			$img.hide();
		}
		$(this).hide();
	});
})(jQuery);
