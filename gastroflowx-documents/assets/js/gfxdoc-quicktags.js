/* global QTags */
( function () {
	'use strict';

	if ( typeof QTags === 'undefined' ) {
		return;
	}

	QTags.addButton( 'gfxdoc_box_blue', 'box: niebieska', '[gfxbox color="blue"]', '[/gfxbox]' );
	QTags.addButton( 'gfxdoc_box_yellow', 'box: żółta', '[gfxbox color="yellow"]', '[/gfxbox]' );
	QTags.addButton( 'gfxdoc_box_green', 'box: zielona', '[gfxbox color="green"]', '[/gfxbox]' );
	QTags.addButton( 'gfxdoc_box_red', 'box: czerwona', '[gfxbox color="red"]', '[/gfxbox]' );
	QTags.addButton( 'gfxdoc_box_fill', 'box: do wypełnienia', '[gfxbox color="fill"]', '[/gfxbox]' );
	QTags.addButton( 'gfxdoc_badge', 'plakietka', '[gfxbadge]', '[/gfxbadge]' );
	QTags.addButton( 'gfxdoc_fillin', 'linia kropkowana', '[gfxfillin]', '' );
	QTags.addButton( 'gfxdoc_sigtable', 'tabela podpisów', '[gfxsigtable label1="Podpis Pracodawcy" label2="Podpis Pracownika"]', '' );
	QTags.addButton( 'gfxdoc_pagebreak', 'podział strony (PDF)', '[gfxpagebreak]', '' );
} )();
