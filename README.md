# SimpleSearchCustomDriver

An example of a custom driver for the extra *SimpleSearch*.

## Custom Driver

Set the system setting `simplesearch.driver_class` to `SimpleSearchCustomDriver\Driver\Custom`.

## PdoFetchDriver

Set the system setting `simplesearch.driver_class` to `SimpleSearchCustomDriver\Driver\PdoFetchDriver`.

This driver uses the class *pdoFetch* (from the extra *pdoTools*) to query the data.

### Additional features:

* Fields from custom tables are available in the output.
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