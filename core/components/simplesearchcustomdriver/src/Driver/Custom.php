<?php
namespace SimpleSearchCustomDriver\Driver;

use PDO;
use SimpleSearch\Driver\SimpleSearchDriver;
use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modTemplateVarResource;
use xPDO\Om\xPDOQuery;

/**
 * Custom sql-based search driver for SimpleSearch
 *
 * @package simplesearch
 */
class Custom extends SimpleSearchDriver
{

    public function initialize()
    {
    }

    public function index(array $fields): bool
    {
        return true;
    }

    public function removeIndex($id): bool
    {
        return true;
    }

    /**
     * @param string $searchString
     * @param array $scriptProperties
     * @return array
     */
    public function search($searchString, array $scriptProperties = []) {

        if (!empty($searchString)) {
            $searchString = strip_tags($this->modx->sanitizeString($searchString));
        }

        $andTerms = $this->modx->getOption('andTerms', $scriptProperties, true);
        $useAllWords = $this->modx->getOption('useAllWords', $scriptProperties, false);
        $maxWords = $this->modx->getOption('maxWords', $scriptProperties, 7);
        $matchWildcard = $this->modx->getOption('matchWildcard', $scriptProperties, true);

        $ids = $this->modx->getOption('ids', $scriptProperties, '');
        $exclude = $this->modx->getOption('exclude', $scriptProperties, '');
        $hideMenu = (int) $this->modx->getOption('hideMenu', $scriptProperties, 2);
        $customWhere = trim($this->modx->getOption('where', $scriptProperties, ''));

        $docFields = array_map('trim', explode(',', $this->modx->getOption('docFields', $scriptProperties, 'pagetitle,longtitle,alias,description,introtext,content')));
        $docFields = array_unique(array_filter($docFields)); //remove empty elements
        $includeTVList = $this->modx->getOption('includeTVList', $scriptProperties, ''); // Only TVs listed here are used for the search and the sorting. (TV default values are not taken into account for the search.)
        $includeTVList = array_map('trim',explode(',', $includeTVList));
        $includeTVList = array_unique(array_filter($includeTVList));
        $includeTVs = $this->modx->getOption('includeTVs', $scriptProperties, false); // Add all TVs to the output (raw values)
        $processTVs = $this->modx->getOption('processTVs', $scriptProperties, ''); // Add all processed TVs to the output
        $tvPrefix = trim($this->modx->getOption('tvPrefix', $scriptProperties, ''));

        $isDebug = $this->modx->getOption('debug', $scriptProperties, false);

        $c = $this->modx->newQuery(modResource::class);
        $c->select($this->modx->getSelectColumns(modResource::class, 'modResource')); // Select all fields from modResource

        $includedTVAliases = [];
        if (count($includeTVList) > 0) {
            $q = $this->modx->newQuery(modTemplateVar::class, ['name:IN' => $includeTVList]);
            $q->leftJoin(modTemplateVarTemplate::class, 'TemplateVarTemplates');
            $q->select('id,name,type,default_text');
            $q->select('GROUP_CONCAT(templateid) AS templateids');
            $q->groupBy('id,name,type,default_text');
            if ($q->prepare() && $q->stmt->execute()) {
                while ($tv = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $templateIds = $tv['templateids'];
                    $tvAlias = 'TV' . strtolower($tv['name']);
                    $outputName = $tvPrefix . $tv['name'];
                    $includedTVAliases[] = $tvAlias;
                    $c->leftJoin(modTemplateVarResource::class, $tvAlias, "`{$tvAlias}`.`contentid` = `modResource`.`id` AND `{$tvAlias}`.`tmplvarid` = {$tv['id']}");
                    if ($tv['default_text']){
                        $c->select("CASE WHEN `modResource`.`template` IN ({$templateIds}) THEN IFNULL(`{$tvAlias}`.`value`, {$this->modx->quote($tv['default_text'])}) ELSE '' END AS `{$outputName}`");
                    } else {
                        $c->select("IFNULL(`{$tvAlias}`.`value`, '') AS `{$outputName}`");
                    }
                }
            }
        }

        // If using customPackages, add here
        $customPackages = [];
        if (!empty($scriptProperties['customPackages'])) {
            $packages = array_map('trim', explode('||', $scriptProperties['customPackages']));
            if (is_array($packages) && !empty($packages)) {
                $searchArray = [
                    '{core_path}',
                    '{assets_path}',
                    '{base_path}',
                ];

                $replacePaths = [
                    $this->modx->getOption('core_path', null, MODX_CORE_PATH),
                    $this->modx->getOption('assets_path', null, MODX_ASSETS_PATH),
                    $this->modx->getOption('base_path', null, MODX_BASE_PATH),
                ];
                $customFields = [];
                foreach ($packages as $package) {
                    // 0: class name, 1: field name(s) (csl), 2: package name, 3: package path, 4: criteria
                    $package = array_map('trim', explode(':', $package));
                    if (!empty($package[4])) {
                        $package[3] = str_replace($searchArray, $replacePaths, $package[3]);

                        $className = $package[0];
                        $classAlias = $this->modx->getAlias($className);

                        if (!class_exists($className)){
                            $this->modx->addPackage($package[2], $package[3]);
                        }

                        $c->leftJoin($className, $classAlias, $package[4]);

                        $fields = array_map('trim', explode(',', $package[1]));
                        $fields = array_unique(array_filter($fields));
                        $c->select($this->modx->getSelectColumns($className, $classAlias, $classAlias . '.', $fields));

                        $fields = array_map(function ($fieldName) use ($classAlias) { return $classAlias . '.' . $fieldName; }, $fields);
                        $package[1] = $fields;

                        $customPackages[] = $package;
                        $customFields = array_merge($customFields, $fields);
                    }
                }
                $scriptProperties['customFields'] = array_unique($customFields); // For the sorting
            }
        }

        // Process conditional clauses
        $wildcard = $matchWildcard ? '%' : '';
        $whereArray = [];
        $i = 1;

        $searchTerms = [ $searchString ];
        if (empty($useAllWords)) {
            $searchTerms = $this->search->searchArray;
        }

        foreach ($searchTerms as $term) {
            if ($i > $maxWords) {
                break;
            }

            $whereArrayKeys = [];
            $whereArrayValues = [];
            $term = $wildcard . $term . $wildcard;

            foreach ($docFields as $field) {
                $whereArrayKeys[] = 'OR:' . $field . ':LIKE';
                $whereArrayValues[] = $term;
            }

            // TVs
            if (count($includedTVAliases) > 0) {
                foreach ($includedTVAliases as $tvAlias) {
                    $whereArrayKeys[] = "OR:`{$tvAlias}`.`value`:LIKE";
                    $whereArrayValues[] = $term;
                }
            }

            // custom packages
            if (is_array($customPackages) && !empty($customPackages)) {
                foreach ($customPackages as $package) {
                    $fields = $package[1];
                    foreach ($fields as $field) {
                        $whereArrayKeys[] = 'OR:' . $field . ':LIKE';
                        $whereArrayValues[] = $term;
                    }
                }
            }

            if (count($whereArrayKeys) > 0) {
                if ($andTerms) {
                    $whereArrayKeys[0] = preg_replace('/^OR:/', '', $whereArrayKeys[0]);
                }
                $whereArray[] = array_combine($whereArrayKeys, $whereArrayValues);
            }

            $i++;
        }

        $c->where($whereArray);

        if (!empty($ids)) {
            $idType = $this->modx->getOption('idType', $this->config, 'parents');
            $depth = $this->modx->getOption('depth', $this->config, 10);
            $ids = $this->processIds($ids, $idType, $depth);
            if (!empty($exclude)) {
                $exclude = $this->cleanIds($exclude);
                $ids = array_diff($ids, explode(',', $exclude)); // remove excluded ids from the 'IN' array
            }

            $f = $this->modx->getSelectColumns(modResource::class, 'modResource', '', ['id']);
            $c->where(["$f:IN" => $ids]);
        } elseif (!empty($exclude)) {
            $exclude = $this->cleanIds($exclude);
            $f = $this->modx->getSelectColumns(modResource::class, 'modResource', '', ['id']);
            $c->where(["{$f}:NOT IN" => explode(',', $exclude)]);
        }

        $c->where(['published:=' => 1]);
        $c->where(['searchable:=' => 1]);
        $c->where(['deleted:=' => 0]);

        // Restrict to either this context or specified contexts
        $ctx = !empty($this->config['contexts']) ? $this->config['contexts'] : $this->modx->context->get('key');
        $f = $this->modx->getSelectColumns(modResource::class, 'modResource','', ['context_key']);
        $c->where(["$f:IN" => explode(',', $ctx)]);

        if ($hideMenu !== 2) {
            $c->where(['hidemenu' => $hideMenu === 1]);
        }

        // Add custom where conditions
        if (!empty($customWhere)) {
            if (is_string($customWhere) && ($customWhere[0] === '{' || $customWhere[0] === '[')) {
                $customWhere = json_decode($customWhere, true);
            }
            if (!is_array($customWhere)) {
                $customWhere = [$customWhere];
            }
            $c->where($customWhere);
        }

        $total = $this->modx->getCount(modResource::class, $c);

        // Set limit
        $perPage = (int) $this->modx->getOption('perPage', $this->config, 10);
        $offset = $this->modx->getOption('start', $this->config, 0);
        $offsetIndex = $this->modx->getOption('offsetIndex', $this->config, 'simplesearch_offset');
        if (isset($_REQUEST[$offsetIndex])) {
            $offset = (int) $_REQUEST[$offsetIndex];
        }

        $c->query['distinct'] = 'DISTINCT';

        $sortBy = $this->modx->getOption('sortBy', $scriptProperties, '');
        $maxCountPhpSort = (int) $this->modx->getOption('maxCountPhpSort', $scriptProperties, 0);
        if ($maxCountPhpSort && $total > $maxCountPhpSort){
            // Too many results to sort in PHP -> Use limit in SQL-Query
            $fallbackSortBy = $this->modx->getOption('fallbackSortBy', $scriptProperties, '');
            if ($fallbackSortBy) {
                $sortBy = $fallbackSortBy;
            } else {
                $sortBy = 'id';
            }
        }

        if (!empty($sortBy)) {
            // sort and limit resources with SQL
            $sortDir = $this->modx->getOption('sortDir', $scriptProperties, 'DESC');
            $sortDirs = array_map('trim', explode(',', $sortDir));
            $sortBys = array_map('trim', explode(',', $sortBy));
            $dir = 'desc';
            $resourceColumns = $this->modx->getFields(modResource::class);
            for ($i = 0, $iMax = count($sortBys); $i < $iMax; $i++) {
                $by = $sortBys[$i];
                if (isset($sortDirs[$i])) {
                    $dir = $sortDirs[$i];
                }

                if (array_key_exists($by, $resourceColumns)) {
                    $c->sortby("`modResource`.`{$by}`", strtoupper($dir));
                } elseif (in_array($by, $includeTVList)) {
                    $tvName = strtolower($by);
                    $c->sortby("`TV{$tvName}`.`value`", strtoupper($dir));
                } else {
                    $c->sortby($by, strtoupper($dir));
                }
            }
            if ($perPage > 0) {
                $c->limit($perPage, $offset);
            }
        } else {
            // Just to be sure that the order is always the same for every page
            $c->sortby('`modResource`.`id`', 'ASC');
        }

        $c->prepare();

        // For debugging purposes
        if ($isDebug){
            $sql = $c->ToSql();
            $this->modx->setPlaceholder('SimpleSearchCustomDriver.SQL', $sql, true);
        }

        // Execute query
        $resources = [];
        if ($c->stmt->execute()) {
            if (!$resources = $c->stmt->fetchAll(PDO::FETCH_ASSOC)) {
                $resources = [];
            }
        } else {
            $errors = $c->stmt->errorInfo();
            $errMsg = 'Could not process query, error #' . $errors[0] . ': ' . $errors[1] . ': ' . $errors[2];
            $this->modx->log(\modX::LOG_LEVEL_ERROR, $errMsg);
        }

        if (empty($sortBy)) {
            // sort and limit resources in PHP
            $resources = $this->sortResults($resources, $scriptProperties);
            if ($perPage > 0) {
                $resources = array_slice($resources, $offset, $perPage);
            }
        }

        $list = [];

        foreach ($resources as $resource) {
            // check 'list' permission
            $object = $this->modx->newObject(modResource::class);
            $object->_fields['id'] = $resource['id'];
            $object->_fields['template'] = $resource['template'];
            $object->_new = false; // Otherwise $object->getMany('TemplateVars') doesn't contain the values from the DB
            if ($object instanceof modAccessibleObject && !$object->checkPolicy('list')) {
                continue;
            }

            // process TVs
            if (!empty($includeTVs) || !empty($processTVs)) {
                $templateVars =& $object->getMany('TemplateVars');
                // @var modTemplateVar $templateVar
                foreach ($templateVars as $tvId => $templateVar) {
                    $resource[$tvPrefix . $templateVar->get('name')] = !empty($processTVs) ? $templateVar->renderOutput($resource['id']) : $templateVar->get('value');
                }
            }

            $list[] = $resource;
        }

        return [
            'total' => $total,
            'results' => $list,
        ];
    }

