// Create namespace for this app.
window.metaman = {};

/**
 * Print metadata suggestions.
 */
metaman.printSuggestions(request) = function () {
    var suggestions = JSON.parse(request.responseText);
    document.getElementById("suggestions").innerHTML =
        metaman.formatCategorySuggestions(suggestions.categorySuggestions) +
        metaman.formatPropertySuggestions(suggestions.propertySuggestions);
    document.getElementById("debugInfo").innerHTML =
        suggestions.debugInfo;
};


/**
 *
 */
metaman.getEmptyListPlaceholder = function () {
    return '<li class="emptyList">%s</li>';
};


/**
 *
 */
metaman.formatCategorySuggestions = function (categories) {
    var html = '';
    for (var i in categories) {
        var code = categories[i][0];
        if (code in metaman.wikiSourceReverse) {
            // check if category is aready present
            // continue; // TODO: what was the point of this?
        }
        var key = metaman.storeWikiSource(code);
        var category = categories[i][1];
        html += '<li class="shown" id="sourceItem' + key + '">' +
            '<a class="newItem" href="javascript:addSourceItem(' + key + ')">' +
            category + '</a></li>';
    }
    
    // No suggestions present
    if (html === '') {
        html = metaman.getEmptyListPlaceholder();
    }
    return '<h4>%s</span>' +
        '</h4><ul>' + html + '</ul>';
};


/**
 *
 */
metaman.formatPropertySuggestions = function (properties) {
    var html = '',
        prop,
        code,
        value,
        key,
        propertyList,
        property,
        innerHTML;
    for (var i in properties) {
        prop = properties[i];
        code = prop.code;
        if (code in metaman.wikiSourceReverse) {
            // check if property is aready present
            // continue;
        }
        key = metaman.storeWikiSource(code);
        propertyList = prop.properties;
        value = prop.value;
        innerHTML = '';
        for (j in propertyList) {
            property = propertyList[j];
            innerHTML += '<span>' + property + '</span>';
            if (j < propertyList.length-1) {
                innerHTML += ', ';
            }
        }
        html += '<li class="shown" id="sourceItem' + key + '">' +
            '<a class="newItem" href="javascript:addSourceItem(' + key + ')">' +
            innerHTML + ' = ' + value + '</a></li>';
    }

    // No suggestions present
    if (html === '') {
        html = metaman.getEmptyListPlaceholder();
    }
    return '<h4>%s</span>' +
        '</h4><ul>' + html + '</ul>';
};


/**
 * Parse wikitext for categories and return them
 */
metaman.getCategories = function (wikitext) {
    var pattern = /\[\[((%s)|(%s)):([^:\]]+)\]\]/g,
        categories = [],
        match;
    while (match = pattern.exec(wikitext)) {
        categories.push([match[0], match[4]]);
    }
    return categories;
};


/**
 * Parse wikitext for template properties (parameters) and return them
 */
metaman.getTemplateProperties = function (wikitext) {
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
};


/**
 * Parse wikitext for properties and return them
 */
metaman.getProperties = function (wikitext) {
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
};


/**
 * TODO: comment method
 */
metaman.createCategoryLink = function (source, category) {
    var key = storeWikiSource(source);
    return '<li id="sourceItem' + key + '" class="shown">' +
        '<a class="addedItem" href="javascript:removeSourceItem(' + key +
        ')">' + category + '</a></li>';
};


/**
 * Create clickable links from the list of categories
 */
metaman.getCategoryLinks = function (categoryList) {
    var html = '';
    for (var i in categoryList) {
        var wikitext = categoryList[i][0];
        var category = categoryList[i][1];
        html += createCategoryLink(wikitext, category);
    }

    // No categories present
    if (html === '') {
        html = getEmptyListPlaceholder();
    }
    return '<ul id="categoryList">' + html + '</ul>';
};


/**
 * Create clickable links from the list of properties
 */
metaman.createPropertyLink = function (source, properties, value) {
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
};


/**
 * Create clickable links from the list of properties
 */
metaman.getPropertyLinks = function (propertyList) {
    var html = '';
    for (var i in propertyList) {
        var wikitext = propertyList[i][0];
        var properties = propertyList[i][1];
        var value = propertyList[i][2];
        html += createPropertyLink(wikitext, properties, value);
    }
    
    // No properties present
    if (html === '') {
        html = getEmptyListPlaceholder();
    }
    return '<ul id="propertyList">' + html + '</ul>';
};


/**
 * Parse wikitext for categories and properties
 */
metaman.parseWikitext = function () {
    var wikitext = document.editform.wpTextbox1.value;
    document.getElementById("categories").innerHTML =
        getCategoryLinks(getCategories(wikitext));
    document.getElementById("properties").innerHTML =
        getPropertyLinks(getProperties(wikitext));
};


/**
 * TODO: comment method
 */
metaman.incSourceCounter = function () {
    sourceCounter += 1;
    return sourceCounter-1;
};


/**
 * TODO: comment method
 */
metaman.getWikiSource = function (sourceID) {
    return wikiSource[sourceID];
};


/**
 * TODO: comment method
 */
metaman.storeWikiSource = function (source) {
    var key = incSourceCounter();
    wikiSource[key] = source;
    wikiSourceReverse[source] = 1;
    return key;
};


/**
 * TODO: comment method
 */
metaman.removeSourceItem = function (sourceID) {
    var textarea = document.editform.wpTextbox1.value;
    var sourceItem = getWikiSource(sourceID);
    document.editform.wpTextbox1.value = textarea.replace(sourceItem, "");
    document.getElementById("sourceItem" + sourceID).setAttribute(
        "class", "hidden");
};


/**
 * TODO: comment method
 */
metaman.addSourceItem = function (sourceID) {
    document.editform.wpTextbox1.value += getWikiSource(sourceID) + '\n';
    document.getElementById("sourceItem" + sourceID).setAttribute(
        "class", "hidden");
    parseWikitext();
};


/**
 * Parse wikitext inside textarea, print clickable metadata links
 */
document.editform.wpTextbox1.onkeyup = function(evt) {
    parseWikitext();
};



// Store wiki code items
var wikiSource = [],
    wikiSourceReverse = [],

    // Counter for associative array wikiSource
    sourceCounter = 0;

// Trigger delayed parsing of wikitext (so DOM-tree completes first)
setTimeout(parseWikitext, 500);

// Trigger delayed retrieval of suggestions
setTimeout('sajax_do_call("MetaMan::getSuggestions", [%s], metaman.printSuggestions)', 550);