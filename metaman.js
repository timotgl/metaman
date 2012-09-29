<!--/* Comments in here are formatted like this one because the content */-->
<!--/* of this file is inserted into HTML. */-->

<!--/* Print metadata suggestions */-->
function printSuggestions(request) {
	var suggestions = JSON.parse(request.responseText);
	document.getElementById("suggestions").innerHTML =
		formatCategorySuggestions(suggestions['categorySuggestions']) +
		formatPropertySuggestions(suggestions['propertySuggestions']);
	document.getElementById("debugInfo").innerHTML =
		suggestions['debugInfo'];
}



function getEmptyListPlaceholder() {
	return '<li class="emptyList">%s</li>';
}




function formatCategorySuggestions(categories) {
	var html = '';
	for (var i in categories) {
		var code = categories[i][0];
		if (code in wikiSourceReverse) {
			<!--/* check if category is aready present */-->
			<!--/* continue; */-->
		}
		var key = storeWikiSource(code);
		var category = categories[i][1];
		html += '<li class="shown" id="sourceItem' + key + '">' +
			'<a class="newItem" href="javascript:addSourceItem(' + key + ')">' +
			category + '</a></li>';
	}
	if (html == '') { <!--/* No suggestions present */-->
		html = getEmptyListPlaceholder();
	}
	return '<h4>%s</span>' +
		'</h4><ul>' + html + '</ul>';
}



function formatPropertySuggestions(properties) {
	var html = '';
	for (var i in properties) {
		var code = properties[i]['code'];
		if (code in wikiSourceReverse) {
			<!--/* check if property is aready present */-->
			<!--/* continue; */-->
		}
		var key = storeWikiSource(code);
		var propertyList = properties[i]['properties'];
		var value = properties[i]['value'];
		var innerHTML = '';
		for (j in propertyList) {
			var property = propertyList[j];
			innerHTML += '<span>' + property + '</span>';
			if (j < propertyList.length-1) {
				innerHTML += ', ';
			}
		}
		html += '<li class="shown" id="sourceItem' + key + '">' +
			'<a class="newItem" href="javascript:addSourceItem(' + key + ')">' +
			innerHTML + ' = ' + value + '</a></li>';
	}
	if (html == '') { <!--/* No suggestions present */-->
		html = getEmptyListPlaceholder();
	}
	return '<h4>%s</span>' +
		'</h4><ul>' + html + '</ul>';
}



<!--/* Parse wikitext for categories and return them */-->
function getCategories(wikitext) {
	var pattern = /\[\[((%s)|(%s)):([^:\]]+)\]\]/g;
	var categories = [];
	var match;
	while (match = pattern.exec(wikitext)) {
		categories.push([match[0], match[4]]);
	}
	return categories;
}


<!--/* Parse wikitext for template properties (parameters) and return them */-->
function getTemplateProperties(wikitext) {
	var outerPattern = /\{\{([^}]+)\}\}/g;
	var innerPattern = /\|([^=|}]+)=([^=|}]+)/g;
	var properties = [];
	var outerMatch;
	var innerMatch;
	while (outerMatch = outerPattern.exec(wikitext)) {
		var content = outerMatch[0];
		while (innerMatch = innerPattern.exec(content)) {
			properties.push([innerMatch[0], [innerMatch[1]], innerMatch[2]]);
		}
	}
	return properties;
}



<!--/* Parse wikitext for properties and return them */-->
function getProperties(wikitext) {
	var pattern = /\[\[(([^:\]]+)(::[^:\]]+)+)\]\]/g;
	var properties = [];
	var match;
	while (match = pattern.exec(wikitext)) {
		var original = match[0];
		var splitted = match[1].split('::');
		var last = splitted.length-1;
		var propertyList = splitted.slice(0, last);
		var value = splitted[last];
		properties.push([original, propertyList, value]);
	}
	var templProps = getTemplateProperties(wikitext);
	return properties.concat(templProps);
}



function createCategoryLink(source, category) {
	var key = storeWikiSource(source);
	return '<li id="sourceItem' + key + '" class="shown">' +
		'<a class="addedItem" href="javascript:removeSourceItem(' + key +
		')">' + category + '</a></li>';
}



<!--/* Create clickable links from the list of categories */-->
function getCategoryLinks(categoryList) {
	var html = '';
	for (var i in categoryList) {
		var wikitext = categoryList[i][0];
		var category = categoryList[i][1];
		html += createCategoryLink(wikitext, category);
	}
	if (html == '') { <!--/* No categories present */-->
		html = getEmptyListPlaceholder();
	}
	return '<ul id="categoryList">' + html + '</ul>';
}



<!-- /* Create clickable links from the list of properties */-->
function createPropertyLink(source, properties, value) {
	var key = storeWikiSource(source);
	var innerHTML = '';
	for (var j in properties) {
		property = properties[j];
		innerHTML += '<span>' + property + '</span>';
		if (j < properties.length-1) {
			innerHTML += ', ';
		}
	}
	return '<li id="sourceItem'+ key + '" class="shown">' +
		'<a class="addedItem" href="javascript:removeSourceItem('+ key +')">' +
		innerHTML + ' = ' + value + '</a></li>';
}



<!--/* Create clickable links from the list of properties */-->
function getPropertyLinks(propertyList) {
	var html = '';
	for (var i in propertyList) {
		var wikitext = propertyList[i][0];
		var properties = propertyList[i][1];
		var value = propertyList[i][2];
		html += createPropertyLink(wikitext, properties, value);
	}
	if (html == '') { <!--/* No properties present */-->
		html = getEmptyListPlaceholder();
	}
	return '<ul id="propertyList">' + html + '</ul>';
}



<!--/* Parse wikitext for categories and properties */-->
function parseWikitext() {
	var wikitext = document.editform.wpTextbox1.value;
	document.getElementById("categories").innerHTML =
		getCategoryLinks(getCategories(wikitext));
	document.getElementById("properties").innerHTML =
		getPropertyLinks(getProperties(wikitext));
}



function incSourceCounter() {
	sourceCounter += 1;
	return sourceCounter-1;
}



function getWikiSource(sourceID) {
	return wikiSource[sourceID];
}



function storeWikiSource(source) {
	var key = incSourceCounter();
	wikiSource[key] = source;
	wikiSourceReverse[source] = 1;
	return key;
}



function removeSourceItem(sourceID) {
	var textarea = document.editform.wpTextbox1.value;
	var sourceItem = getWikiSource(sourceID);
	document.editform.wpTextbox1.value = textarea.replace(sourceItem, "");
	document.getElementById("sourceItem" + sourceID).setAttribute(
		"class", "hidden");
}



function addSourceItem(sourceID) {
	document.editform.wpTextbox1.value += getWikiSource(sourceID) + '\n';
	document.getElementById("sourceItem" + sourceID).setAttribute(
		"class", "hidden");
	parseWikitext();
}



<!--/* Parse wikitext inside textarea, print clickable metadata links */-->
document.editform.wpTextbox1.onkeyup = function(evt) {
	parseWikitext();
}



<!--/* Store wiki code items */-->
var wikiSource = new Array();
var wikiSourceReverse = new Array();
var sourceCounter = 0; <!--/* Counter for associative array wikiSource */-->

<!--/* Trigger delayed parsing of wikitext (so DOM-tree completes first) */-->
setTimeout(parseWikitext, 500);

<!--/* Trigger delayed retrieval of suggestions */-->
setTimeout(
	'sajax_do_call("MetaMan::getSuggestions", [%s], printSuggestions)', 550);