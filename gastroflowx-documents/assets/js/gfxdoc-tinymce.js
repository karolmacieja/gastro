/* global tinymce */
( function () {
	'use strict';

	tinymce.PluginManager.add( 'gfxdoc_shortcodes', function ( editor ) {
		editor.addButton( 'gfxdoc_insert', {
			type: 'menubutton',
			text: 'GastroFlowx',
			icon: false,
			menu: [
				{
					text: 'Ramka informacyjna (niebieska)',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="blue"]', '[/gfxbox]', 'Treść ramki…' );
					},
				},
				{
					text: 'Ramka ostrzegawcza (żółta)',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="yellow"]', '[/gfxbox]', 'Treść ramki…' );
					},
				},
				{
					text: 'Ramka (zielona)',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="green"]', '[/gfxbox]', 'Treść ramki…' );
					},
				},
				{
					text: 'Ramka (czerwona)',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="red"]', '[/gfxbox]', 'Treść ramki…' );
					},
				},
				{
					text: 'Ramka „do wypełnienia” (przerywana)',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="fill"]', '[/gfxbox]', 'Treść ramki…' );
					},
				},
				{
					text: 'Ramka „strona umowy”',
					onclick: function () {
						wrapSelection( editor, '[gfxbox color="party"]', '[/gfxbox]', '<h3>Nazwa strony</h3>' );
					},
				},
				{ text: '-' },
				{
					text: 'Plakietka (np. rola)',
					onclick: function () {
						wrapSelection( editor, '[gfxbadge]', '[/gfxbadge]', 'tekst' );
					},
				},
				{
					text: 'Linia do wypełnienia (kropkowana)',
					onclick: function () {
						editor.insertContent( '[gfxfillin]' );
					},
				},
				{ text: '-' },
				{
					text: 'Tabela pól (dane do uzupełnienia)',
					onclick: function () {
						var snippet =
							'[gfxfieldtable]<br>' +
							'[gfxfield label="Imię i nazwisko"][/gfxfield]<br>' +
							'[gfxfield label="Adres"][/gfxfield]<br>' +
							'[/gfxfieldtable]';
						editor.insertContent( snippet );
					},
				},
				{
					text: 'Tabela na podpisy (2 kolumny)',
					onclick: function () {
						editor.insertContent( '[gfxsigtable label1="Podpis Pracodawcy" label2="Podpis Pracownika"]' );
					},
				},
				{ text: '-' },
				{
					text: 'Notatka na dole strony (wyszarzona)',
					onclick: function () {
						wrapSelection( editor, '[gfxnote]', '[/gfxnote]', 'Tekst zastrzeżenia…' );
					},
				},
				{
					text: 'Podział strony (tylko w PDF)',
					onclick: function () {
						editor.insertContent( '[gfxpagebreak]' );
					},
				},
			],
		} );
	} );

	/**
	 * Wraps the current selection with opening/closing shortcode tags, or —
	 * if nothing is selected — inserts the tags around a placeholder so the
	 * editor immediately sees where to type.
	 */
	function wrapSelection( editor, open, close, placeholder ) {
		var selected = editor.selection.getContent( { format: 'html' } );
		var inner = selected && selected.length ? selected : placeholder;
		editor.insertContent( open + inner + close );
	}
} )();