    /**
     * Scores and sorts the results based on 'fieldPotency'
     *
     * @param array $resources
     * @param array $scriptProperties The $scriptProperties array
     * @return array Scored and sorted search results
     */
    protected function sortResults(array $resources, array $scriptProperties) {
        $searchStyle = $this->modx->getOption('searchStyle', $scriptProperties, 'partial');
        $docFields = array_map('trim', explode(',', $this->modx->getOption('docFields', $scriptProperties, 'pagetitle,longtitle,alias,description,introtext,content')));
        $docFields = array_filter($docFields);
        $includeTVList = array_map('trim', explode(',', $this->modx->getOption('includeTVList', $scriptProperties, '')));
        $includeTVList = array_filter($includeTVList);
        $customFields = $scriptProperties['customFields'] ?? [];
        $tvPrefix = $this->modx->getOption('tvPrefix', $scriptProperties, '');
        $isDebug = $this->modx->getOption('debug', $scriptProperties, false);
        $potentyFields = [];
        if ($tvPrefix) {
            $potentyFields = array_merge($docFields, array_map(function ($name) use ($tvPrefix) { return $tvPrefix . $name; }, $includeTVList), $customFields);
        } else {
            $potentyFields = array_merge($docFields, $includeTVList, $customFields);
        }
        $potentyFields = array_unique($potentyFields);
        if (count($potentyFields) == 0){
            return $resources;
        }

        $fieldPotency = array_map('trim', explode(',', $this->modx->getOption('fieldPotency', $scriptProperties, '')));
        foreach ($fieldPotency as $key => $field) {
            unset($fieldPotency[$key]);
            $arr = array_map('trim', explode(':', $field));
            if (!empty($arr[1])) {
                if ($tvPrefix && in_array($arr[0], $includeTVList)) {
                    $arr[0] = $tvPrefix . $arr[0];
                }
                $fieldPotency[$arr[0]] = (int) $arr[1];
            }
        }

        // Calculate Scores
        foreach ($resources as $idx => &$resource) {
            foreach ($potentyFields as $field) {
                $potency = (array_key_exists($field, $fieldPotency)) ? (int) $fieldPotency[$field] : 1;
                foreach ($this->search->searchArray as $term) {
                    $queryTerm = preg_quote($term,'/');
                    $regex = ($searchStyle === 'partial') ? "/{$queryTerm}/i" : "/\b{$queryTerm}\b/i";
                    $numberOfMatches = 0;
                    if (array_key_exists($field, $resource)) {
                        $numberOfMatches = preg_match_all($regex, $resource[$field], $matches);
                    }

                    if (empty($this->searchScores[$idx])) {
                        $this->searchScores[$idx] = 0;
                    }

                    $this->searchScores[$idx] += $numberOfMatches * $potency;
                }
            }
            if ($isDebug){
                $resource['score'] = $this->searchScores[$idx]; // Add score to result
            }
        }
        unset($resource);

        // Sort
        arsort($this->searchScores);

        $list = array();
        foreach ($this->searchScores as $idx => $score) {
            $list[] = $resources[$idx];
        }

        return $list;
    }

}
