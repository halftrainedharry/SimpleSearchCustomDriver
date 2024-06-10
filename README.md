# SimpleSearchCustomDriver

Examples of custom drivers for the extra *SimpleSearch*.

## Custom Driver

This driver runs the search more efficiently than the default *SimpleSearch* driver, if TVs are used.

### Usage:

Set the system setting `simplesearch.driver_class` to `SimpleSearchCustomDriver\Driver\Custom`.

### Additional features:

* Fields from custom tables are available in the output. (The Placeholders have the class alias as the prefix: `[[+testpackageItem.title]]`.)
* Fields from custom tables can be used in the property `fieldPotency`. (Include the class alias as the prefix.)
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &customPackages=`Testpackage\Model\testpackageItem:title:::testpackageItem.resource = modResource.id`
    &fieldPotency=`content:10,sometv:5,testpackageItem.title:20`
]]
```

* The results can be sorted by TVs (that are listed in the property `includeTVList`) or fields from a custom table.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &includeTVList=`sometv,someothertv`
    &sortBy=`sometv`
    &sortDir=`desc`
]]
```

* Custom filters can be added with the property `where`.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &where=`{"template:IN":[1,2,3]}`
]]
```

* Set a maximun amount of results to be sorted in PHP using the `fieldPotency` property. This avoids the loading of a huge amount of data from the database, if someone searches for a very common search term.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &maxCountPhpSort=`200`
    &sortBy=``
    &fallbackSortBy=`publishedon`
    &sortDir=`desc`
]]
```

* For debugging purposes, the generated SQL query is set as a placeholder. The placeholder `[[+score]]` is added to the template to check the score calculation.
```
<pre>[[!+SimpleSearchCustomDriver.SQL]]</pre>

[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &debug=`1`
]]
```

### Different behavior

The property `` &includeTVs=`1` `` can't be used in this driver to search in all available TVs.
Only the TVs listed in the property `includeTVList` are used for the search and the sorting.

## PdoFetchDriver

This driver uses the class *pdoFetch* (from the extra *pdoTools*) to query the data.

### Usage:

Set the system setting `simplesearch.driver_class` to `SimpleSearchCustomDriver\Driver\PdoFetchDriver`.

### Additional features:

* Fields from custom tables are available in the output. (The Placeholders have no prefix: `[[+title]]`.)
* Fields from custom tables can be used in the property `fieldPotency`.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &customPackages=`Testpackage\Model\testpackageItem:title:::testpackageItem.resource = modResource.id`
    &fieldPotency=`content:10,sometv:5,title:20`
]]
```

* The results can be sorted by TVs (listed in the property `includeTVList`) or fields from a custom table.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &includeTVList=`sometv,someothertv`
    &sortBy=`sometv`
    &sortDir=`desc`
]]
```

* Custom filters can be added with the property `where`.
```
[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &where=`{"template:IN":[1,2,3]}`
]]
```

* Output debug information from *pdoFetch* (to check the generated SQL query).
```
<pre>[[!+pdoFetchLog]]</pre>

[[!SimpleSearchForm]]
<h2>Results</h2>
[[!SimpleSearch?
    ...
    &debug=`1`
]]
```

### Different behavior

The property `` &includeTVs=`1` `` can't be used in this driver to search in all available TVs.
Only the TVs listed in the property `includeTVList` are used for the search and the sorting.

Also, the default values of TVs are not taken into account for the search.