jQuery(function() {
	if (JSINFO['approve']['prettyprint'] !== true) {
		return false;
	}
	
	//hide in print
	jQuery('#dokuwiki__header').addClass('approve-noprint');

	var $h1 = jQuery("#dokuwiki__content h1:first");
	if ($h1.length > 0) {
		var h1 = $h1.text();
		$h1.addClass('approve-noprint');
	} else {
		//if no header use page title
		var h1 = jQuery("#dokuwiki__header h1:first span").text();
	}
	
	var $img = jQuery("#dokuwiki__header img").clone();
	var $table = jQuery("<table>")
					.addClass("approve-print-only")
					.css({
						'table-layout': 'fixed',
						'border-collapse': 'collapse',
						'border': '0',//eliminate default border
						'width':'100%', 
						'margin-bottom': '10px'
	});

	var $tr = jQuery("<tr>").appendTo($table);

	var cells = [];

	cells.push(jQuery("<td>").append($img));

	let $print_header = jQuery('<h1>').text(h1);
	cells.push(jQuery("<td>").append($print_header));

	var lang = JSINFO['approve']['lang'];
	
	var status = JSINFO['approve']['status'];
	var date = JSINFO['approve']['date'];
	var author = JSINFO['approve']['author'];


	if (status === 'Approved') {
		var cont =	lang['approved']+'<br>'+author;
	} else {
		var cont =	lang['draft']+'<br>'+author;
	}


	cells.push(jQuery("<td>")
	.html('<p style="text-align:left">'+
		cont+'<br>'+
		date.replace(' ', '&nbsp;')+'<br>'+
		
	'</p>'));

	for (cell in cells) {
		var $td = cells[cell];
		$td.css({
			'border':'1px solid #000', 
			'border-top':'0',
			'text-align': 'center',
			'vertical-align': 'middle'
		});
		$tr.append($td);
	}
	
	cells[0].css('width', '25%');
	cells[1].css('width', '50%');
	cells[2].css('width', '25%');

	$tr.children().first().css('border-left', '0');
	$tr.children().last().css('border-right', '0');

	$table.prependTo("body");
});
